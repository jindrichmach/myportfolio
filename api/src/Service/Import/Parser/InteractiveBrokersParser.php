<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Service\Import\Reader\Table;

/**
 * Interactive Brokers — dva formáty:
 *   Activity Statement (Reports → Statements → Activity, CSV): vícesekční soubor `<Sekce>,Header|Data|Total,…`.
 *     Čteme Trades (jen Stocks / ETF; DataDiscriminator Order), Dividends, Withholding Tax, Corporate Actions
 *     (split) a Financial Instrument Information (symbol → ISIN). Časy v US Eastern.
 *   Flex Query CSV: sekce s hlavičkou obsahující ClientAccountID (volitelně BOS/EOS nebo HEADER/DATA prefixy);
 *     Trades (TradePrice, Quantity, IBCommission, TransactionID) a Cash Transactions (Type, Amount).
 * Dividenda se páruje se srážkovou daní podle symbolu (ISIN) a dne → hrubá dividenda + daň jako poplatek.
 */
final class InteractiveBrokersParser extends AbstractParser
{
    private const TZ = 'America/New_York';
    private const STOCKS = ['stocks', 'stk', 'etf', 'etfs', 'fund', 'funds'];

    public function key(): string
    {
        return 'ibkr';
    }

    public function label(): string
    {
        return 'Interactive Brokers';
    }

    public function detect(array $tables): bool
    {
        return $this->activity($tables) !== null || $this->flex($tables) !== [];
    }

    public function parse(array $tables): array
    {
        if (($sections = $this->activity($tables)) !== null) {
            return $this->parseActivity($sections);
        }
        $flex = $this->flex($tables);
        if ($flex === []) {
            throw new \InvalidArgumentException('Soubor není Activity Statement ani Flex Query z Interactive Brokers.');
        }
        return $this->parseFlex($flex);
    }

    // ── Activity Statement ──────────────────────────────────────────

    /** Sekce → datové řádky (asociativně podle poslední hlavičky sekce). @return array<string,list<array<string,string>>>|null */
    private function activity(array $tables): ?array
    {
        foreach ($tables as $t) {
            $sections = [];
            $headers = [];
            foreach ($t->rows as $i => $row) {
                $section = trim($row[0] ?? '');
                $kind = trim($row[1] ?? '');
                if ($kind === 'Header') {
                    $headers[$section] = array_map('trim', array_slice($row, 2));
                } elseif ($kind === 'Data' && isset($headers[$section])) {
                    $assoc = ['__line' => (string) ($i + 1)];
                    foreach ($headers[$section] as $c => $name) {
                        if ($name !== '') {
                            $assoc[$name] = trim((string) ($row[$c + 2] ?? ''));
                        }
                    }
                    $sections[$section][] = $assoc;
                }
            }
            if (isset($headers['Statement']) || isset($headers['Trades']) && in_array('DataDiscriminator', $headers['Trades'], true)) {
                return $sections;
            }
        }
        return null;
    }

    /** @param array<string,list<array<string,string>>> $s @return list<Draft> */
    private function parseActivity(array $s): array
    {
        $isins = [];
        foreach ($s['Financial Instrument Information'] ?? [] as $r) {
            $id = strtoupper($this->col($r, 'Security ID'));
            foreach (explode(',', $this->col($r, 'Symbol')) as $sym) {
                $isins[strtoupper(trim($sym))] = ['isin' => preg_match('/^[A-Z]{2}[A-Z0-9]{9}\d$/', $id) === 1 ? $id : null,
                    'name' => $this->col($r, 'Description') ?: null];
            }
        }

        $out = [];
        $seen = [];
        foreach ($s['Trades'] ?? [] as $r) {
            $disc = strtolower($this->col($r, 'DataDiscriminator'));
            if ($disc !== '' && $disc !== 'order' && $disc !== 'trade') {
                continue; // ClosedLot, SubTotal…
            }
            $at = $this->dateTime(str_replace(',', '', $this->col($r, 'Date/Time')), self::TZ);
            $symbol = strtoupper($this->col($r, 'Symbol'));
            $line = $this->line($r);
            $hash = $this->rowHash($r);
            $id = $hash . ':' . ($seen[$hash] = ($seen[$hash] ?? 0) + 1);
            $category = Table::norm($this->col($r, 'Asset Category'));
            if (!in_array($category, self::STOCKS, true)) {
                $out[] = Draft::skip($at, "Nepodporovaná třída „{$this->col($r, 'Asset Category')}\" (forex, opce, futures)", $line, $id, $symbol);
                continue;
            }
            $out[] = $this->trade($symbol, $isins[$symbol] ?? null, $at, $this->col($r, 'Quantity'), $this->col($r, 'Proceeds'),
                $this->col($r, 'T. Price'), $this->col($r, 'Comm/Fee'), strtoupper($this->col($r, 'Currency')), $id, $line);
        }

        $income = [];
        foreach (['Dividends' => 'dividend', 'Payment In Lieu Of Dividends' => 'dividend', 'Withholding Tax' => 'tax'] as $section => $type) {
            foreach ($s[$section] ?? [] as $r) {
                $currency = strtoupper($this->col($r, 'Currency'));
                if (str_starts_with(strtolower($currency), 'total') || $this->col($r, 'Date') === '') {
                    continue;
                }
                $income[] = ['type' => $type, 'currency' => $currency, 'date' => $this->col($r, 'Date'),
                    'desc' => $this->col($r, 'Description'), 'amount' => $this->col($r, 'Amount'), 'line' => $this->line($r)];
            }
        }
        array_push($out, ...$this->income($income, $isins));

        $splitSeen = [];
        foreach ($s['Corporate Actions'] ?? [] as $r) {
            $desc = $this->col($r, 'Description');
            if ($desc === '' || str_starts_with(strtolower($this->col($r, 'Asset Category')), 'total')) {
                continue;
            }
            $at = $this->dateTime(str_replace(',', '', $this->col($r, 'Date/Time') ?: $this->col($r, 'Report Date')), self::TZ);
            if (preg_match('/^([^(]+)\(([A-Z0-9]{12})\)\s+Split (\d+(?:\.\d+)?) for (\d+(?:\.\d+)?)/i', $desc, $m) === 1) {
                if (isset($splitSeen[$m[2] . $at])) {
                    continue;
                }
                $splitSeen[$m[2] . $at] = true;
                $out[] = new Draft('split', $at, strtoupper(trim($m[1])), $m[2], $isins[strtoupper(trim($m[1]))]['name'] ?? null, 'security',
                    splitRatio: (string) BigDecimal::of($m[3])->dividedBy($m[4], 10, RoundingMode::HalfUp)->strippedOfTrailingZeros(),
                    externalId: 'split:' . $m[2] . ':' . substr($at, 0, 10), note: $desc, line: $this->line($r));
                continue;
            }
            $out[] = Draft::skip($at, 'Korporátní akce — zadej ručně', $this->line($r), 'corp:' . $this->rowHash($r));
        }

        foreach ($s['Deposits & Withdrawals'] ?? [] as $r) {
            if ($this->col($r, 'Settle Date') !== '' && !str_starts_with(strtolower($this->col($r, 'Currency')), 'total')) {
                $out[] = Draft::skip($this->dateTime($this->col($r, 'Settle Date'), self::TZ), 'Vklad / výběr hotovosti', $this->line($r));
            }
        }
        return $out;
    }

    // ── Flex Query ──────────────────────────────────────────────────

    /** Datové řádky všech sekcí Flex Query. @return list<array<string,string>> */
    private function flex(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            $header = null;
            foreach ($t->rows as $i => $row) {
                $cells = array_map('trim', $row);
                // Prefixy „HEADER,TRNT,…" / „DATA,TRNT,…" a značky BOS/EOS
                $prefixed = in_array($cells[0] ?? '', ['HEADER', 'DATA'], true);
                $body = $prefixed ? array_slice($cells, 2) : $cells;
                if (in_array($cells[0] ?? '', ['BOF', 'EOF', 'BOA', 'EOA', 'BOS', 'EOS'], true)) {
                    $header = null;
                    continue;
                }
                if (in_array('ClientAccountID', $body, true)) {
                    $header = $body;
                    continue;
                }
                if ($header === null || implode('', $body) === '') {
                    continue;
                }
                $assoc = ['__line' => (string) ($i + 1)];
                foreach ($header as $c => $name) {
                    if ($name !== '') {
                        $assoc[$name] = $body[$c] ?? '';
                    }
                }
                $out[] = $assoc;
            }
        }
        return $out;
    }

    /** @param list<array<string,string>> $rows @return list<Draft> */
    private function parseFlex(array $rows): array
    {
        $out = [];
        $income = [];
        $isins = [];
        foreach ($rows as $r) {
            $symbol = strtoupper($this->col($r, 'Symbol'));
            if ($this->col($r, 'ISIN') !== '') {
                $isins[$symbol] = ['isin' => strtoupper($this->col($r, 'ISIN')), 'name' => $this->col($r, 'Description') ?: null];
            }
        }
        foreach ($rows as $r) {
            $symbol = strtoupper($this->col($r, 'Symbol'));
            $line = $this->line($r);
            $id = $this->col($r, 'TransactionID', 'TradeID') ?: $this->rowHash($r);
            if (array_key_exists('TradePrice', $r)) {
                $at = $this->flexDate($this->col($r, 'DateTime', 'TradeDate'));
                if (!in_array(Table::norm($this->col($r, 'AssetClass')), self::STOCKS, true)) {
                    $out[] = Draft::skip($at, "Nepodporovaná třída „{$this->col($r, 'AssetClass')}\" (forex, opce, futures)", $line, $id, $symbol);
                    continue;
                }
                $out[] = $this->trade($symbol, $isins[$symbol] ?? null, $at, $this->col($r, 'Quantity'), $this->col($r, 'Proceeds'),
                    $this->col($r, 'TradePrice'), $this->col($r, 'IBCommission'), strtoupper($this->col($r, 'CurrencyPrimary', 'Currency')), $id, $line);
                continue;
            }
            if (array_key_exists('Type', $r) && array_key_exists('Amount', $r)) {
                $type = Table::norm($this->col($r, 'Type'));
                $kind = match ($type) {
                    'dividends', 'payment in lieu of dividends' => 'dividend',
                    'withholding tax' => 'tax',
                    default => null,
                };
                $date = $this->col($r, 'DateTime', 'SettleDate', 'Date');
                if ($kind === null) {
                    $out[] = Draft::skip($this->flexDate($date), 'Hotovostní pohyb (' . $this->col($r, 'Type') . ')', $line, $id, $symbol);
                    continue;
                }
                $income[] = ['type' => $kind, 'currency' => strtoupper($this->col($r, 'CurrencyPrimary', 'Currency')),
                    'date' => substr($this->flexDate($date), 0, 10), 'desc' => $this->col($r, 'Description'), 'amount' => $this->col($r, 'Amount'),
                    'line' => $line, 'symbol' => $symbol, 'isin' => strtoupper($this->col($r, 'ISIN'))];
            }
        }
        array_push($out, ...$this->income($income, $isins));
        return $out;
    }

    /** „20240115;103000", „20240115", „2024-01-15, 10:30:00" → lokální čas. */
    private function flexDate(string $v): string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:[;, ](\d{2}):?(\d{2}):?(\d{2}))?$/', trim($v), $m) === 1) {
            $v = "{$m[1]}-{$m[2]}-{$m[3]} " . (isset($m[4]) ? "{$m[4]}:{$m[5]}:{$m[6]}" : '00:00:00');
        }
        return $this->dateTime(str_replace(',', '', $v), self::TZ);
    }

    // ── Společné ────────────────────────────────────────────────────

    /** @param array{isin:?string,name:?string}|null $info */
    private function trade(string $symbol, ?array $info, string $at, string $qtyRaw, string $proceeds, string $price, string $comm,
        string $currency, string $id, int $line): Draft
    {
        $fee = $this->num($comm, true, '.');
        $fee = $fee !== null && !BigDecimal::of($fee)->isZero() ? $fee : null;
        return new Draft($this->negative($qtyRaw) ? 'sell' : 'buy', $at, $symbol, $info['isin'] ?? null, $info['name'] ?? null, 'security',
            quantity: $this->num($qtyRaw, true, '.'), unitPrice: $proceeds === '' ? $this->num($price, true, '.') : null,
            amount: $this->num($proceeds, true, '.'), currency: $currency, fee: $fee, feeCurrency: $fee !== null ? $currency : null,
            externalId: $id, line: $line);
    }

    /**
     * Dividendy + srážková daň → hrubá dividenda s daní jako poplatkem. Symbol a ISIN z popisu
     * („AAPL(US0378331005) Cash Dividend USD 0.24 per Share"), párování podle ISIN/symbolu, dne a měny.
     *
     * @param list<array<string,string|int>> $rows
     * @param array<string,array{isin:?string,name:?string}> $isins
     * @return list<Draft>
     */
    private function income(array $rows, array $isins): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $symbol = (string) ($r['symbol'] ?? '');
            $isin = (string) ($r['isin'] ?? '');
            if (preg_match('/^([^(\s]+)\s*\(([A-Z0-9]{12})\)/', (string) $r['desc'], $m) === 1) {
                $symbol = $symbol !== '' ? $symbol : strtoupper($m[1]);
                $isin = $isin !== '' ? $isin : $m[2];
            }
            $at = $this->dateTime((string) $r['date'], self::TZ);
            $key = ($isin !== '' ? $isin : $symbol) . '|' . substr($at, 0, 10) . '|' . $r['currency'];
            $g = $groups[$key] ?? ['symbol' => $symbol, 'isin' => $isin ?: null, 'at' => $at, 'currency' => (string) $r['currency'],
                'gross' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'line' => (int) $r['line'], 'desc' => null];
            $amount = BigDecimal::of($this->num((string) $r['amount'], false, '.') ?? '0');
            if ($r['type'] === 'tax') {
                $g['tax'] = $g['tax']->minus($amount);
            } else {
                $g['gross'] = $g['gross']->plus($amount);
                $g['desc'] ??= (string) $r['desc'];
                $g['line'] = (int) $r['line'];
            }
            $groups[$key] = $g;
        }
        $out = [];
        foreach ($groups as $key => $g) {
            if (!$g['gross']->isPositive()) {
                $out[] = Draft::skip($g['at'], 'Srážková daň / oprava bez kladné dividendy — zadej ručně', $g['line'], 'div:' . $key, $g['symbol']);
                continue;
            }
            $tax = $g['tax']->isPositive() ? (string) $g['tax'] : null;
            $out[] = new Draft('dividend', $g['at'], $g['symbol'], $g['isin'] ?? ($isins[$g['symbol']]['isin'] ?? null),
                $isins[$g['symbol']]['name'] ?? null, 'security', amount: (string) $g['gross'], currency: $g['currency'],
                fee: $tax, feeCurrency: $tax !== null ? $g['currency'] : null, externalId: 'div:' . $key, note: $g['desc'], line: $g['line']);
        }
        return $out;
    }
}
