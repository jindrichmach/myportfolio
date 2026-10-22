<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\AccountRepository;
use MyPortfolio\Repository\ImportBatchRepository;
use MyPortfolio\Repository\ImportPreviewRepository;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\PortfolioRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Service\Import\Parser\ImportParserInterface;
use MyPortfolio\Service\Import\Reader\TabularReader;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Portfolio\TransactionService;
use MyPortfolio\Support\Decimal;

/**
 * Import z exportu burzy / brokera:
 *   preview()  — rozparsuje soubor, uloží ho do import_previews a vrátí náhled
 *   view()     — náhled k uloženému tokenu (párování instrumentů, duplicity, převody k rozhodnutí)
 *   confirm()  — zapíše transakce podle rozhodnutí uživatele jako jednu dávku (vše nebo nic)
 *   revert()   — vrátí dávku
 */
final class CsvImportService
{
    private const SECURITY_CLASSES = ['etf', 'stock', 'fund'];
    private const MAX_PREVIEW_ROWS = 2000;

    public function __construct(
        private readonly Connection $db,
        private readonly TabularReader $reader,
        private readonly ImportParsers $parsers,
        private readonly ImportPreviewRepository $previews,
        private readonly ImportBatchRepository $batches,
        private readonly InstrumentRepository $instruments,
        private readonly PositionRepository $positions,
        private readonly AccountRepository $accounts,
        private readonly PortfolioRepository $portfolios,
        private readonly TransactionRepository $transactions,
        private readonly TransactionService $transactionService,
    ) {}

    /** @return array<string,mixed> náhled */
    public function preview(int $userId, string $bytes, string $filename, int $accountId, int $portfolioId, ?string $sourceKey): array
    {
        $this->accounts->find($userId, $accountId) ?? throw DomainError::validation(['account_id' => ['Účet nenalezen.']]);
        $this->portfolios->find($userId, $portfolioId) ?? throw DomainError::validation(['portfolio_id' => ['Portfolio nenalezeno.']]);
        try {
            $tables = $this->reader->read($bytes, $filename);
        } catch (\InvalidArgumentException $e) {
            throw DomainError::validation(['file' => [$e->getMessage()]]);
        }
        $parser = $sourceKey !== null && $sourceKey !== ''
            ? ($this->parsers->get($sourceKey) ?? throw DomainError::validation(['source' => ['Neznámý zdroj importu.']]))
            : ($this->parsers->detect($tables) ?? throw DomainError::validation(['file' => ['Formát souboru se nepodařilo rozpoznat — vyber zdroj ručně.']]));
        try {
            $drafts = $parser->parse($tables);
        } catch (\InvalidArgumentException $e) {
            throw DomainError::validation(['file' => [$parser->label() . ': ' . $e->getMessage()]]);
        }
        if ($drafts === []) {
            throw DomainError::validation(['file' => ['V souboru nejsou žádné pohyby.']]);
        }
        if (count($drafts) > self::MAX_PREVIEW_ROWS * 5) {
            throw DomainError::validation(['file' => ['Soubor má příliš mnoho řádků — rozděl export na kratší období.']]);
        }
        return $this->view($userId, $this->store($userId, $parser->key(), $parser->label(), $filename, $accountId, $portfolioId, $drafts));
    }

    /**
     * Náhled z hotových řádků (synchronizace přes API burzy). `$sourceKey` s dvojtečkou (např. `api:kraken`)
     * se použije jako zdroj idempotence přímo, jinak `csv:<key>`.
     *
     * @param list<Draft> $drafts
     */
    public function previewDrafts(int $userId, string $sourceKey, string $label, string $filename, int $accountId, int $portfolioId, array $drafts): string
    {
        return $this->store($userId, $sourceKey, $label, $filename, $accountId, $portfolioId, $drafts);
    }

    /** @param list<Draft> $drafts */
    private function store(int $userId, string $sourceKey, string $label, string $filename, int $accountId, int $portfolioId, array $drafts): string
    {
        usort($drafts, static fn (Draft $a, Draft $b) => [$a->executedAt, $a->line] <=> [$b->executedAt, $b->line]);
        $token = bin2hex(random_bytes(16));
        $this->previews->save($userId, $token, $sourceKey, $filename, $accountId, $portfolioId,
            ['label' => $label, 'drafts' => array_map(static fn (Draft $d) => $d->toArray(), $drafts)]);
        return $token;
    }

    /** @return array<string,mixed> */
    public function view(int $userId, string $token): array
    {
        $p = $this->previews->find($userId, $token) ?? throw DomainError::notFound('Náhled importu vypršel nebo neexistuje — nahraj soubor znovu.');
        $parser = $this->parsers->get($p['source']);
        $drafts = array_map([Draft::class, 'fromArray'], $p['payload']['drafts']);
        $source = self::source($p['source']);
        $existing = array_flip($this->transactions->existingExternalIds($userId, $source,
            array_values(array_filter(array_map(static fn (Draft $d) => $d->externalId, $drafts)))));

        $assets = [];
        $rows = [];
        $transfers = [];
        $summary = ['rows' => count($drafts), 'new' => 0, 'duplicate' => 0, 'skipped' => 0, 'transfers' => 0];
        foreach ($drafts as $i => $d) {
            $status = $d->kind === 'skip' ? 'skip' : (isset($existing[$d->externalId]) ? 'duplicate' : 'new');
            $summary[$status === 'new' ? 'new' : ($status === 'skip' ? 'skipped' : 'duplicate')]++;
            if ($d->kind !== 'skip') {
                $key = self::assetKey($d);
                $assets[$key] ??= ['key' => $key, 'symbol' => $d->symbol, 'isin' => $d->isin, 'name' => $d->name,
                    'asset_class' => $d->assetClass, 'currency' => $d->quoteCurrency ?? $d->currency, 'rows' => 0, 'new_rows' => 0];
                $assets[$key]['rows']++;
                $assets[$key]['name'] ??= $d->name;
                $assets[$key]['currency'] ??= $d->quoteCurrency ?? $d->currency;
                if ($status === 'new') {
                    $assets[$key]['new_rows']++;
                }
                if (in_array($d->kind, ['send', 'receive'], true) && $status === 'new') {
                    $summary['transfers']++;
                    $transfers[] = ['index' => $i, 'kind' => $d->kind, 'executed_at' => $d->executedAt, 'symbol' => $d->symbol,
                        'quantity' => $d->quantity, 'asset_key' => $key, 'note' => $d->note];
                }
            }
            if (count($rows) < self::MAX_PREVIEW_ROWS) {
                $rows[] = ['index' => $i, 'line' => $d->line, 'kind' => $d->kind, 'executed_at' => $d->executedAt, 'symbol' => $d->symbol,
                    'quantity' => $d->quantity, 'unit_price' => $d->unitPrice, 'amount' => $d->amount, 'currency' => $d->currency,
                    'fee' => $d->fee, 'fee_currency' => $d->feeCurrency, 'status' => $status, 'reason' => $d->skipReason, 'note' => $d->note];
            }
        }

        $ownAccount = $p['account_id'];
        $marketPositions = array_values(array_filter($this->positions->list($userId), static fn (array $x) => $x['valuation_mode'] === 'market'));
        foreach ($assets as &$a) {
            $candidates = $this->instruments->match($a['isin'], $a['symbol'], $a['asset_class'] === 'crypto' ? ['crypto'] : self::SECURITY_CLASSES);
            $a['candidates'] = array_map(static fn (array $c) => ['id' => $c['id'], 'symbol' => $c['symbol'], 'name' => $c['name'],
                'asset_class' => $c['asset_class'], 'quote_currency' => $c['quote_currency'], 'isin' => $c['isin']], $candidates);
            $a['instrument_id'] = self::pick($candidates, $a['currency'])['id'] ?? null;
            $a['positions'] = array_values(array_map(static fn (array $x) => ['id' => $x['id'],
                'label' => $x['institution_name'] . ' · ' . $x['account_name'] . ' · ' . $x['portfolio_name']],
                array_filter($marketPositions, static fn (array $x) => $a['instrument_id'] !== null
                    && $x['instrument_id'] === $a['instrument_id'] && $x['account_id'] !== $ownAccount)));
        }
        unset($a);

        $account = $this->accounts->find($userId, $ownAccount);
        $portfolio = $this->portfolios->find($userId, $p['portfolio_id']);
        return [
            'token'     => $token,
            'source'    => ['key' => $p['source'], 'label' => $p['payload']['label'] ?? $parser?->label() ?? $p['source']],
            'filename'  => $p['filename'],
            'account'   => ['id' => $ownAccount, 'name' => ($account['institution_name'] ?? '') . ' · ' . ($account['name'] ?? '')],
            'portfolio' => ['id' => $p['portfolio_id'], 'name' => $portfolio['name'] ?? ''],
            'accounts'  => array_map(static fn (array $x) => ['id' => $x['id'], 'name' => $x['institution_name'] . ' · ' . $x['name']],
                array_values(array_filter($this->accounts->list($userId, false), static fn (array $x) => $x['id'] !== $ownAccount))),
            'summary'   => $summary,
            'assets'    => array_values($assets),
            'transfers' => $transfers,
            'rows'      => $rows,
        ];
    }

    /**
     * @param array{assets?:array<string,array<string,mixed>>,transfers?:array<int|string,?array<string,mixed>>} $decisions
     *        assets[key] = {instrument_id} | {create:{name,quote_currency,asset_class?}} | {skip:true}
     *        transfers[index] = {position_id} | {account_id} | null (vynechat)
     * @return array{batch_id:?int,imported:int,transfers:int,skipped:int}
     */
    public function confirm(int $userId, string $token, array $decisions): array
    {
        $p = $this->previews->find($userId, $token) ?? throw DomainError::notFound('Náhled importu vypršel nebo neexistuje — nahraj soubor znovu.');
        $view = $this->view($userId, $token);
        $accountCurrency = (string) ($this->accounts->find($userId, $p['account_id'])['currency'] ?? 'CZK');
        $drafts = array_map([Draft::class, 'fromArray'], $p['payload']['drafts']);
        $source = self::source($p['source']);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $instrumentIds = $this->resolveInstruments($userId, $view['assets'], $decisions['assets'] ?? []);
            $batchId = $this->batches->create($userId, $source, $p['filename']);
            $existing = array_flip($this->transactions->existingExternalIds($userId, $source,
                array_values(array_filter(array_map(static fn (Draft $d) => $d->externalId, $drafts)))));

            $byPosition = [];
            $transfers = [];
            $skipped = 0;
            foreach ($drafts as $i => $d) {
                $instrumentId = $d->kind === 'skip' ? null : ($instrumentIds[self::assetKey($d)] ?? null);
                if ($d->kind === 'skip' || $instrumentId === null || isset($existing[$d->externalId])) {
                    $skipped++;
                    continue;
                }
                $own = $this->position($userId, $p['portfolio_id'], $p['account_id'], $instrumentId);
                if (in_array($d->kind, ['send', 'receive'], true)) {
                    $target = $decisions['transfers'][$i] ?? $decisions['transfers'][(string) $i] ?? null;
                    if (!is_array($target)) {
                        $skipped++;
                        continue;
                    }
                    $other = isset($target['position_id'])
                        ? $this->otherPosition($userId, (int) $target['position_id'], $instrumentId)
                        : $this->position($userId, $p['portfolio_id'], (int) ($target['account_id'] ?? 0), $instrumentId);
                    $transfers[] = [$d, $own, $other];
                    continue;
                }
                $byPosition[$own][] = $this->input($d, $accountCurrency);
            }

            $imported = 0;
            foreach ($byPosition as $positionId => $rows) {
                $imported += $this->transactionService->import($userId, $positionId, $rows, $source, $batchId);
            }
            foreach ($transfers as [$d, $own, $other]) {
                $out = $d->kind === 'send';
                $this->transactionService->transfer($userId, [
                    'from_position_id' => $out ? $own : $other,
                    'to_position_id'   => $out ? $other : $own,
                    'quantity'         => $d->quantity,
                    'executed_at'      => $d->executedAt,
                    'fee'              => $out ? $d->fee : null,
                    'fee_currency'     => $out ? $d->feeCurrency : null,
                    'note'             => $d->note,
                ], ['source' => $source, 'import_batch_id' => $batchId,
                    $out ? 'external_id_out' : 'external_id_in' => $d->externalId ?: null]);
                $imported++;
            }

            if ($imported > 0) {
                $this->batches->finish($userId, $batchId, count($drafts), $imported, $skipped);
            } else {
                $this->batches->delete($userId, $batchId);
                $batchId = null;
            }
            $this->previews->delete($userId, $token);
            $pdo->commit();
            return ['batch_id' => $batchId, 'imported' => $imported, 'transfers' => count($transfers), 'skipped' => $skipped];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return int počet smazaných transakcí */
    public function revert(int $userId, int $batchId): int
    {
        $batch = $this->batches->find($userId, $batchId) ?? throw DomainError::notFound('Dávka importu nenalezena.');
        if ($batch['reverted_at'] !== null) {
            throw new DomainError('already_reverted', 'Dávka už je vrácená.', 409);
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $deleted = $this->transactionService->revertBatch($userId, $batchId);
            $this->batches->markReverted($userId, $batchId);
            $pdo->commit();
            return $deleted;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<array<string,mixed>> $assets z view()
     * @param array<string,array<string,mixed>> $decisions
     * @return array<string,?int> asset key → instrument (null = vynechat)
     */
    private function resolveInstruments(int $userId, array $assets, array $decisions): array
    {
        $out = [];
        $missing = [];
        foreach ($assets as $a) {
            if ($a['new_rows'] === 0) {
                $out[$a['key']] = $a['instrument_id'];
                continue;
            }
            $d = $decisions[$a['key']] ?? null;
            if (!empty($d['skip'])) {
                $out[$a['key']] = null;
            } elseif (isset($d['instrument_id'])) {
                $instrument = $this->instruments->find((int) $d['instrument_id']) ?? throw DomainError::validation(['assets' => ["{$a['symbol']}: instrument nenalezen."]]);
                $out[$a['key']] = (int) $instrument['id'];
            } elseif (isset($d['create']) && is_array($d['create'])) {
                $out[$a['key']] = $this->createInstrument($userId, $a, $d['create']);
            } elseif ($a['instrument_id'] !== null) {
                $out[$a['key']] = $a['instrument_id'];
            } else {
                $missing[] = $a['symbol'] !== '' ? $a['symbol'] : (string) $a['isin'];
            }
        }
        if ($missing !== []) {
            throw DomainError::validation(['assets' => ['Přiřaď nebo vynech instrumenty: ' . implode(', ', $missing) . '.']]);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $create
     */
    private function createInstrument(int $userId, array $asset, array $create): int
    {
        $class = (string) ($create['asset_class'] ?? ($asset['asset_class'] === 'crypto' ? 'crypto' : 'etf'));
        $symbol = strtoupper(trim((string) ($create['symbol'] ?? $asset['symbol'])));
        $name = trim((string) ($create['name'] ?? $asset['name'] ?? $symbol));
        $quote = strtoupper(trim((string) ($create['quote_currency'] ?? $asset['currency'] ?? '')));
        $isin = strtoupper(trim((string) ($create['isin'] ?? $asset['isin'] ?? '')));
        if (!in_array($class, InstrumentRepository::ASSET_CLASSES, true) || $symbol === '' || $name === '' || preg_match('/^[A-Z]{3}$/', $quote) !== 1) {
            throw DomainError::validation(['assets' => ["{$asset['symbol']}: vyplň třídu, symbol, název a měnu kotace nového instrumentu."]]);
        }
        $id = $this->instruments->findId($class, $symbol, $quote);
        return $id ?? $this->instruments->create([
            'asset_class' => $class, 'symbol' => mb_substr($symbol, 0, 40), 'name' => mb_substr($name, 0, 190),
            'isin' => preg_match('/^[A-Z]{2}[A-Z0-9]{9}\d$/', $isin) === 1 ? $isin : null, 'quote_currency' => $quote,
            'decimals' => $class === 'crypto' ? 8 : 4, 'price_source' => 'manual', 'source_refs' => [],
        ], $userId);
    }

    private function position(int $userId, int $portfolioId, int $accountId, int $instrumentId): int
    {
        $this->accounts->find($userId, $accountId) ?? throw DomainError::validation(['transfers' => ['Cílový účet nenalezen.']]);
        $id = $this->positions->findId($userId, $portfolioId, $accountId, $instrumentId, null);
        if ($id !== null) {
            return $id;
        }
        $instrument = $this->instruments->find($instrumentId) ?? throw DomainError::notFound('Instrument nenalezen.');
        return $this->positions->create($userId, ['portfolio_id' => $portfolioId, 'account_id' => $accountId, 'instrument_id' => $instrumentId,
            'valuation_mode' => 'market', 'name' => null, 'currency' => $instrument['quote_currency'], 'interest_rate_pa' => null]);
    }

    private function otherPosition(int $userId, int $positionId, int $instrumentId): int
    {
        $p = $this->positions->find($userId, $positionId);
        if ($p === null || $p['instrument_id'] !== $instrumentId) {
            throw DomainError::validation(['transfers' => ['Cílová pozice musí být stejný instrument.']]);
        }
        return $positionId;
    }

    /** @return array<string,mixed> vstup pro TransactionService::import */
    private function input(Draft $d, string $accountCurrency): array
    {
        $d->currency ??= $accountCurrency;
        $d->feeCurrency ??= $d->fee !== null ? $d->currency : null;
        // Přesnost sloupců DB (XLSX buňky nesou float šum, např. 982.94000000000005)
        $d->quantity = self::scale($d->quantity, Decimal::SCALE_QUANTITY);
        $d->unitPrice = self::scale($d->unitPrice, Decimal::SCALE_PRICE);
        $d->amount = self::scale($d->amount, Decimal::SCALE_AMOUNT);
        $d->fee = self::scale($d->fee, Decimal::SCALE_FEE);
        $d->splitRatio = self::scale($d->splitRatio, Decimal::SCALE_PRICE);
        $type = $d->kind;
        $base = ['external_id' => $d->externalId, 'type' => $type, 'executed_at' => $d->executedAt,
            'note' => mb_substr(trim(($d->note ?? '')), 0, 500) ?: null];
        return match ($type) {
            'buy', 'sell' => $base + ['quantity' => $d->quantity, 'unit_price' => $d->unitPrice, 'amount' => $d->amount,
                'price_currency' => $d->currency, 'fee' => $d->fee, 'fee_currency' => $d->fee !== null ? $d->feeCurrency : null],
            'reward'      => $base + ['quantity' => $d->quantity, 'amount' => $d->amount, 'price_currency' => $d->currency],
            'dividend', 'interest', 'fee' => $base + ['amount' => $d->amount, 'price_currency' => $d->currency,
                'fee' => $d->fee, 'fee_currency' => $d->fee !== null ? $d->feeCurrency : null],
            'split'       => $base + ['split_ratio' => $d->splitRatio],
            default       => throw new \LogicException("Neznámý typ řádku importu: $type"),
        };
    }

    private static function scale(?string $value, int $scale): ?string
    {
        return $value !== null ? (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp)->strippedOfTrailingZeros() : null;
    }

    /** @param list<array<string,mixed>> $candidates */
    private static function pick(array $candidates, ?string $currency): ?array
    {
        foreach ($candidates as $c) {
            if ($currency !== null && $c['quote_currency'] === $currency) {
                return $c;
            }
        }
        return $candidates[0] ?? null;
    }

    public static function source(string $parserKey): string
    {
        return str_contains($parserKey, ':') ? $parserKey : 'csv:' . $parserKey;
    }

    public static function assetKey(Draft $d): string
    {
        return ($d->assetClass === 'crypto' ? 'crypto' : 'security') . '|' . strtoupper($d->symbol) . '|' . strtoupper((string) $d->isin);
    }
}
