<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\TaxRepository;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Support\Decimal;

/**
 * Daňový report za kalendářní rok: prodeje CP a kryptoaktiv
 * po FIFO lotech s osvobozením, zahraniční dividendy po zemích, úroky. Vše v CZK denním kurzem ČNB,
 * nebo jednotným kurzem (nákup kurzem roku nákupu). Zaokrouhlení na haléře až na výstupu.
 */
final class TaxReportService
{
    public const RATE_DAILY = 'daily';
    public const RATE_UNIFIED = 'unified';
    private const SECURITY_CLASSES = ['etf', 'stock', 'fund'];

    /** @var array<string,true> měna|rok jednotných kurzů použitých v posledním build() */
    private array $usedRates = [];

    public function __construct(
        private readonly TaxRepository $repo,
        private readonly FxService $fx,
        private readonly UnifiedRates $unified,
    ) {}

    /** @return list<int> */
    public function years(int $userId): array
    {
        return $this->repo->years($userId);
    }

    /** @return array<string,mixed> */
    public function build(int $userId, int $year, string $rateMethod): array
    {
        if (!in_array($rateMethod, [self::RATE_DAILY, self::RATE_UNIFIED], true)) {
            throw DomainError::validation(['rate' => ['Neznámý způsob přepočtu.']]);
        }
        $this->usedRates = [];
        try {
            $lots = $this->lotRows($userId, $year, $rateMethod);
            $sections = [];
            foreach ([CzTaxRules::SECURITIES, CzTaxRules::CRYPTO] as $class) {
                $sections[$class] = $this->classify($class, $year, array_values(array_filter($lots, static fn (array $r) => $r['class'] === $class)));
            }
            $capped = $this->applyCap($year, $sections);
            [$dividends, $interest] = $this->income($userId, $year, $rateMethod);
        } catch (FxRateMissing $e) {
            throw new DomainError('fx_missing', $rateMethod === self::RATE_UNIFIED
                ? "Jednotný kurz {$e->currency} pro rok " . substr($e->date, 0, 4) . ' nejde spočítat — chybí kurz ČNB k ' . $e->date
                    . '. Rok musí být uzavřený; starší kurzy doplní správce: php api/bin/cron-fx.php --from=' . substr($e->date, 0, 4) . '-01-01'
                : $e->getMessage() . ' Doplň kurzy ČNB (Administrace → Ceny a kurzy).', 422, ['currency' => $e->currency, 'date' => $e->date]);
        }

        $out = ['year' => $year, 'rate' => $rateMethod, 'cap_exceeded' => $capped];
        foreach ($sections as $class => $s) {
            $out[$class] = self::presentSection($s);
        }
        $out['dividends'] = $dividends;
        $out['interest'] = $interest;
        if ($rateMethod === self::RATE_UNIFIED) {
            $out['unified_rates'] = $this->usedUnifiedRates();
        }
        return $out;
    }

    /**
     * Prodeje roku rozpadlé na loty: příjem (podíl hrubé ceny), výdaj (nabývací cena lotu + podíl poplatku), v CZK.
     *
     * @return list<array<string,mixed>>
     */
    private function lotRows(int $userId, int $year, string $method): array
    {
        $byTx = [];
        foreach ($this->repo->sales($userId, $year) as $r) {
            $byTx[$r['transaction_id']][] = $r;
        }
        $rows = [];
        foreach ($byTx as $parts) {
            $tx = $parts[0];
            $class = $tx['asset_class'] === 'crypto' ? CzTaxRules::CRYPTO
                : (in_array($tx['asset_class'], self::SECURITY_CLASSES, true) ? CzTaxRules::SECURITIES : null);
            if ($class === null || $tx['amount'] === null || $tx['price_currency'] === null) {
                continue;
            }
            $sold = BigDecimal::of((string) $tx['sold_quantity']);
            $gross = $this->czk(BigDecimal::of((string) $tx['amount']), (string) $tx['price_currency'], $tx['executed_at'], $method);
            // Poplatek v měně ČNB → výdaj; v kusech prodávaného aktiva → úbytky kind=fee níž; v jiném kryptu (BNB…)
            // ho kurz ČNB neocení → vynechá se s varováním na řádku (report kvůli němu nespadne)
            $feeCurrency = $tx['fee'] !== null && $tx['fee_currency'] !== null && $tx['fee_currency'] !== $tx['symbol'] ? (string) $tx['fee_currency'] : null;
            $feeSkipped = $feeCurrency !== null && !$this->fx->isCnbCurrency($feeCurrency) ? $feeCurrency : null;
            $fee = $feeCurrency !== null && $feeSkipped === null
                ? $this->czk(BigDecimal::of((string) $tx['fee']), $feeCurrency, $tx['executed_at'], $method)
                : BigDecimal::zero();

            $matched = BigDecimal::zero();
            foreach ($parts as $p) {
                $qty = BigDecimal::of((string) $p['quantity']);
                $cost = $this->czk(BigDecimal::of((string) $p['cost_amount']), (string) $p['cost_currency'], (string) $p['acquired_at'], $method);
                if ($p['kind'] === 'fee') {
                    // Kusy spotřebované na poplatek: nabývací cena je výdaj prodeje (příjem 0), osvobození podle jejich lotu
                    $rows[] = ['class' => $class, 'transaction_id' => $tx['transaction_id'], 'disposed_at' => $tx['executed_at'], 'acquired_at' => $p['acquired_at'],
                        'symbol' => $tx['symbol'], 'name' => $tx['instrument_name'], 'isin' => $tx['isin'],
                        'account' => $tx['institution_name'] . ' · ' . $tx['account_name'], 'currency' => $tx['price_currency'],
                        'quantity' => $qty, 'income' => BigDecimal::zero(), 'expense' => $cost, 'fee_lot' => true, 'fee_skipped' => null];
                    continue;
                }
                $matched = $matched->plus($qty);
                $rows[] = $this->lotRow($tx, $class, $qty, $sold, $gross, $fee, $p['acquired_at'], $cost) + ['fee_lot' => false, 'fee_skipped' => $feeSkipped];
            }
            // Část prodeje bez nákupu (chybějící historie) — zdanitelná s nulovým výdajem
            $missing = $sold->minus($matched);
            if ($missing->isPositive()) {
                $rows[] = $this->lotRow($tx, $class, $missing, $sold, $gross, $fee, null, BigDecimal::zero()) + ['fee_lot' => false, 'fee_skipped' => $feeSkipped];
            }
        }
        return $rows;
    }

    /** @param array<string,mixed> $tx */
    private function lotRow(array $tx, string $class, BigDecimal $qty, BigDecimal $sold, BigDecimal $gross, BigDecimal $fee,
        ?string $acquiredAt, BigDecimal $cost): array
    {
        $share = static fn (BigDecimal $v) => $sold->isZero() ? BigDecimal::zero()
            : $v->multipliedBy($qty)->dividedBy($sold, Decimal::SCALE_PRICE, RoundingMode::HalfUp);
        return [
            'class'          => $class,
            'transaction_id' => $tx['transaction_id'],
            'disposed_at'    => $tx['executed_at'],
            'acquired_at'    => $acquiredAt,
            'symbol'         => $tx['symbol'],
            'name'           => $tx['instrument_name'],
            'isin'           => $tx['isin'],
            'account'        => $tx['institution_name'] . ' · ' . $tx['account_name'],
            'currency'       => $tx['price_currency'],
            'quantity'       => $qty,
            'income'         => $share($gross),
            'expense'        => $cost->plus($share($fee)),
        ];
    }

    /**
     * Osvobození v rámci druhu: nejdřív limit 100 000 Kč z úhrnu hrubých příjmů, jinak časový test po lotech.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function classify(string $class, int $year, array $rows): array
    {
        $exemptionYear = $class === CzTaxRules::SECURITIES || $year >= 2025;
        $eligibleIncome = BigDecimal::zero();
        foreach ($rows as $r) {
            if ($exemptionYear && CzTaxRules::exemptionApplies($class, $r['disposed_at'])) {
                $eligibleIncome = $eligibleIncome->plus($r['income']);
            }
        }
        $underLimit = $eligibleIncome->isPositive() && $eligibleIncome->isLessThanOrEqualTo(CzTaxRules::INCOME_LIMIT);

        foreach ($rows as &$r) {
            $eligible = $exemptionYear && CzTaxRules::exemptionApplies($class, $r['disposed_at']);
            $r['exemption'] = match (true) {
                !$eligible                => $class === CzTaxRules::CRYPTO && $year === 2025 ? 'before_crypto_exemption' : 'none',
                $underLimit               => 'limit',
                $r['acquired_at'] !== null && CzTaxRules::heldOverThreeYears($r['acquired_at'], $r['disposed_at']) => 'time_test',
                default                   => 'none',
            };
            $r['taxable_share'] = $r['exemption'] === 'limit' || $r['exemption'] === 'time_test' ? BigDecimal::zero() : BigDecimal::one();
        }
        unset($r);
        return ['rows' => $rows, 'eligible_income' => $eligibleIncome, 'under_limit' => $underLimit, 'exemption_year' => $exemptionYear];
    }

    /**
     * Strop 40 mil. Kč na příjmy osvobozené časovým testem: nad stropem se osvobození poměrně krátí.
     *
     * @param array<string,array<string,mixed>> $sections
     */
    private function applyCap(int $year, array &$sections): bool
    {
        $classes = CzTaxRules::capClasses($year);
        $pool = BigDecimal::zero();
        foreach ($classes as $class) {
            foreach ($sections[$class]['rows'] as $r) {
                if ($r['exemption'] === 'time_test') {
                    $pool = $pool->plus($r['income']);
                }
            }
        }
        if ($pool->isLessThanOrEqualTo(CzTaxRules::TIME_TEST_CAP)) {
            return false;
        }
        $taxableShare = $pool->minus(CzTaxRules::TIME_TEST_CAP)->dividedBy($pool, Decimal::SCALE_PRICE, RoundingMode::HalfUp);
        foreach ($classes as $class) {
            foreach ($sections[$class]['rows'] as &$r) {
                if ($r['exemption'] === 'time_test') {
                    $r['exemption'] = 'time_test_capped';
                    $r['taxable_share'] = $taxableShare;
                }
            }
            unset($r);
        }
        return true;
    }

    /**
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private static function presentSection(array $s): array
    {
        $sum = ['income' => BigDecimal::zero(), 'expense' => BigDecimal::zero(), 'exempt_income' => BigDecimal::zero(),
            'taxable_income' => BigDecimal::zero(), 'taxable_expense' => BigDecimal::zero()];
        $rows = [];
        foreach ($s['rows'] as $r) {
            $taxIncome = $r['income']->multipliedBy($r['taxable_share']);
            $taxExpense = $r['expense']->multipliedBy($r['taxable_share']);
            $sum['income'] = $sum['income']->plus($r['income']);
            $sum['expense'] = $sum['expense']->plus($r['expense']);
            $sum['taxable_income'] = $sum['taxable_income']->plus($taxIncome);
            $sum['taxable_expense'] = $sum['taxable_expense']->plus($taxExpense);
            $sum['exempt_income'] = $sum['exempt_income']->plus($r['income']->minus($taxIncome));
            $rows[] = [
                'transaction_id' => $r['transaction_id'], 'disposed_at' => $r['disposed_at'], 'acquired_at' => $r['acquired_at'],
                'symbol' => $r['symbol'], 'name' => $r['name'], 'isin' => $r['isin'], 'account' => $r['account'], 'currency' => $r['currency'],
                'quantity' => (string) $r['quantity']->strippedOfTrailingZeros(),
                'income' => self::money($r['income']), 'expense' => self::money($r['expense']),
                'gain' => self::money($r['income']->minus($r['expense'])), 'exemption' => $r['exemption'],
                'fee_lot' => $r['fee_lot'], 'fee_skipped' => $r['fee_skipped'],
            ];
        }
        $result = $sum['taxable_income']->minus($sum['taxable_expense']);
        return [
            'eligible_income' => self::money($s['eligible_income']),
            'under_limit'     => $s['under_limit'],
            'exemption_year'  => $s['exemption_year'],
            'income'          => self::money($sum['income']),
            'expense'         => self::money($sum['expense']),
            'gain'            => self::money($sum['income']->minus($sum['expense'])),
            'exempt_income'   => self::money($sum['exempt_income']),
            'taxable_income'  => self::money($sum['taxable_income']),
            'taxable_expense' => self::money($sum['taxable_expense']),
            'result'          => self::money($result),
            // § 10/4: ztráta druhu příjmu se nepřenáší, základ nemůže být záporný
            'tax_base'        => self::money($result->isNegative() ? BigDecimal::zero() : $result),
            'rows'            => $rows,
        ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} dividendy po zemích, úroky po pozicích */
    private function income(int $userId, int $year, string $method): array
    {
        $rate = BigDecimal::of(CzTaxRules::DIVIDEND_RATE);
        $countries = [];
        $domestic = ['gross' => BigDecimal::zero(), 'withheld' => BigDecimal::zero(), 'rows' => []];
        $interest = [];
        foreach ($this->repo->income($userId, $year) as $r) {
            $amount = $this->czk(BigDecimal::of((string) $r['amount']), (string) $r['price_currency'], $r['executed_at'], $method);
            // Sražená daň je v měně ČNB; poplatek v kryptu kurz ČNB neocení → vynechá se
            $withheld = $r['fee'] !== null && $r['fee_currency'] !== null && $r['fee_currency'] !== $r['symbol']
                && $this->fx->isCnbCurrency((string) $r['fee_currency'])
                ? $this->czk(BigDecimal::of((string) $r['fee']), (string) $r['fee_currency'], $r['executed_at'], $method)
                : BigDecimal::zero();
            $row = ['date' => substr($r['executed_at'], 0, 10), 'symbol' => $r['symbol'], 'name' => $r['instrument_name'] ?? $r['position_name'],
                'isin' => $r['isin'], 'account' => $r['institution_name'] . ' · ' . $r['account_name'],
                'amount' => (string) $r['amount'], 'currency' => $r['price_currency'], 'gross_czk' => self::money($amount), 'withheld_czk' => self::money($withheld)];
            if ($r['type'] === 'interest') {
                $key = $r['position_id'];
                $interest[$key] ??= ['name' => $row['name'] ?? $row['symbol'], 'account' => $row['account'], 'total' => BigDecimal::zero(), 'withheld' => BigDecimal::zero(), 'count' => 0];
                $interest[$key]['total'] = $interest[$key]['total']->plus($amount);
                $interest[$key]['withheld'] = $interest[$key]['withheld']->plus($withheld);
                $interest[$key]['count']++;
                continue;
            }
            $country = $r['isin'] !== null && preg_match('/^[A-Z]{2}/', (string) $r['isin']) === 1 ? substr((string) $r['isin'], 0, 2) : null;
            if ($country === 'CZ') {
                $domestic['gross'] = $domestic['gross']->plus($amount);
                $domestic['withheld'] = $domestic['withheld']->plus($withheld);
                $domestic['rows'][] = $row;
                continue;
            }
            $c = $country ?? '??';
            $countries[$c] ??= ['country' => $country, 'gross' => BigDecimal::zero(), 'withheld' => BigDecimal::zero(), 'rows' => []];
            $countries[$c]['gross'] = $countries[$c]['gross']->plus($amount);
            $countries[$c]['withheld'] = $countries[$c]['withheld']->plus($withheld);
            $countries[$c]['rows'][] = $row;
        }

        $total = ['gross' => BigDecimal::zero(), 'withheld' => BigDecimal::zero(), 'creditable' => BigDecimal::zero(), 'cz_tax' => BigDecimal::zero(), 'to_pay' => BigDecimal::zero()];
        $list = [];
        ksort($countries);
        foreach ($countries as $c) {
            $czTax = $c['gross']->multipliedBy($rate);
            $creditable = $c['withheld']->isGreaterThan($czTax) ? $czTax : $c['withheld'];
            $toPay = $czTax->minus($creditable);
            foreach (['gross' => $c['gross'], 'withheld' => $c['withheld'], 'creditable' => $creditable, 'cz_tax' => $czTax, 'to_pay' => $toPay] as $k => $v) {
                $total[$k] = $total[$k]->plus($v);
            }
            $list[] = ['country' => $c['country'], 'gross' => self::money($c['gross']), 'withheld' => self::money($c['withheld']),
                'creditable' => self::money($creditable), 'cz_tax' => self::money($czTax), 'to_pay' => self::money($toPay), 'rows' => $c['rows']];
        }
        $dividends = [
            'countries' => $list,
            'total'     => array_map(self::money(...), $total),
            'domestic'  => ['gross' => self::money($domestic['gross']), 'withheld' => self::money($domestic['withheld']), 'rows' => $domestic['rows']],
        ];
        $interestOut = array_values(array_map(static fn (array $i) => ['name' => $i['name'], 'account' => $i['account'], 'count' => $i['count'],
            'total' => self::money($i['total']), 'withheld' => self::money($i['withheld'])], $interest));
        $interestTotal = array_reduce($interest, static fn (BigDecimal $s, array $i) => $s->plus($i['total']), BigDecimal::zero());
        return [$dividends, ['positions' => $interestOut, 'total' => self::money($interestTotal)]];
    }

    private function czk(BigDecimal $amount, string $currency, string $date, string $method): BigDecimal
    {
        $date = substr($date, 0, 10);
        if ($method === self::RATE_UNIFIED && $currency !== 'CZK') {
            $this->usedRates[$currency . '|' . substr($date, 0, 4)] = true;
        }
        return $method === self::RATE_UNIFIED
            ? $this->unified->toCzk($amount, $currency, $date)
            : $this->fx->convert($amount, $currency, 'CZK', $date);
    }

    /** @return list<array{currency:string,year:int,rate:string}> jednotné kurzy použité v reportu */
    private function usedUnifiedRates(): array
    {
        ksort($this->usedRates);
        $out = [];
        foreach (array_keys($this->usedRates) as $key) {
            [$currency, $year] = explode('|', $key);
            $out[] = ['currency' => $currency, 'year' => (int) $year,
                'rate' => (string) $this->unified->rateToCzk($currency, (int) $year)->toScale(4, RoundingMode::HalfUp)];
        }
        return $out;
    }

    private static function money(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }
}
