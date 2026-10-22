<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\FxRateRepository;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Support\Decimal;

/**
 * Jednotný kurz (§ 38 ZDP) jako GFŘ: průměr kurzů ČNB k poslednímu dni každého měsíce roku,
 * zaokrouhlený na 2 desetinná místa (za kurzovní množství ČNB, např. 100 JPY).
 */
final class UnifiedRates
{
    /** @var array<string,BigDecimal> */
    private array $cache = [];

    public function __construct(private readonly FxRateRepository $rates) {}

    /** Kč za 1 jednotku měny pro rok; chybí-li některý měsíc (rok neskončil), FxRateMissing. */
    public function rateToCzk(string $currency, int $year): BigDecimal
    {
        if ($currency === 'CZK') {
            return BigDecimal::one();
        }
        $key = "$currency|$year";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $sum = BigDecimal::zero();
        $amount = 1;
        for ($m = 1; $m <= 12; $m++) {
            $end = (new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $m)))->modify('last day of this month')->format('Y-m-d');
            $notBefore = (new \DateTimeImmutable($end))->modify('-10 days')->format('Y-m-d');
            $row = $this->rates->rateOnOrBefore($currency, $end, $notBefore) ?? throw new FxRateMissing($currency, $end);
            $amount = $row['amount'];
            $sum = $sum->plus($row['rate']);
        }
        return $this->cache[$key] = $sum->dividedBy(12, 2, RoundingMode::HalfUp)
            ->dividedBy($amount, Decimal::SCALE_PRICE, RoundingMode::HalfUp);
    }

    public function toCzk(BigDecimal $amount, string $currency, string $date): BigDecimal
    {
        return $amount->multipliedBy($this->rateToCzk($currency, (int) substr($date, 0, 4)));
    }
}
