<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Fx;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\FxRateRepository;
use MyPortfolio\Repository\TransactionFxRepository;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Support\Decimal;
use Psr\Clock\ClockInterface;

/**
 * Kurzy měn se základem CZK (ČNB). Kurz k datu = poslední platný lístek nejvýše
 * MAX_AGE_DAYS dní starý (víkendy, svátky); jinak FxRateMissing — nikdy 1.
 */
final class FxService
{
    public const BASE = 'CZK';
    private const MAX_AGE_DAYS = 7;
    private const YEAR_FILE_THRESHOLD = 5;

    /** @var array<string,BigDecimal|false> "měna|datum" → kurz (false = chybí); oceňování se ptá opakovaně */
    private array $cache = [];

    public function __construct(
        private readonly CnbFxProvider $cnb,
        private readonly FxRateRepository $rates,
        private readonly TransactionFxRepository $transactions,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Stáhne a uloží kurzy pro pracovní dny v rozsahu, které ještě v DB chybí.
     * Víc než YEAR_FILE_THRESHOLD chybějících dní v roce → roční soubor (1 požadavek).
     *
     * @return array{fetched:int,skipped:int,errors:list<string>}
     */
    public function sync(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $report = ['fetched' => 0, 'skipped' => 0, 'errors' => []];
        $tz = new \DateTimeZone(date_default_timezone_get());
        $missingByYear = [];
        for ($day = $from->setTimezone($tz)->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') >= 6 || $this->rates->hasDate($day->format('Y-m-d'))) {
                $report['skipped']++;
                continue;
            }
            $missingByYear[(int) $day->format('Y')][] = $day;
        }

        foreach ($missingByYear as $year => $days) {
            if (count($days) > self::YEAR_FILE_THRESHOLD) {
                try {
                    $wanted = array_flip(array_map(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $days));
                    foreach ($this->cnb->fetchYear($year) as $sheet) {
                        if (isset($wanted[$sheet['date']])) {
                            $this->store($sheet);
                            $report['fetched']++;
                        }
                    }
                    continue;
                } catch (MarketDataException $e) {
                    $report['errors'][] = $year . ': ' . $e->getMessage();
                    // pokračuj po dnech
                }
            }
            foreach ($days as $day) {
                try {
                    $this->store($this->cnb->fetch($day));
                    $report['fetched']++;
                } catch (MarketDataException $e) {
                    $report['errors'][] = $day->format('Y-m-d') . ': ' . $e->getMessage();
                }
            }
        }
        return $report;
    }

    /** @param array{date:string,rates:list<array{currency:string,amount:int,rate:string}>} $sheet */
    private function store(array $sheet): void
    {
        $this->cache = [];
        foreach ($sheet['rates'] as $r) {
            $this->rates->upsert($sheet['date'], $r['currency'], $r['amount'], $r['rate']);
        }
    }

    /** Kč za 1 jednotku měny k datu. */
    public function rateToCzk(string $currency, string $date): BigDecimal
    {
        if ($currency === self::BASE) {
            return BigDecimal::one();
        }
        $key = $currency . '|' . $date;
        if (!isset($this->cache[$key])) {
            $notBefore = (new \DateTimeImmutable($date))->modify('-' . self::MAX_AGE_DAYS . ' days')->format('Y-m-d');
            $row = $this->rates->rateOnOrBefore($currency, $date, $notBefore);
            $this->cache[$key] = $row === null
                ? false
                : BigDecimal::of($row['rate'])->dividedBy($row['amount'], Decimal::SCALE_PRICE, RoundingMode::HalfUp);
        }
        return $this->cache[$key] ?: throw new FxRateMissing($currency, $date);
    }

    /** Měna z kurzového lístku ČNB (CZK vždy) — na rozdíl od kryptoaktiv jde přepočítat. */
    public function isCnbCurrency(string $currency): bool
    {
        return strtoupper($currency) === self::BASE || $this->rates->knows($currency);
    }

    public function convert(BigDecimal $amount, string $from, string $to, string $date): BigDecimal
    {
        if ($from === $to) {
            return $amount;
        }
        return $amount
            ->multipliedBy($this->rateToCzk($from, $date))
            ->dividedBy($this->rateToCzk($to, $date), Decimal::SCALE_PRICE, RoundingMode::HalfUp);
    }

    /**
     * Doplní `fx_rate_czk` transakcím, které ho ještě nemají (kurz ke dni transakce).
     *
     * @return array{filled:int,missing:int}
     */
    public function fillTransactionRates(int $limit = 5000): array
    {
        $filled = $missing = 0;
        foreach ($this->transactions->missingRates($limit) as $tx) {
            try {
                $rate = $this->rateToCzk($tx['price_currency'], $tx['date']);
            } catch (FxRateMissing) {
                $missing++;
                continue;
            }
            $this->transactions->setRate($tx['id'], Decimal::toDb($rate, Decimal::SCALE_PRICE) ?? '0');
            $filled++;
        }
        return ['filled' => $filled, 'missing' => $missing];
    }

    /** Od kdy stahovat kurzy: poslední uložený den, jinak první transakce, jinak dnes. */
    public function syncStart(): \DateTimeImmutable
    {
        $latest = $this->rates->latestDate();
        if ($latest !== null) {
            return (new \DateTimeImmutable($latest))->modify('+1 day');
        }
        $earliest = $this->transactions->earliestTransactionDate();
        return new \DateTimeImmutable($earliest ?? $this->clock->now()->format('Y-m-d'));
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
    }
}
