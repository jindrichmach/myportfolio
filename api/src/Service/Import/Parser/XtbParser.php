<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Service\Import\Reader\Table;

/**
 * XTB xStation 5 — Historie → Peněžní operace → Export. Tři varianty:
 *   A) XLSX do ~2026: list „CASH OPERATION HISTORY", hlavička ID | Type | Time | Comment | Symbol | Amount,
 *      nad ní preambule s měnou účtu; časy jako Excel serial v čase serveru (CET). Prodej = „Stock sale"
 *      (nákupní hodnota) + řádek „close trade" („Profit of position #…") se ziskem.
 *   B) XLSX 2026: list „Cash Operations", hlavička Type | Ticker | Instrument | Time | Amount | ID | Comment | Product,
 *      časy v UTC, „Stock sell" = skutečný výnos; měna účtu v souboru není (= měna vybraného účtu).
 *   C) CSV (starší): ID;Type;Time;Symbol;Comment;Amount, čas „DD.MM.YYYY HH:MM:SS".
 * Amount je vždy v měně účtu. Množství a cena jen z Comment („OPEN BUY 10 @ 101.50", „CLOSE BUY 4/10 @ 110").
 * Comment je ve všech jazycích anglicky → klasifikace podle něj, Type jen jako nápověda.
 */
final class XtbParser extends AbstractParser
{
    private const SUFFIX_CURRENCY = ['US' => 'USD', 'DE' => 'EUR', 'FR' => 'EUR', 'NL' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'BE' => 'EUR',
        'PT' => 'EUR', 'FI' => 'EUR', 'IE' => 'EUR', 'AT' => 'EUR', 'UK' => 'GBP', 'PL' => 'PLN', 'CZ' => 'CZK', 'CH' => 'CHF', 'DK' => 'DKK',
        'SE' => 'SEK', 'NO' => 'NOK'];
    private const TRADE = '/^(OPEN|CLOSE) (?:BUY|SELL) ([\d.]+)(?:\/[\d.]+)? @ ([\d.]+)/i';

    public function key(): string
    {
        return 'xtb';
    }

    public function label(): string
    {
        return 'XTB';
    }

    public function detect(array $tables): bool
    {
        return $this->variant($tables) !== null;
    }

    /** @return array{0:string,1:list<array<string,string>>}|null */
    private function variant(array $tables): ?array
    {
        if (($r = $this->rows($tables, ['Type', 'Ticker', 'Instrument', 'Time', 'Amount', 'ID', 'Comment'])) !== null) {
            return ['B', $r];
        }
        if (($r = $this->rows($tables, ['ID', 'Type', 'Time', 'Comment', 'Symbol', 'Amount'])) !== null) {
            return [$this->anyName($tables, 'CASH OPERATION HISTORY') ? 'A' : 'C', $r];
        }
        return null;
    }

    public function parse(array $tables): array
    {
        [$variant, $rows] = $this->variant($tables) ?? throw new \InvalidArgumentException('Nenalezen list s peněžními operacemi XTB.');
        $currency = $variant === 'A' ? $this->accountCurrency($tables) : null;
        $tz = $variant === 'B' ? 'UTC' : 'Europe/Prague';

        // Zisk/ztráta k prodeji (varianta A): „Profit of position #…" ve stejný čas a symbol.
        $profits = [];
        foreach ($rows as $row) {
            if (preg_match('/^Profit of position/i', $this->col($row, 'Comment')) === 1) {
                $profits[$this->col($row, 'Time') . '|' . $this->symbolRaw($row)] = $this->num($this->col($row, 'Amount'), false, '.');
            }
        }

        $out = [];
        $dividends = [];
        $taxes = [];
        foreach ($rows as $row) {
            $comment = $this->col($row, 'Comment');
            $type = Table::norm($this->col($row, 'Type'));
            $rawTime = $this->col($row, 'Time');
            if ($rawTime === '' || Table::norm($this->col($row, 'ID')) === 'total') {
                continue; // patička „Total"
            }
            $at = $this->dateTime($rawTime, $tz, $variant === 'C' ? 'd.m.Y H:i:s' : null);
            $line = $this->line($row);
            $id = $this->col($row, 'ID') ?: $this->rowHash($row);
            $raw = $this->symbolRaw($row);
            [$symbol, $quote] = $this->symbol($raw);
            $amount = $this->num($this->col($row, 'Amount'), false, '.');
            $abs = $amount !== null ? ltrim($amount, '-') : null;

            if (preg_match(self::TRADE, $comment, $m) === 1) {
                $close = strtoupper($m[1]) === 'CLOSE';
                $value = $abs;
                if ($close && isset($profits[$rawTime . '|' . $raw])) {
                    $value = (string) BigDecimal::of((string) $abs)->plus($profits[$rawTime . '|' . $raw])->abs();
                }
                $out[] = new Draft($close ? 'sell' : 'buy', $at, $symbol, name: $this->col($row, 'Instrument') ?: null, assetClass: 'security',
                    quantity: $m[2], amount: $value, currency: $currency, externalId: $id, note: $comment, line: $line, quoteCurrency: $quote);
                continue;
            }
            if (preg_match('/^Profit of position/i', $comment) === 1 || $type === 'close trade') {
                continue; // započteno do prodeje
            }
            $isTax = preg_match('/\bWHT\b/i', $comment) === 1 || str_contains($type, 'withholding') || str_contains($type, 'podatek');
            if ($isTax && $symbol !== '') {
                $taxes[] = ['symbol' => $symbol, 'date' => substr($at, 0, 10), 'at' => $at, 'amount' => $abs, 'id' => $id, 'line' => $line, 'quote' => $quote];
                continue;
            }
            if ((str_contains($comment, '/ SHR') || str_contains($type, 'divid') || str_contains($type, 'dywidend')) && $symbol !== '') {
                $dividends[] = new Draft('dividend', $at, $symbol, assetClass: 'security', amount: $abs, currency: $currency,
                    externalId: $id, note: $comment, line: $line, quoteCurrency: $quote);
                continue;
            }
            $out[] = Draft::skip($at, $this->skipReason($type), $line, $id, $symbol);
        }

        // Srážková daň k dividendě stejného symbolu a dne → poplatek dividendy.
        foreach ($taxes as $t) {
            foreach ($dividends as $d) {
                if ($d->symbol === $t['symbol'] && substr($d->executedAt, 0, 10) === $t['date'] && $d->fee === null) {
                    $d->fee = $t['amount'];
                    $d->feeCurrency = $currency;
                    continue 2;
                }
            }
            $out[] = new Draft('fee', $t['at'], $t['symbol'], assetClass: 'security', amount: $t['amount'], currency: $currency,
                externalId: $t['id'], note: 'Srážková daň', line: $t['line'], quoteCurrency: $t['quote']);
        }
        return [...$out, ...$dividends];
    }

    private function skipReason(string $type): string
    {
        return match (true) {
            str_contains($type, 'deposit'), str_contains($type, 'withdraw') => 'Vklad / výběr hotovosti',
            str_contains($type, 'interest')                                 => 'Úrok z volné hotovosti (hotovost se nepromítá)',
            default                                                         => 'Peněžní operace bez instrumentu (poplatek, swap, převod)',
        };
    }

    private function symbolRaw(array $row): string
    {
        return strtoupper($this->col($row, 'Symbol', 'Ticker'));
    }

    /** „ABCD.US" → [ABCD, USD]. @return array{0:string,1:?string} */
    private function symbol(string $raw): array
    {
        if (preg_match('/^(.+)\.([A-Z]{2})$/', $raw, $m) === 1) {
            return [$m[1], self::SUFFIX_CURRENCY[$m[2]] ?? null];
        }
        return [$raw, null];
    }

    /** Měna účtu z preambule varianty A (hodnota pod buňkou „Currency"). */
    private function accountCurrency(array $tables): ?string
    {
        foreach ($tables as $t) {
            foreach ($t->rows as $i => $row) {
                foreach ($row as $c => $cell) {
                    if (in_array(Table::norm($cell), ['currency', 'mena', 'waluta'], true)) {
                        $value = strtoupper(trim((string) ($t->rows[$i + 1][$c] ?? '')));
                        if (preg_match('/^[A-Z]{3}$/', $value) === 1) {
                            return $value;
                        }
                    }
                }
            }
        }
        return null;
    }

    private function anyName(array $tables, string $name): bool
    {
        foreach ($tables as $t) {
            if (Table::norm($t->name) === Table::norm($name)) {
                return true;
            }
        }
        return false;
    }
}
