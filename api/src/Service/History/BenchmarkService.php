<?php

declare(strict_types=1);

namespace MyPortfolio\Service\History;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\PriceRepository;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Service\Fx\FxService;

/**
 * Benchmark v grafu historie: „kdyby stejné peníze šly do instrumentu X". Hodnota na začátku období se
 * „nakoupí" za cenu benchmarku, každý vnější tok (vklad / výběr) ve stejný den za tehdejší cenu — vše
 * v měně zobrazení (cena × kurz ČNB k danému dni). Cena chybí → poslední známá (nejvýš 14 dní); déle bez ceny
 * (i uprostřed období) benchmark nedostupný — nikdy tichá náhrada. Výběr prodá nejvýš držené kusy.
 */
final class BenchmarkService
{
    private const LOOKBACK_DAYS = 14;
    private const SCALE = 18;

    public function __construct(
        private readonly PriceRepository $prices,
        private readonly FxService $fx,
    ) {}

    /**
     * @param array{date:string,value:?string}|null $baseline stav den před začátkem období
     * @param list<array{date:string,flow:string}> $rows denní toky období
     * @return array{values:array<string,string>,start_price:string,end_price:string,start_value:string,end_value:string,net_flow:string}|null
     */
    public function simulate(int $instrumentId, string $currency, ?array $baseline, array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }
        $start = $baseline['date'] ?? $rows[0]['date'];
        $end = $rows[array_key_last($rows)]['date'];
        $from = (new \DateTimeImmutable($start))->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d');
        $quotes = $this->prices->history($instrumentId, $from, $end);
        if ($quotes === []) {
            return null;
        }

        $i = 0;
        $last = null;  // poslední cena ≤ den: [datum, cena v měně zobrazení]
        $priceAt = function (string $day) use ($quotes, &$i, &$last, $currency): ?BigDecimal {
            while ($i < count($quotes) && $quotes[$i]['date'] <= $day) {
                try {
                    $last = [$quotes[$i]['date'], $this->fx->convert(BigDecimal::of($quotes[$i]['close']), $quotes[$i]['currency'], $currency, $quotes[$i]['date'])];
                } catch (FxRateMissing) {
                    // den bez kurzu přeskočit, zůstane předchozí cena
                }
                $i++;
            }
            if ($last === null || (new \DateTimeImmutable($last[0]))->diff(new \DateTimeImmutable($day))->days > self::LOOKBACK_DAYS) {
                return null;
            }
            return $last[1];
        };

        $startValue = $baseline !== null && $baseline['value'] !== null ? BigDecimal::of($baseline['value']) : BigDecimal::zero();
        $startPrice = $priceAt($start);
        if ($startPrice === null && $startValue->isPositive()) {
            return null;
        }
        $units = $startPrice !== null && $startValue->isPositive()
            ? $startValue->dividedBy($startPrice, self::SCALE, RoundingMode::HalfUp) : BigDecimal::zero();
        $netFlow = BigDecimal::zero();
        $values = [];
        $price = $startPrice;
        foreach ($rows as $r) {
            $today = $priceAt($r['date']);
            $flow = BigDecimal::of($r['flow']);
            if ($today === null) {
                if ($startPrice !== null || !$flow->isZero()) {
                    return null;  // cena chybí déle než LOOKBACK_DAYS, nebo tok před první cenou — nejde simulovat
                }
                continue;
            }
            $price = $today;
            $startPrice ??= $price;
            if (!$flow->isZero()) {
                // Výběr prodá nejvýš držené kusy (žádný short) — tok se počítá ve skutečné výši
                $delta = $flow->dividedBy($price, self::SCALE, RoundingMode::HalfUp);
                if ($units->plus($delta)->isNegative()) {
                    $delta = $units->negated();
                    $flow = $delta->multipliedBy($price);
                }
                $units = $units->plus($delta);
                $netFlow = $netFlow->plus($flow);
            }
            $values[$r['date']] = (string) $units->multipliedBy($price)->toScale(2, RoundingMode::HalfUp);
        }
        if ($price === null || $startPrice === null) {
            return null;
        }
        return [
            'values'      => $values,
            'start_price' => (string) $startPrice,
            'end_price'   => (string) $price,
            'start_value' => (string) $startValue->toScale(2, RoundingMode::HalfUp),
            'end_value'   => (string) $units->multipliedBy($price)->toScale(2, RoundingMode::HalfUp),
            'net_flow'    => (string) $netFlow->toScale(2, RoundingMode::HalfUp),
        ];
    }
}
