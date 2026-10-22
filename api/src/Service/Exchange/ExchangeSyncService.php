<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange;

use MyPortfolio\Repository\ExchangeConnectionRepository;
use MyPortfolio\Repository\ImportPreviewRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Import\CsvImportService;
use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Service\Portfolio\DomainError;

/**
 * Synchronizace transakcí přes API burzy.
 *
 *   1. Stáhne pohyby od kurzoru (`synced_until` mínus týden překryvu, jinak `sync_from`, jinak celá historie) do teď.
 *   2. Vytvoří z nich náhled importu (zdroj `api:<burza>`) — stejné párování instrumentů, převody, idempotence.
 *   3. Když není co rozhodovat (instrumenty známé, žádné odeslání / příjem), import rovnou potvrdí a posune kurzor.
 *      Jinak náhled čeká na uživatele (`pending_token`, stav `pending`); kurzor se neposune — po potvrzení nebo
 *      vypršení náhledu se období stáhne znovu a už zapsané řádky idempotence přeskočí.
 *   4. Co uživatel v potvrzeném náhledu vynechal, si napojení pamatuje (`exchange_skipped`, podle external_id) a příště
 *      to přeskočí bez ptaní. Jiný nový pohyb z týdenního překryvu (burza ho dodala se zpožděním) čeká na rozhodnutí.
 */
final class ExchangeSyncService
{
    private const OVERLAP_DAYS = 7;

    public function __construct(
        private readonly ExchangeConnectionRepository $connections,
        private readonly ExchangeClients $clients,
        private readonly SecretEncryption $secrets,
        private readonly CsvImportService $imports,
        private readonly ImportPreviewRepository $previews,
        private readonly TransactionRepository $transactions,
        private readonly PositionRepository $positions,
    ) {}

    /**
     * Pohyby (external_id) čekajícího náhledu synchronizace — volá se před potvrzením v Importu; jiný náhled = [].
     *
     * @return list<string>
     */
    public function pendingExternalIds(int $userId, string $token): array
    {
        $preview = $this->connections->findByPendingToken($userId, $token) !== null ? $this->previews->find($userId, $token) : null;
        if ($preview === null) {
            return [];
        }
        $ids = [];
        foreach ((array) ($preview['payload']['drafts'] ?? []) as $d) {
            $draft = Draft::fromArray($d);
            if ($draft->kind !== 'skip' && $draft->externalId !== null) {
                $ids[] = $draft->externalId;
            }
        }
        return $ids;
    }

    /**
     * Uživatel potvrdil čekající náhled synchronizace (Import → potvrdit): co nezapsal, je vynechané rozhodnutím
     * (zapamatovat), kurzor se posune.
     *
     * @param list<string> $externalIds výstup pendingExternalIds() před potvrzením
     */
    public function confirmed(int $userId, string $token, array $externalIds): void
    {
        $c = $this->connections->findByPendingToken($userId, $token);
        if ($c === null) {
            return;
        }
        $imported = array_flip($this->transactions->existingExternalIds($userId, 'api:' . $c['provider'], $externalIds));
        $this->connections->addSkipped($userId, $c['id'], array_values(array_filter($externalIds, static fn (string $id) => !isset($imported[$id]))));
        $this->connections->confirmPending($userId, $token);
    }

    /** Krypto aktiva, která na účtu napojení už jsou (i archivované / nulové pozice). @return list<string> */
    private function knownAssets(int $userId, int $accountId): array
    {
        $out = [];
        foreach ($this->positions->list($userId, ['account_id' => $accountId, 'archived' => true]) as $p) {
            if ($p['asset_class'] === 'crypto' && $p['instrument_symbol'] !== null) {
                $out[strtoupper((string) $p['instrument_symbol'])] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * @return array{status:string,message:string,imported:int,pending_token:?string}
     */
    public function sync(int $userId, int $connectionId, ?\DateTimeImmutable $now = null): array
    {
        $c = $this->connections->withSecret($userId, $connectionId) ?? throw DomainError::notFound('Napojení nenalezeno.');
        $client = $this->clients->get($c['provider']) ?? throw DomainError::notFound('Neznámá burza.');
        if ($c['pending_token'] !== null && $this->previews->find($userId, $c['pending_token']) !== null) {
            return ['status' => 'pending', 'message' => (string) $c['last_message'], 'imported' => 0, 'pending_token' => $c['pending_token']];
        }

        $to = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        // Od kurzoru s týdenním překryvem (pohyby, které burza dokončí se zpožděním); nikdy před začátek napojení.
        $from = $c['sync_from'] !== null ? new \DateTimeImmutable((string) $c['sync_from']) : null;
        if ($c['synced_until'] !== null) {
            $overlap = (new \DateTimeImmutable((string) $c['synced_until']))->modify('-' . self::OVERLAP_DAYS . ' days');
            $from = $from === null || $overlap > $from ? $overlap : $from;
        }
        try {
            $credentials = (array) json_decode($this->secrets->decrypt((string) $c['credentials_enc']), true, flags: JSON_THROW_ON_ERROR);
            $drafts = $client->fetch($credentials, $from, $to, $this->knownAssets($userId, $c['account_id']));
        } catch (ExchangeException | \JsonException $e) {
            $message = $e instanceof ExchangeException ? $e->getMessage() : 'Uložené přihlašovací údaje nejde přečíst — zadej klíč znovu.';
            $this->connections->recordSync($userId, $connectionId, 'error', $message, null, null);
            return ['status' => 'error', 'message' => $message, 'imported' => 0, 'pending_token' => null];
        }
        $until = $to->format('Y-m-d H:i:s');
        if ($drafts === []) {
            $this->connections->recordSync($userId, $connectionId, 'ok', 'Žádné nové pohyby.', $until, null);
            return ['status' => 'ok', 'message' => 'Žádné nové pohyby.', 'imported' => 0, 'pending_token' => null];
        }

        $label = sprintf('API %s %s – %s', $client->label(), $from?->format('Y-m-d') ?? '…', $to->format('Y-m-d H:i'));
        $token = $this->imports->previewDrafts($userId, 'api:' . $c['provider'], $client->label(), $label,
            $c['account_id'], $c['portfolio_id'], $drafts);
        $view = $this->imports->view($userId, $token);

        // Rozhodnutí potřebují nové pohyby s neznámým instrumentem a odeslání / příjmy — kromě těch, které uživatel
        // u napojení dřív vynechal (exchange_skipped). Zpožděný pohyb z překryvu se tak nikdy tiše nezahodí.
        $source = 'api:' . $c['provider'];
        $ids = array_values(array_filter(array_map(static fn (Draft $d) => $d->kind !== 'skip' ? $d->externalId : null, $drafts)));
        $existing = array_flip($this->transactions->existingExternalIds($userId, $source, $ids));
        $skipped = array_flip($this->connections->skipped($userId, $c['id'], $ids));
        $undecided = static fn (Draft $d) => $d->kind !== 'skip' && !isset($existing[$d->externalId]) && !isset($skipped[$d->externalId]);
        $openAssets = [];
        foreach ($drafts as $d) {
            if ($undecided($d)) {
                $openAssets[CsvImportService::assetKey($d)] = true;
            }
        }
        $decisions = ['assets' => [], 'transfers' => []];
        $unknown = 0;
        foreach ($view['assets'] as $a) {
            if ($a['new_rows'] > 0 && $a['instrument_id'] === null) {
                isset($openAssets[$a['key']]) ? $unknown++ : $decisions['assets'][$a['key']] = ['skip' => true];
            }
        }
        $transfers = count(array_filter($view['transfers'], static fn (array $t) => $undecided($drafts[$t['index']])));

        if ($unknown === 0 && $transfers === 0) {
            $result = $this->imports->confirm($userId, $token, $decisions);
            $message = $result['imported'] > 0 ? "Zapsáno {$result['imported']} transakcí." : 'Žádné nové pohyby.';
            $this->connections->recordSync($userId, $connectionId, 'ok', $message, $until, null);
            return ['status' => 'ok', 'message' => $message, 'imported' => $result['imported'], 'pending_token' => null];
        }

        $message = sprintf('Čeká na rozhodnutí: %d neznámých instrumentů, %d odeslání / příjmů.', $unknown, $transfers);
        $this->connections->recordSync($userId, $connectionId, 'pending', $message, null, $token, $until);
        return ['status' => 'pending', 'message' => $message, 'imported' => 0, 'pending_token' => $token];
    }
}
