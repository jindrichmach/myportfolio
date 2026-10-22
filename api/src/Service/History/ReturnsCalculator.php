<?php

declare(strict_types=1);

namespace MyPortfolio\Service\History;

/**
 * Výnosové metriky z denní řady (hodnota + čistý vnější tok).
 *
 * Metriky jsou poměry, ne peníze — počítají se ve float ze stringů DECIMAL
 * (XIRR potřebuje neceločíselné mocniny, které BigDecimal neumí).
 *
 *   TWR    — denní výnosy řetězené; kladný tok se počítá na začátku dne, záporný na konci
 *   XIRR   — roční míra, při které je NPV toků (−počáteční hodnota, −toky, +koncová hodnota) nula
 *   simple — (konec − začátek − toky) / (začátek + vklady)
 */
final class ReturnsCalculator
{
    /**
     * @param array{date:string,value:?string}|null $baseline stav den před začátkem období (null = od nuly)
     * @param list<array{date:string,value:?string,flow:string}> $points jen úplné body (value !== null) se počítají
     * @return array{twr:?float,twr_annualized:?float,xirr:?float,simple:?float,gain:?float,net_flow:float} gain/net_flow jen orientačně (float)
     */
    public function metrics(?array $baseline, array $points): array
    {
        $startValue = $baseline !== null && $baseline['value'] !== null ? (float) $baseline['value'] : 0.0;
        $startDate = $baseline['date'] ?? ($points[0]['date'] ?? null);

        $prev = $startValue;
        $growth = 1.0;
        $pending = 0.0;
        $netFlow = 0.0;
        $deposits = 0.0;
        $cashflows = $startValue > 0 && $startDate !== null ? [[$startDate, -$startValue]] : [];
        $end = null;
        foreach ($points as $p) {
            $flow = (float) $p['flow'] + $pending;
            if ($p['value'] === null) {
                $pending = $flow; // tok nepřiřaditelný k úplnému dni se přenese
                continue;
            }
            $pending = 0.0;
            $value = (float) $p['value'];
            $in = max($flow, 0.0);
            $out = min($flow, 0.0);
            $denominator = $prev + $in;
            if ($denominator > 0) {
                $growth *= ($value - $out) / $denominator;
            }
            $prev = $value;
            $netFlow += $flow;
            $deposits += $in;
            if ($flow != 0.0) {
                $cashflows[] = [$p['date'], -$flow];
            }
            $end = $p;
        }

        if ($end === null) {
            return ['twr' => null, 'twr_annualized' => null, 'xirr' => null, 'simple' => null, 'gain' => null, 'net_flow' => $netFlow];
        }
        $endValue = (float) $end['value'];
        $cashflows[] = [$end['date'], $endValue];

        $twr = $growth - 1;
        $days = $startDate !== null ? (int) (new \DateTimeImmutable($startDate))->diff(new \DateTimeImmutable($end['date']))->days : 0;
        $gain = $endValue - $startValue - $netFlow;
        $base = $startValue + $deposits;

        return [
            'twr'            => $twr,
            'twr_annualized' => $days >= 365 && $growth > 0 ? $growth ** (365 / $days) - 1 : null,
            'xirr'           => $this->xirr($cashflows),
            'simple'         => $base > 0 ? $gain / $base : null,
            'gain'           => $gain,
            'net_flow'       => $netFlow,
        ];
    }

    /**
     * Reálné (po inflaci) metriky: (1 + nominální) / (1 + inflace) − 1. Inflace je změřená za `$inflationMonths`
     * měsíců (index za poslední měsíce ještě nemusí být zveřejněný) — převede se na roční míru a ta na délku
     * výnosového období `$days`, aby se srovnávala stejně dlouhá období. Roční metriky (TWR p. a., XIRR) se dělí
     * roční inflací.
     *
     * @param array{twr:?float,twr_annualized:?float,xirr:?float} $m
     * @return array{twr:?float,twr_annualized:?float,xirr:?float}
     */
    public function real(array $m, float $inflation, int $inflationMonths, int $days): array
    {
        $real = static fn (?float $nominal, ?float $infl) => $nominal === null || $infl === null || $infl <= -1 ? null : (1 + $nominal) / (1 + $infl) - 1;
        $annual = $inflationMonths > 0 && $inflation > -1 ? (1 + $inflation) ** (12 / $inflationMonths) - 1 : null;
        $period = $annual !== null && $days > 0 ? (1 + $annual) ** ($days / 365) - 1 : null;
        return [
            'twr'            => $real($m['twr'], $period),
            'twr_annualized' => $real($m['twr_annualized'], $annual),
            'xirr'           => $real($m['xirr'], $annual),
        ];
    }

    /**
     * @param list<array{0:string,1:float}> $cashflows [datum, částka] — investor: vklad záporný
     */
    public function xirr(array $cashflows): ?float
    {
        if (count($cashflows) < 2) {
            return null;
        }
        $t0 = new \DateTimeImmutable($cashflows[0][0]);
        $flows = [];
        $hasPos = $hasNeg = false;
        foreach ($cashflows as [$date, $amount]) {
            $flows[] = [(int) $t0->diff(new \DateTimeImmutable($date))->format('%r%a') / 365, $amount];
            $hasPos = $hasPos || $amount > 0;
            $hasNeg = $hasNeg || $amount < 0;
        }
        if (!$hasPos || !$hasNeg) {
            return null;
        }
        $npv = static function (float $r) use ($flows): float {
            $sum = 0.0;
            foreach ($flows as [$t, $a]) {
                $sum += $a / (1 + $r) ** $t;
            }
            return $sum;
        };

        // Newton
        $r = 0.1;
        for ($i = 0; $i < 50; $i++) {
            $f = $npv($r);
            $d = 0.0;
            foreach ($flows as [$t, $a]) {
                $d -= $t * $a / (1 + $r) ** ($t + 1);
            }
            if ($d == 0.0) {
                break;
            }
            $next = $r - $f / $d;
            if (!is_finite($next) || $next <= -0.9999) {
                break;
            }
            if (abs($next - $r) < 1e-10) {
                return $next;
            }
            $r = $next;
        }

        // Bisekce jako záloha
        [$lo, $hi] = [-0.9999, 100.0];
        $fLo = $npv($lo);
        $fHi = $npv($hi);
        if ($fLo * $fHi > 0) {
            return null;
        }
        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2;
            $fMid = $npv($mid);
            if (abs($fMid) < 1e-9 || ($hi - $lo) < 1e-12) {
                return $mid;
            }
            if ($fLo * $fMid < 0) {
                [$hi, $fHi] = [$mid, $fMid];
            } else {
                [$lo, $fLo] = [$mid, $fMid];
            }
        }
        return ($lo + $hi) / 2;
    }
}
