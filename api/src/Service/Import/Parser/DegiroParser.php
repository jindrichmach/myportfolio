<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Service\Import\Reader\Table;

/**
 * DEGIRO — Aktivita → Transakce → Export CSV (`Transactions.csv`) a Aktivita → Výpis z účtu (`Account.csv`,
 * jen pro dividendy). Obě mohou přijít zvlášť nebo spolu v ZIPu. Hlavičky EN/CZ, nepojmenované sloupce
 * nesou měnu / částku k sousednímu sloupci → řádky se čtou podle pozice (`<sloupec> #2` = sloupec vpravo).
 *   Transactions: …,Quantity,Price,<měna>,Local value,<měna>,Value,<měna>|Value EUR,…,Transaction and/or third,<měna>,Total,<měna>,Order ID
 *   Account:      Date,Time,Value date,Product,ISIN,Description,FX,Change,<částka>,Balance,<částka>,Order Id
 * Znaménko množství = směr, časy v místním čase (Amsterdam). Split = dvojice řádků bez Order ID (staré kusy −, nové +).
 */
final class DegiroParser extends AbstractParser
{
    private const TZ = 'Europe/Amsterdam';

    public function key(): string
    {
        return 'degiro';
    }

    public function label(): string
    {
        return 'DEGIRO';
    }

    public function detect(array $tables): bool
    {
        foreach ($tables as $t) {
            if ($this->transactions($t) !== null || $this->account($t) !== null) {
                return true;
            }
        }
        return false;
    }

    public function parse(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            if (($rows = $this->transactions($t)) !== null) {
                array_push($out, ...$this->parseTransactions($rows));
            } elseif (($rows = $this->account($t)) !== null) {
                array_push($out, ...$this->parseAccount($rows));
            }
        }
        if ($out === [] && !$this->detect($tables)) {
            throw new \InvalidArgumentException('Nenalezen Transactions.csv ani Account.csv z DEGIRO.');
        }
        return $out;
    }

    /** @return list<array<string,string>>|null */
    private function transactions(Table $t): ?array
    {
        return $this->positional($t, [['isin'], ['venue'], ['quantity', 'pocet'], ['price', 'cena'], ['order id', 'id objednavky']]);
    }

    /** @return list<array<string,string>>|null */
    private function account(Table $t): ?array
    {
        return $this->positional($t, [['isin'], ['description', 'popis'], ['change', 'pohyb'], ['balance', 'zustatek']]);
    }

    /**
     * Řádky podle hlavičky, kde nepojmenovaný sloupec dostane název „<předchozí> #2".
     *
     * @param list<list<string>> $required alternativy normalizovaných názvů
     * @return list<array<string,string>>|null
     */
    private function positional(Table $t, array $required): ?array
    {
        foreach ($t->rows as $i => $row) {
            $have = array_map([Table::class, 'norm'], $row);
            foreach ($required as $alts) {
                if (array_intersect($alts, $have) === []) {
                    continue 2;
                }
            }
            $header = [];
            $prev = '';
            foreach ($row as $c => $name) {
                $name = trim($name);
                $header[$c] = $name !== '' ? $name : ($prev !== '' ? $prev . ' #2' : '');
                $prev = $name !== '' ? $name : '';
            }
            $out = [];
            foreach (array_slice($t->rows, $i + 1, null, true) as $idx => $cells) {
                if (implode('', array_map('trim', $cells)) === '') {
                    continue;
                }
                $assoc = ['__line' => (string) ($idx + 1)];
                foreach ($header as $c => $name) {
                    if ($name !== '') {
                        $assoc[$name] = trim((string) ($cells[$c] ?? ''));
                    }
                }
                $out[] = $assoc;
            }
            return $out;
        }
        return null;
    }

    /** @param list<array<string,string>> $rows @return list<Draft> */
    private function parseTransactions(array $rows): array
    {
        $out = [];
        $splits = [];
        $seen = [];
        foreach ($rows as $row) {
            $at = $this->dateTime(trim($this->col($row, 'Date', 'Datum') . ' ' . $this->col($row, 'Time', 'Čas')), self::TZ);
            $line = $this->line($row);
            $isin = strtoupper($this->col($row, 'ISIN')) ?: null;
            $name = $this->col($row, 'Product', 'Produkt') ?: null;
            $qtyRaw = $this->col($row, 'Quantity', 'Počet');
            $qty = $this->num($qtyRaw, true);
            $orderId = $this->col($row, 'Order ID', 'ID objednávky');
            if ($orderId === '') {
                $splits[substr($at, 0, 10) . '|' . $name][] = ['isin' => $isin, 'name' => $name, 'qty' => $qty,
                    'negative' => $this->negative($qtyRaw), 'at' => $at, 'line' => $line];
                continue;
            }
            $hash = $this->rowHash($row);
            $id = $orderId . ':' . substr($hash, 0, 8) . ':' . ($seen[$hash] = ($seen[$hash] ?? 0) + 1);
            [$value, $currency] = $this->accountValue($row);
            $fees = BigDecimal::zero();
            foreach ($row as $k => $v) {
                if (preg_match('/^(transaction|autofx|transakcni|poplatek)/i', Table::norm((string) $k)) === 1 && !str_ends_with((string) $k, ' #2')) {
                    $fees = $fees->plus($this->num((string) $v, true) ?? '0');
                }
            }
            $priceCurrency = strtoupper($this->col($row, 'Price #2', 'Cena #2')) ?: null;
            $out[] = new Draft($this->negative($qtyRaw) ? 'sell' : 'buy', $at, '', $isin, $name, 'security', quantity: $qty,
                unitPrice: $value === null ? $this->num($this->col($row, 'Price', 'Cena'), true) : null,
                amount: $value, currency: $value === null ? $priceCurrency : $currency,
                fee: $fees->isZero() ? null : (string) $fees, feeCurrency: $fees->isZero() ? null : $currency,
                externalId: $id, line: $line, quoteCurrency: $priceCurrency);
        }
        foreach ($splits as $group) {
            $old = array_values(array_filter($group, static fn ($r) => $r['negative']));
            $new = array_values(array_filter($group, static fn ($r) => !$r['negative']));
            if (count($old) === 1 && count($new) === 1 && $old[0]['qty'] !== null && $new[0]['qty'] !== null && !BigDecimal::of($old[0]['qty'])->isZero()) {
                $ratio = BigDecimal::of($new[0]['qty'])->dividedBy($old[0]['qty'], 10, RoundingMode::HalfUp)->strippedOfTrailingZeros();
                $out[] = new Draft('split', $old[0]['at'], '', $old[0]['isin'], $old[0]['name'], 'security', splitRatio: (string) $ratio,
                    externalId: 'split:' . $old[0]['isin'] . ':' . substr($old[0]['at'], 0, 10), line: $old[0]['line'],
                    note: $old[0]['isin'] !== $new[0]['isin'] ? "Nový ISIN {$new[0]['isin']}" : null);
                continue;
            }
            foreach ($group as $r) {
                $out[] = Draft::skip($r['at'], 'Korporátní akce bez Order ID — zadej ručně', $r['line'], 'corp:' . $r['line'], (string) $r['name']);
            }
        }
        return $out;
    }

    /** Hodnota obchodu bez poplatků v měně účtu: „Value EUR" nebo „Value" + měna vpravo. @return array{0:?string,1:?string} */
    private function accountValue(array $row): array
    {
        foreach ($row as $k => $v) {
            if (preg_match('/^(Value|Hodnota) ([A-Z]{3})$/', (string) $k, $m) === 1) {
                return [$this->num((string) $v, true), $m[2]];
            }
        }
        $value = $this->num($this->col($row, 'Value', 'Hodnota'), true);
        return [$value, strtoupper($this->col($row, 'Value #2', 'Hodnota #2')) ?: null];
    }

    /** @param list<array<string,string>> $rows @return list<Draft> */
    private function parseAccount(array $rows): array
    {
        // Dlouhý popis pokračuje na dalším řádku bez data
        $merged = [];
        foreach ($rows as $row) {
            if ($this->col($row, 'Date', 'Datum') === '' && $merged !== []) {
                $last = array_key_last($merged);
                foreach (['Description', 'Popis'] as $k) {
                    if (isset($merged[$last][$k])) {
                        $merged[$last][$k] = trim($merged[$last][$k] . ' ' . ($row[$k] ?? ''));
                    }
                }
                continue;
            }
            $merged[] = $row;
        }

        $out = [];
        $dividends = [];
        $taxes = [];
        foreach ($merged as $row) {
            $at = $this->dateTime(trim($this->col($row, 'Date', 'Datum') . ' ' . $this->col($row, 'Time', 'Čas')), self::TZ);
            $desc = $this->col($row, 'Description', 'Popis');
            $isin = strtoupper($this->col($row, 'ISIN'));
            $currency = strtoupper($this->col($row, 'Change', 'Pohyb'));
            $amount = $this->col($row, 'Change #2', 'Pohyb #2');
            $key = $isin . '|' . substr($at, 0, 10) . '|' . $currency;
            $norm = Table::norm($desc);
            if ($isin !== '' && preg_match('/^(dividend tax|dan z dividendy)/', $norm) === 1) {
                $taxes[$key] = ($taxes[$key] ?? BigDecimal::zero())->plus($this->num($amount, false) ?? '0');
            } elseif ($isin !== '' && preg_match('/^(dividend|dividenda|fund distribution|distribuce fondu|capital return)/', $norm) === 1) {
                $dividends[$key] = ['row' => $row, 'at' => $at, 'amount' => ($dividends[$key]['amount'] ?? BigDecimal::zero())
                    ->plus($this->num($amount, false) ?? '0'), 'currency' => $currency, 'desc' => $desc];
            } else {
                $out[] = Draft::skip($at, 'Pohyb na účtu (obchody se importují z Transactions.csv)', $this->line($row), '', $isin);
            }
        }
        foreach ($dividends as $key => $d) {
            $tax = isset($taxes[$key]) ? $taxes[$key]->negated() : BigDecimal::zero();
            $out[] = new Draft('dividend', $d['at'], '', explode('|', $key)[0], $this->col($d['row'], 'Product', 'Produkt') ?: null, 'security',
                amount: (string) $d['amount']->abs(), currency: $d['currency'],
                fee: $tax->isPositive() ? (string) $tax : null, feeCurrency: $tax->isPositive() ? $d['currency'] : null,
                externalId: 'div:' . $key, note: $d['desc'], line: $this->line($d['row']));
        }
        return $out;
    }
}
