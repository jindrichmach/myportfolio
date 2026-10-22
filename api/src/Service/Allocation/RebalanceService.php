<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Allocation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\AllocationTargetRepository;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\LotRepository;
use MyPortfolio\Service\Portfolio\ValuationService;
use MyPortfolio\Service\Tax\CzTaxRules;

/**
 * Cílová alokace a rebalancing.
 *
 *   třída     — podíl na celku (krypto, ETF, akcie, fondy / ruční, hotovost), cíl v součtu 100 %
 *   instrument — volitelně podíl uvnitř třídy (součet 100 %); instrument třídy bez cíle má cíl 0 %
 *
 * Návrh vkladu rozdělí částku mezi třídy (a instrumenty) úměrně tomu, kolik jim po vkladu chybí do cíle — bez prodeje.
 * Plný rebalancing srovná vše na cíl; u prodejů FIFO odhadne, kolik kusů pochází z lotů mladších 3 let (zdanitelné).
 * Pozice bez ocenění (chybí cena / kurz) se do výpočtu nepočítají a vrátí se v `incomplete`.
 */
final class RebalanceService
{
    public const CATEGORIES = ['crypto', 'etf', 'stock', 'fund', 'cash'];
    public const DEFAULT_BAND = '5';
    private const TAXED = ['crypto', 'etf', 'stock'];
    private const SCALE = 10;

    public function __construct(
        private readonly ValuationService $valuation,
        private readonly AllocationTargetRepository $targets,
        private readonly LotRepository $lots,
        private readonly InstrumentRepository $instruments,
    ) {}

    /** @return array<string,mixed> */
    public function compute(int $userId, string $currency, ?int $portfolioId, ?BigDecimal $deposit = null, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $rows = array_values(array_filter(
            $this->valuation->positions($userId, $currency),
            static fn (array $r) => !$r['archived'] && ($portfolioId === null || (int) $r['portfolio_id'] === $portfolioId),
        ));
        $targets = $this->targets->list($userId, $portfolioId);

        $classTargets = [];
        $instrumentTargets = [];
        foreach ($targets as $t) {
            if ($t['instrument_id'] === null) {
                $classTargets[$t['category']] = $t;
            } else {
                $instrumentTargets[$t['category']][$t['instrument_id']] = BigDecimal::of($t['target_pct']);
            }
        }

        // Skutečnost: třída → instrument (ruční pozice bez instrumentu jako vlastní položka)
        $classes = [];
        $incomplete = [];
        $total = BigDecimal::zero();
        foreach ($rows as $r) {
            if ($r['value'] === null) {
                $incomplete[] = ['position_id' => $r['position_id'], 'display_name' => $r['display_name']];
                continue;
            }
            $value = BigDecimal::of($r['value']);
            $instrumentTarget = $r['instrument_id'] !== null && isset($instrumentTargets[$r['category']][(int) $r['instrument_id']]);
            if ($value->isZero() && !$instrumentTarget) {
                continue;
            }
            $key = $r['instrument_id'] !== null ? 'i' . $r['instrument_id'] : 'p' . $r['position_id'];
            $c = &$classes[$r['category']];
            $c ??= ['value' => BigDecimal::zero(), 'items' => []];
            $c['value'] = $c['value']->plus($value);
            $item = &$c['items'][$key];
            $item ??= ['instrument_id' => $r['instrument_id'] !== null ? (int) $r['instrument_id'] : null,
                'label' => $r['display_name'], 'name' => $r['instrument_name'], 'value' => BigDecimal::zero(), 'positions' => []];
            $item['value'] = $item['value']->plus($value);
            $item['positions'][] = $r;
            unset($c, $item);
            $total = $total->plus($value);
        }
        // Instrumenty s cílem, které zatím nikde nedržíš
        foreach ($instrumentTargets as $category => $list) {
            foreach (array_keys($list) as $instrumentId) {
                $classes[$category] ??= ['value' => BigDecimal::zero(), 'items' => []];
                if (!isset($classes[$category]['items']['i' . $instrumentId])) {
                    $instrument = $this->instruments->find($instrumentId);
                    $classes[$category]['items']['i' . $instrumentId] = ['instrument_id' => $instrumentId,
                        'label' => $instrument['symbol'] ?? null, 'name' => $instrument['name'] ?? null, 'value' => BigDecimal::zero(), 'positions' => []];
                }
            }
        }
        foreach (array_keys($classTargets) as $category) {
            $classes[$category] ??= ['value' => BigDecimal::zero(), 'items' => []];
        }
        uksort($classes, static fn (string $a, string $b) => self::order($a) <=> self::order($b));

        $hasTargets = $classTargets !== [];
        $out = [];
        $outOfBand = 0;
        foreach ($classes as $category => $c) {
            $target = isset($classTargets[$category]) ? BigDecimal::of($classTargets[$category]['target_pct']) : BigDecimal::zero();
            $band = BigDecimal::of($classTargets[$category]['band_pct'] ?? self::DEFAULT_BAND);
            $pct = self::share($c['value'], $total);
            $diffPct = $pct->minus($target);
            $targetValue = $total->multipliedBy($target)->dividedBy(100, self::SCALE, RoundingMode::HalfUp);
            $outside = $hasTargets && $diffPct->abs()->isGreaterThan($band);
            $outOfBand += $outside ? 1 : 0;
            $itemTargets = $instrumentTargets[$category] ?? null;
            $items = [];
            foreach ($c['items'] as $item) {
                $itemTarget = $itemTargets !== null ? ($itemTargets[$item['instrument_id']] ?? BigDecimal::zero()) : null;
                $items[] = [
                    'instrument_id' => $item['instrument_id'],
                    'label'         => $item['label'],
                    'name'          => $item['name'],
                    'value'         => self::out($item['value']),
                    'pct'           => self::out(self::share($item['value'], $c['value'])),
                    'target_pct'    => $itemTarget !== null ? self::out($itemTarget) : null,
                    'diff_amount'   => $itemTarget !== null
                        ? self::out($item['value']->minus($targetValue->multipliedBy($itemTarget)->dividedBy(100, self::SCALE, RoundingMode::HalfUp)))
                        : null,
                ];
            }
            usort($items, static fn (array $a, array $b) => BigDecimal::of($b['value'])->compareTo($a['value']));
            $out[] = [
                'category'    => $category,
                'value'       => self::out($c['value']),
                'pct'         => self::out($pct),
                'target_pct'  => $hasTargets ? self::out($target) : null,
                'band_pct'    => $hasTargets ? self::out($band) : null,
                'diff_pct'    => $hasTargets ? self::out($diffPct) : null,
                'diff_amount' => $hasTargets ? self::out($c['value']->minus($targetValue)) : null,
                'out_of_band' => $outside,
                'instrument_targets' => $itemTargets !== null,
                'items'       => $items,
            ];
        }

        return [
            'currency'     => $currency,
            'portfolio_id' => $portfolioId,
            'has_targets'  => $hasTargets,
            'total'        => self::out($total),
            'out_of_band'  => $outOfBand,
            'classes'      => $out,
            'targets'      => $targets,
            'deposit'      => $hasTargets && $deposit !== null && $deposit->isPositive()
                ? ['amount' => self::out($deposit), 'lines' => $this->depositPlan($classes, $classTargets, $instrumentTargets, $total, $deposit)]
                : null,
            'rebalance'    => $hasTargets ? $this->rebalance($userId, $classes, $classTargets, $instrumentTargets, $total, $today) : [],
            'incomplete'   => $incomplete,
        ];
    }

    /**
     * Rozdělení vkladu bez prodeje: úměrně schodku do cíle po vkladu (třída, pak instrumenty uvnitř třídy).
     *
     * @param array<string,array<string,mixed>> $classes
     * @param array<string,array<string,mixed>> $classTargets
     * @param array<string,array<int,BigDecimal>> $instrumentTargets
     * @return list<array<string,mixed>>
     */
    private function depositPlan(array $classes, array $classTargets, array $instrumentTargets, BigDecimal $total, BigDecimal $deposit): array
    {
        $after = $total->plus($deposit);
        $classDeficits = [];
        foreach ($classes as $category => $c) {
            $target = isset($classTargets[$category]) ? BigDecimal::of($classTargets[$category]['target_pct']) : BigDecimal::zero();
            $classDeficits[$category] = self::positive($after->multipliedBy($target)->dividedBy(100, self::SCALE, RoundingMode::HalfUp)->minus($c['value']));
        }
        $lines = [];
        foreach (self::split($deposit, $classDeficits) as $category => $amount) {
            if (!isset($instrumentTargets[$category])) {
                $lines[] = self::line('buy', $category, null, null, $amount);
                continue;
            }
            $classAfter = $classes[$category]['value']->plus($amount);
            $itemDeficits = [];
            foreach ($classes[$category]['items'] as $key => $item) {
                $t = $instrumentTargets[$category][$item['instrument_id']] ?? BigDecimal::zero();
                $itemDeficits[$key] = self::positive($classAfter->multipliedBy($t)->dividedBy(100, self::SCALE, RoundingMode::HalfUp)->minus($item['value']));
            }
            foreach (self::split($amount, $itemDeficits) as $key => $part) {
                $lines[] = self::line('buy', $category, $classes[$category]['items'][$key], null, $part);
            }
        }
        return array_values(array_filter($lines, static fn (array $l) => BigDecimal::of($l['amount'])->isPositive()));
    }

    /**
     * Plný rebalancing na cíl: nákupy a prodeje po instrumentech (třída bez cílů instrumentů → nákup za třídu,
     * prodej poměrně podle hodnoty). Prodeje s odhadem zdanitelných kusů (FIFO, loty mladší 3 let).
     *
     * @param array<string,array<string,mixed>> $classes
     * @param array<string,array<string,mixed>> $classTargets
     * @param array<string,array<int,BigDecimal>> $instrumentTargets
     * @return list<array<string,mixed>>
     */
    private function rebalance(int $userId, array $classes, array $classTargets, array $instrumentTargets, BigDecimal $total, \DateTimeImmutable $today): array
    {
        $lines = [];
        $lots = null;
        foreach ($classes as $category => $c) {
            $target = isset($classTargets[$category]) ? BigDecimal::of($classTargets[$category]['target_pct']) : BigDecimal::zero();
            $classTarget = $total->multipliedBy($target)->dividedBy(100, self::SCALE, RoundingMode::HalfUp);
            $diffs = [];  // klíč položky (null = celá třída) → +nákup / −prodej
            if (isset($instrumentTargets[$category])) {
                foreach ($c['items'] as $key => $item) {
                    $t = $instrumentTargets[$category][$item['instrument_id']] ?? BigDecimal::zero();
                    $diffs[$key] = $classTarget->multipliedBy($t)->dividedBy(100, self::SCALE, RoundingMode::HalfUp)->minus($item['value']);
                }
            } else {
                $diff = $classTarget->minus($c['value']);
                if ($diff->isPositive()) {
                    $diffs[''] = $diff;
                } elseif ($diff->isNegative()) {
                    foreach ($c['items'] as $key => $item) {
                        $diffs[$key] = $diff->multipliedBy($item['value'])->dividedBy($c['value'], self::SCALE, RoundingMode::HalfUp);
                    }
                }
            }
            foreach ($diffs as $key => $diff) {
                $item = $key === '' ? null : $c['items'][$key];
                if ($diff->abs()->isLessThan('0.01')) {
                    continue;
                }
                if ($diff->isPositive()) {
                    $lines[] = self::line('buy', $category, $item, null, $diff);
                    continue;
                }
                $lots ??= $this->openLots($userId);
                $lines[] = self::line('sell', $category, $item, $this->sellPositions((array) $item, $diff->negated(), $lots, $today), $diff->negated());
            }
        }
        // Nejdřív prodeje (z nich jsou peníze na nákupy), uvnitř od největší částky
        usort($lines, static fn (array $a, array $b) => ($a['action'] === 'buy') <=> ($b['action'] === 'buy')
            ?: BigDecimal::of($b['amount'])->compareTo($a['amount']));
        return $lines;
    }

    /**
     * Prodej položky rozdělený na pozice poměrně podle hodnoty; u tržních pozic kusy a zdanitelná část (FIFO).
     *
     * @param array<string,mixed> $item
     * @param array<int,list<array<string,mixed>>> $lots
     * @return list<array<string,mixed>>
     */
    private function sellPositions(array $item, BigDecimal $amount, array $lots, \DateTimeImmutable $today): array
    {
        $item += ['value' => BigDecimal::zero(), 'positions' => []];
        if (!$item['value']->isPositive()) {
            return [];
        }
        $saleDate = $today->format('Y-m-d');
        $out = [];
        foreach ($item['positions'] as $p) {
            $value = BigDecimal::of($p['value']);
            if (!$value->isPositive()) {
                continue;
            }
            $part = $amount->multipliedBy($value)->dividedBy($item['value'], self::SCALE, RoundingMode::HalfUp);
            $row = ['position_id' => $p['position_id'], 'label' => $p['display_name'] . ' · ' . $p['institution_name'] . ' / ' . $p['account_name'],
                'amount' => self::out($part), 'quantity' => null, 'taxable_quantity' => null];
            if ($p['valuation_mode'] === 'market' && $p['quantity'] !== null && BigDecimal::of($p['quantity'])->isPositive()) {
                $quantity = BigDecimal::of($p['quantity'])->multipliedBy($part)->dividedBy($value, 18, RoundingMode::Down);
                $row['quantity'] = (string) $quantity->strippedOfTrailingZeros();
                if (in_array($p['category'], self::TAXED, true)) {
                    $taxable = BigDecimal::zero();
                    $left = $quantity;
                    foreach ($lots[$p['position_id']] ?? [] as $lot) {
                        if (!$left->isPositive()) {
                            break;
                        }
                        $take = BigDecimal::of($lot['remaining_quantity'])->isLessThan($left) ? BigDecimal::of($lot['remaining_quantity']) : $left;
                        // stejné pravidlo jako daňový report (časový test po dnech, osvobození krypta od 15. 2. 2025)
                        $class = $p['category'] === 'crypto' ? CzTaxRules::CRYPTO : CzTaxRules::SECURITIES;
                        if (!CzTaxRules::exemptionApplies($class, $saleDate) || !CzTaxRules::heldOverThreeYears((string) $lot['acquired_at'], $saleDate)) {
                            $taxable = $taxable->plus($take);
                        }
                        $left = $left->minus($take);
                    }
                    $row['taxable_quantity'] = (string) $taxable->strippedOfTrailingZeros();
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<int,list<array<string,mixed>>> otevřené loty po pozicích (FIFO pořadí) */
    private function openLots(int $userId): array
    {
        $out = [];
        foreach ($this->lots->lots($userId) as $lot) {
            if (BigDecimal::of((string) $lot['remaining_quantity'])->isPositive()) {
                $out[$lot['position_id']][] = $lot;
            }
        }
        return $out;
    }

    /**
     * Rozdělí částku poměrně podle vah (schodků). Bez kladné váhy nic.
     *
     * @param array<string,BigDecimal> $weights
     * @return array<string,BigDecimal>
     */
    private static function split(BigDecimal $amount, array $weights): array
    {
        $sum = array_reduce($weights, static fn (BigDecimal $c, BigDecimal $w) => $c->plus($w), BigDecimal::zero());
        if (!$sum->isPositive()) {
            return [];
        }
        $out = [];
        foreach ($weights as $key => $w) {
            if ($w->isPositive()) {
                $out[$key] = $amount->multipliedBy($w)->dividedBy($sum, self::SCALE, RoundingMode::HalfUp);
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $item
     * @param list<array<string,mixed>>|null $positions
     * @return array<string,mixed>
     */
    private static function line(string $action, string $category, ?array $item, ?array $positions, BigDecimal $amount): array
    {
        return ['action' => $action, 'category' => $category, 'instrument_id' => $item['instrument_id'] ?? null,
            'label' => $item['label'] ?? null, 'amount' => self::out($amount)] + ($positions !== null ? ['positions' => $positions] : []);
    }

    private static function share(BigDecimal $part, BigDecimal $whole): BigDecimal
    {
        return $whole->isPositive() ? $part->multipliedBy(100)->dividedBy($whole, self::SCALE, RoundingMode::HalfUp) : BigDecimal::zero();
    }

    private static function positive(BigDecimal $v): BigDecimal
    {
        return $v->isPositive() ? $v : BigDecimal::zero();
    }

    private static function order(string $category): int
    {
        $i = array_search($category, self::CATEGORIES, true);
        return $i === false ? 99 : $i;
    }

    private static function out(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }
}
