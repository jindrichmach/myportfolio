<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Summary;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Přehled přijatých dividend a úroků: po letech, po měsících vybraného
 * roku, po instrumentech / pozicích a seznam plateb. Částky v měně zobrazení, hrubě / sraženo / čistě.
 */
final class IncomeOverviewService
{
    public function __construct(private readonly IncomeService $income) {}

    /** @return array<string,mixed> */
    public function overview(int $userId, string $currency, ?int $year): array
    {
        $data = $this->income->rows($userId, $currency);
        $rows = array_values(array_filter($data['rows'], static fn (array $r) => $r['type'] !== 'fee'));

        $years = [];
        foreach ($rows as $r) {
            self::add($years[substr($r['date'], 0, 4)], $r);
        }
        krsort($years);

        $scoped = $year === null ? $rows : array_values(array_filter($rows, static fn (array $r) => (int) substr($r['date'], 0, 4) === $year));
        $months = [];
        if ($year !== null) {
            for ($m = 1; $m <= 12; $m++) {
                $months[sprintf('%d-%02d', $year, $m)] = null;
            }
        }
        $positions = [];
        foreach ($scoped as $r) {
            self::add($months[substr($r['date'], 0, 7)], $r);
            $key = $r['position_id'];
            $positions[$key] ??= ['position' => $r['position'], 'symbol' => $r['symbol'], 'account' => $r['account'], 'count' => 0];
            self::add($positions[$key]['sum'], $r);
            $positions[$key]['count']++;
        }
        ksort($months);
        uasort($positions, static fn (array $a, array $b) => $b['sum']['net']->compareTo($a['sum']['net']));

        $total = null;
        foreach ($scoped as $r) {
            self::add($total, $r);
        }
        return [
            'currency'   => $currency,
            'year'       => $year,
            'years'      => array_map(static fn ($y, $s) => ['year' => (int) $y] + self::out($s), array_keys($years), $years),
            'total'      => self::out($total),
            'months'     => array_map(static fn ($m, $s) => ['month' => $m] + self::out($s), array_keys($months), $months),
            'positions'  => array_values(array_map(static fn (array $p) => ['position' => $p['position'], 'symbol' => $p['symbol'],
                'account' => $p['account'], 'count' => $p['count']] + self::out($p['sum']), $positions)),
            'payments'   => array_map(static fn (array $r) => [
                'date' => $r['date'], 'type' => $r['type'], 'position' => $r['position'], 'symbol' => $r['symbol'], 'account' => $r['account'],
                'amount' => $r['amount'], 'amount_currency' => $r['amount_currency'],
                'gross' => self::money($r['gross']), 'withheld' => self::money($r['withheld']), 'net' => self::money($r['net']),
            ], array_reverse($scoped)),
            'missing_fx' => $data['missing_fx'],
        ];
    }

    /** @param array<string,BigDecimal>|null $sum @param array<string,mixed> $r */
    private static function add(?array &$sum, array $r): void
    {
        $sum ??= ['dividends' => BigDecimal::zero(), 'interest' => BigDecimal::zero(), 'withheld' => BigDecimal::zero(), 'net' => BigDecimal::zero()];
        $key = $r['type'] === 'dividend' ? 'dividends' : 'interest';
        $sum[$key] = $sum[$key]->plus($r['gross']);
        $sum['withheld'] = $sum['withheld']->plus($r['withheld']);
        $sum['net'] = $sum['net']->plus($r['net']);
    }

    /** @param array<string,BigDecimal>|null $s @return array<string,string> */
    private static function out(?array $s): array
    {
        $z = BigDecimal::zero();
        return [
            'dividends' => self::money($s['dividends'] ?? $z),
            'interest'  => self::money($s['interest'] ?? $z),
            'withheld'  => self::money($s['withheld'] ?? $z),
            'net'       => self::money($s['net'] ?? $z),
        ];
    }

    private static function money(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }
}
