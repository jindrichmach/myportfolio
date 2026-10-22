<?php

declare(strict_types=1);

namespace MyPortfolio\Service\NetWorth;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Zůstatek anuitního úvěru k datu. Kotvou je poslední záznam (počátek úvěru nebo korekce po mimořádné
 * splátce / refixaci) se zůstatkem, úrokem p. a. a měsíční splátkou; splátky jdou měsíčně ke dni kotvy
 * (31. → poslední den kratšího měsíce). Splátka = úrok (zůstatek × úrok / 12, na haléře) + jistina;
 * zůstatek neklesne pod nulu.
 */
final class LoanSchedule
{
    private const MAX_MONTHS = 1200;

    /**
     * @param list<array{date:string,value:string,rate_pct:?string,payment:?string}> $entries seřazené podle data
     */
    public function __construct(private readonly array $entries) {}

    /** Zůstatek k datu; před prvním záznamem null (úvěr ještě nebyl). */
    public function balanceOn(string $date): ?BigDecimal
    {
        return $this->balances([$date])[$date];
    }

    /**
     * Zůstatky pro vzestupně seřazené dny jedním průchodem (graf historie).
     *
     * @param list<string> $dates
     * @return array<string,?BigDecimal>
     */
    public function balances(array $dates): array
    {
        $out = [];
        $i = -1;
        $balance = null;
        $anchorDate = '';
        $k = 0;
        $rate = BigDecimal::zero();
        $payment = BigDecimal::zero();
        foreach ($dates as $date) {
            while (isset($this->entries[$i + 1]) && $this->entries[$i + 1]['date'] <= $date) {
                $e = $this->entries[++$i];
                $rate = $e['rate_pct'] !== null ? BigDecimal::of($e['rate_pct']) : $rate;
                $payment = $e['payment'] !== null ? BigDecimal::of($e['payment']) : $payment;
                $balance = BigDecimal::of($e['value']);
                $anchorDate = $e['date'];
                $k = 0;
            }
            while ($balance !== null && $balance->isPositive() && $k < self::MAX_MONTHS && self::paymentDate($anchorDate, $k + 1) <= $date) {
                $balance = self::pay($balance, $rate, $payment);
                $k++;
            }
            $out[$date] = $balance;
        }
        return $out;
    }

    /**
     * Odhad doplacení od posledního záznamu: datum poslední splátky a počet zbývajících splátek k `$today`.
     *
     * @return array{date:string,payments_left:int}|null null = splátka nepokryje úrok (úvěr neklesá)
     */
    public function payoff(string $today): ?array
    {
        $anchor = $this->anchor('9999-12-31');
        if ($anchor === null) {
            return null;
        }
        $balance = BigDecimal::of($anchor['value']);
        $left = 0;
        for ($k = 1; $k <= self::MAX_MONTHS; $k++) {
            if (!$balance->isPositive()) {
                return ['date' => self::paymentDate($anchor['date'], $k - 1), 'payments_left' => $left];
            }
            $next = self::pay($balance, $anchor['rate'], $anchor['payment']);
            if (!$next->isLessThan($balance)) {
                return null;
            }
            $balance = $next;
            $left += self::paymentDate($anchor['date'], $k) > $today ? 1 : 0;
        }
        return null;
    }

    /** Platné parametry k datu: zůstatek z posledního záznamu, úrok a splátka z posledního, který je uvádí. */
    public function anchorOn(string $date): ?array
    {
        return $this->anchor($date);
    }

    /** @return array{date:string,value:string,rate:BigDecimal,payment:BigDecimal}|null */
    private function anchor(string $date): ?array
    {
        $out = null;
        $rate = BigDecimal::zero();
        $payment = BigDecimal::zero();
        foreach ($this->entries as $e) {
            if ($e['date'] > $date) {
                break;
            }
            $rate = $e['rate_pct'] !== null ? BigDecimal::of($e['rate_pct']) : $rate;
            $payment = $e['payment'] !== null ? BigDecimal::of($e['payment']) : $payment;
            $out = ['date' => $e['date'], 'value' => $e['value'], 'rate' => $rate, 'payment' => $payment];
        }
        return $out;
    }

    private static function pay(BigDecimal $balance, BigDecimal $ratePct, BigDecimal $payment): BigDecimal
    {
        $interest = $balance->multipliedBy($ratePct)->dividedBy(1200, 2, RoundingMode::HalfUp);
        $next = $balance->plus($interest)->minus($payment);
        return $next->isNegative() ? BigDecimal::zero() : $next;
    }

    /** k-tá měsíční splátka od data kotvy (den v měsíci zachován, u kratšího měsíce poslední den). */
    private static function paymentDate(string $anchor, int $k): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $anchor));
        $months = $y * 12 + ($m - 1) + $k;
        $year = intdiv($months, 12);
        $month = $months % 12 + 1;
        $last = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        return sprintf('%04d-%02d-%02d', $year, $month, min($d, $last));
    }
}
