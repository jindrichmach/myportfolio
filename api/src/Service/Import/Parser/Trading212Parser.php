<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\Import\Draft;

/**
 * Trading 212 — Menu → History → Export (CSV). Sloupce jsou dynamické (podle obsahu období) a jejich pořadí
 * se měnilo → vše podle názvu. Čas v UTC („Time" nebo „Time (UTC)"). `Total` je v měně účtu (`Currency (Total)`,
 * starší soubory „Total (EUR)"), cena za kus v měně instrumentu. Dividenda: Price / share a Total jsou ČISTÉ,
 * srážková daň ve sloupci `Withholding tax` → hrubá dividenda v měně instrumentu + daň jako poplatek.
 * Split = dvojice „Stock split close" (počet před) + „Stock split open" (počet po).
 */
final class Trading212Parser extends AbstractParser
{
    private const FEES = ['Currency conversion fee', 'Transaction fee', 'Finra fee', 'Stamp duty reserve tax', 'Stamp duty', 'French transaction tax'];

    public function key(): string
    {
        return 'trading212';
    }

    public function label(): string
    {
        return 'Trading 212';
    }

    public function detect(array $tables): bool
    {
        return $this->hasHeader($tables, ['Action', 'ISIN', 'Ticker', 'No. of shares', 'Price / share']);
    }

    public function parse(array $tables): array
    {
        $rows = $this->rows($tables, ['Action', 'ISIN', 'Ticker', 'No. of shares', 'Price / share'])
            ?? throw new \InvalidArgumentException('Nenalezena hlavička Trading 212 exportu.');
        $out = [];
        $splits = [];
        $seen = [];
        foreach ($rows as $row) {
            $action = strtolower($this->col($row, 'Action'));
            $at = $this->dateTime($this->col($row, 'Time', 'Time (UTC)'));
            $line = $this->line($row);
            $hash = $this->rowHash($row);
            $id = $this->col($row, 'ID') ?: $hash . ':' . ($seen[$hash] = ($seen[$hash] ?? 0) + 1);
            $isin = strtoupper($this->col($row, 'ISIN')) ?: null;
            $ticker = strtoupper($this->col($row, 'Ticker'));
            $name = $this->col($row, 'Name') ?: null;
            $qty = $this->num($this->col($row, 'No. of shares'), true, '.');
            [$pps, $ppsCurrency] = $this->price($row);
            [$total, $totalCurrency] = $this->total($row);
            $asset = ['symbol' => $ticker, 'isin' => $isin, 'name' => $name, 'assetClass' => 'security', 'quoteCurrency' => $ppsCurrency];

            if (preg_match('/^(market|limit|stop|stop limit) (buy|sell)$/', $action, $m) === 1) {
                $buy = $m[2] === 'buy';
                $fees = $this->fees($row, $totalCurrency);
                $amount = $total === null ? null
                    : (string) ($buy ? BigDecimal::of($total)->minus($fees) : BigDecimal::of($total)->plus($fees))->abs()->strippedOfTrailingZeros();
                $out[] = new Draft(...$asset + ['kind' => $buy ? 'buy' : 'sell', 'executedAt' => $at, 'quantity' => $qty, 'unitPrice' => $amount === null ? $pps : null,
                    'amount' => $amount, 'currency' => $amount === null ? $ppsCurrency : $totalCurrency,
                    'fee' => $fees->isZero() ? null : (string) $fees, 'feeCurrency' => $fees->isZero() ? null : $totalCurrency,
                    'externalId' => $id, 'line' => $line]);
                continue;
            }
            if (str_starts_with($action, 'dividend') && $action !== 'dividend adjustment') {
                $wht = $this->num($this->col($row, 'Withholding tax'), true, '.');
                $whtCurrency = strtoupper($this->col($row, 'Currency (Withholding tax)'));
                if ($qty !== null && $pps !== null && ($wht === null || $whtCurrency === $ppsCurrency)) {
                    $net = BigDecimal::of($qty)->multipliedBy($pps);
                    $out[] = new Draft(...$asset + ['kind' => 'dividend', 'executedAt' => $at, 'amount' => (string) $net->plus($wht ?? '0')->strippedOfTrailingZeros(),
                        'currency' => $ppsCurrency, 'fee' => $wht, 'feeCurrency' => $wht !== null ? $whtCurrency : null,
                        'externalId' => $id, 'note' => $this->col($row, 'Action'), 'line' => $line]);
                } else {
                    $out[] = new Draft(...$asset + ['kind' => 'dividend', 'executedAt' => $at, 'amount' => $total, 'currency' => $totalCurrency,
                        'externalId' => $id, 'note' => $this->col($row, 'Action') . ' (čistá částka)', 'line' => $line]);
                }
                continue;
            }
            if ($action === 'stock split close' || $action === 'stock split open') {
                $splits[$isin . '|' . $at][$action] = ['qty' => $qty, 'asset' => $asset, 'line' => $line, 'id' => $id];
                continue;
            }
            $out[] = Draft::skip($at, match (true) {
                in_array($action, ['deposit', 'withdrawal', 'currency conversion', 'interest on cash', 'lending interest', 'spending cashback',
                    'card debit', 'card credit', 'card refund', 'result adjustment'], true) => 'Hotovost (vklad, výběr, úrok, karta)',
                default => "Nepodporovaný pohyb „{$this->col($row, 'Action')}\" — zadej ručně",
            }, $line, $id, $ticker);
        }
        foreach ($splits as $key => $pair) {
            $at = explode('|', $key)[1];
            $closeQty = $pair['stock split close']['qty'] ?? null;
            $openQty = $pair['stock split open']['qty'] ?? null;
            $any = $pair['stock split open'] ?? $pair['stock split close'];
            if ($closeQty === null || $openQty === null || BigDecimal::of($closeQty)->isZero()) {
                $out[] = Draft::skip($at, 'Neúplný split — zadej ručně', $any['line'], $any['id'], $any['asset']['symbol']);
                continue;
            }
            $out[] = new Draft(...$any['asset'] + ['kind' => 'split', 'executedAt' => $at,
                'splitRatio' => (string) BigDecimal::of($openQty)->dividedBy($closeQty, 10, \Brick\Math\RoundingMode::HalfUp)->strippedOfTrailingZeros(),
                'externalId' => 'split:' . $any['id'], 'line' => $any['line']]);
        }
        return $out;
    }

    /** Cena za kus v měně instrumentu; GBX (pence) → GBP. @return array{0:?string,1:?string} */
    private function price(array $row): array
    {
        $pps = $this->num($this->col($row, 'Price / share'), true, '.');
        $cur = strtoupper($this->col($row, 'Currency (Price / share)')) ?: null;
        if ($cur === 'GBX' && $pps !== null) {
            return [(string) BigDecimal::of($pps)->dividedBy(100, 10, \Brick\Math\RoundingMode::HalfUp)->strippedOfTrailingZeros(), 'GBP'];
        }
        return [$pps, $cur];
    }

    /** Celková částka v měně účtu: `Total` + `Currency (Total)`, nebo starší „Total (EUR)". @return array{0:?string,1:?string} */
    private function total(array $row): array
    {
        $total = $this->col($row, 'Total');
        if ($total !== '') {
            return [$this->num($total, false, '.'), strtoupper($this->col($row, 'Currency (Total)')) ?: null];
        }
        foreach ($row as $k => $v) {
            if (preg_match('/^Total \(([A-Z]{3})\)$/', (string) $k, $m) === 1) {
                return [$this->num((string) $v, false, '.'), $m[1]];
            }
        }
        return [null, null];
    }

    /** Součet poplatků v měně účtu (sloupec + „Currency (…)" nebo „… (EUR)"). */
    private function fees(array $row, ?string $totalCurrency): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach (self::FEES as $fee) {
            $value = $this->col($row, $fee);
            $cur = strtoupper($this->col($row, "Currency ($fee)"));
            if ($value === '') {
                foreach ($row as $k => $v) {
                    if (preg_match('/^' . preg_quote($fee, '/') . ' \(([A-Z]{3})\)$/', (string) $k, $m) === 1) {
                        [$value, $cur] = [(string) $v, $m[1]];
                    }
                }
            }
            $n = $this->num($value, true, '.');
            if ($n !== null && ($cur === '' || $cur === $totalCurrency)) {
                $sum = $sum->plus($n);
            }
        }
        return $sum;
    }
}
