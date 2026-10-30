<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Portfolio;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\LotRepository;
use MyPortfolio\Repository\ManualValuationRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\Price\PriceService;
use MyPortfolio\Support\Decimal;

/**
 * Ocenění pozic v měně zobrazení uživatele.
 *
 *   nákupní cena  → kurzem ke dni nabytí lotu
 *   hodnota       → dnešní cena × dnešní kurz
 *   realizovaný   → výnos kurzem ke dni prodeje − nákupní cena kurzem ke dni nabytí
 *   příjmy        → dividendy, úroky a odměny kurzem ke dni připsání
 *
 * Tržní pozice: celkový výnos = nerealizovaný + realizovaný + příjmy − poplatky.
 * Ruční pozice: hodnota = poslední ocenění + vklady − výběry + úroky − poplatky po jeho datu
 * (bez ocenění čisté vklady + úroky − poplatky); celkový výnos = hodnota − čisté vklady.
 *
 * Chybějící cena nebo kurz → hodnota null + upozornění, nikdy tichá náhrada.
 */
final class ValuationService
{
    private const SCALE = Decimal::SCALE_PRICE;
    private const OUT_SCALE = 2;

    public function __construct(
        private readonly PositionRepository $positions,
        private readonly LotRepository $lots,
        private readonly TransactionRepository $transactions,
        private readonly ManualValuationRepository $valuations,
        private readonly PriceService $prices,
        private readonly FxService $fx,
    ) {}

    /**
     * Ocenění všech pozic uživatele (včetně archivovaných kvůli realizovanému zisku).
     *
     * @return list<array<string,mixed>>
     */
    public function positions(int $userId, string $currency, ?int $positionId = null): array
    {
        $positions = $positionId !== null
            ? array_filter([$this->positions->find($userId, $positionId)])
            : $this->positions->list($userId, ['archived' => true]);
        if ($positions === []) {
            return [];
        }
        $today = $this->fx->today()->format('Y-m-d');
        $lots = $this->group($this->lots->lots($userId, $positionId));
        $disposals = $this->group($this->lots->disposals($userId, $positionId));
        $flows = $this->group($this->transactions->cashFlows($userId, $positionId));
        $latestValuations = $this->valuations->latestPerPosition($userId, $today);
        $lastPrices = $this->prices->lastPrices(array_values(array_map(
            static fn (array $p) => ['id' => $p['instrument_id'], 'asset_class' => $p['asset_class']],
            array_filter($positions, static fn (array $p) => $p['instrument_id'] !== null),
        )));

        $out = [];
        foreach ($positions as $position) {
            $id = (int) $position['id'];
            $row = $position['valuation_mode'] === 'market'
                ? $this->market($position, $lots[$id] ?? [], $disposals[$id] ?? [], $flows[$id] ?? [],
                    $lastPrices[(int) $position['instrument_id']] ?? null, $currency, $today)
                : $this->manualValue($position, $flows[$id] ?? [], $latestValuations[$id] ?? null, $currency, $today);
            $out[] = $row + [
                'position_id'      => $id,
                'display_name'     => $position['display_name'],
                'instrument_id'    => $position['instrument_id'],
                'instrument_name'  => $position['instrument_name'],
                'asset_class'      => $position['asset_class'],
                'category'         => self::category($position),
                'valuation_mode'   => $position['valuation_mode'],
                'is_cash'          => !empty($position['is_cash']),
                'portfolio_id'     => $position['portfolio_id'],
                'portfolio_name'   => $position['portfolio_name'],
                'account_id'       => $position['account_id'],
                'account_name'     => $position['account_name'],
                'institution_name' => $position['institution_name'],
                'institution_icon_url' => $position['institution_icon_url'],
                'instrument_icon_url'  => $position['instrument_icon_url'],
                'archived'         => $position['archived_at'] !== null,
                'currency'         => $currency,
            ];
        }
        return $out;
    }

    /**
     * Dnešní hodnota ručních pozic v jejich vlastní měně (seznam pozic).
     *
     * @param list<array<string,mixed>> $positions řádky PositionRepository
     * @return array<int,array{value:?string,valuation_date:?string}> position_id → hodnota + datum posledního ocenění
     */
    public function manualValues(int $userId, array $positions): array
    {
        $manual = array_filter($positions, static fn (array $p) => $p['valuation_mode'] === 'manual');
        if ($manual === []) {
            return [];
        }
        $today = $this->fx->today()->format('Y-m-d');
        $flows = $this->group($this->transactions->cashFlows($userId));
        $latest = $this->valuations->latestPerPosition($userId, $today);
        $out = [];
        foreach ($manual as $p) {
            $id = (int) $p['id'];
            $v = $this->manualValue($p, $flows[$id] ?? [], $latest[$id] ?? null, (string) $p['currency'], $today);
            $out[$id] = ['value' => $v['value'], 'valuation_date' => $latest[$id]['date'] ?? null];
        }
        return $out;
    }

    /**
     * Přehled: souhrn, alokace, krypto napříč účty a upozornění.
     *
     * @return array<string,mixed>
     */
    public function dashboard(int $userId, string $currency): array
    {
        $rows = $this->positions($userId, $currency);
        $sum = static fn (array $rs, string $k) => array_reduce(
            $rs,
            static fn (BigDecimal $c, array $r) => $r[$k] !== null ? $c->plus($r[$k]) : $c,
            BigDecimal::zero(),
        );

        // Složky výnosu ze stejných (oceněných) pozic jako celkový výnos — dlaždice
        // nerealizovaný + realizovaný + příjmy − poplatky se tak sečtou přesně na total_return.
        $valued = array_values(array_filter($rows, static fn (array $r) => $r['value'] !== null));
        $invested = $sum($valued, 'invested');
        $total = $sum($valued, 'total_return');
        $totals = [
            'value'        => self::out($sum($valued, 'value')),
            'invested'     => self::out($invested),
            'unrealized'   => self::out($sum($valued, 'unrealized')),
            'realized'     => self::out($sum($valued, 'realized')),
            'income'       => self::out($sum($valued, 'income')),
            'fees'         => self::out($sum($valued, 'fees')),
            'total_return' => self::out($total),
            'return_pct'   => self::pct($total, $sum($valued, 'cost_basis')),
            'incomplete'   => count($rows) - count($valued),
        ];

        $allocation = [];
        foreach (['category' => 'category', 'portfolio' => 'portfolio_name', 'institution' => 'institution_name'] as $dim => $key) {
            $buckets = [];
            foreach ($valued as $r) {
                $buckets[$r[$key]] = ($buckets[$r[$key]] ?? BigDecimal::zero())->plus($r['value']);
            }
            arsort($buckets);
            $allocation[$dim] = array_map(
                static fn (string $label, BigDecimal $v) => ['label' => $label, 'value' => self::out($v)],
                array_map('strval', array_keys($buckets)),
                array_values($buckets),
            );
        }

        $crypto = [];
        foreach ($rows as $r) {
            if ($r['asset_class'] !== 'crypto' || $r['quantity'] === null || BigDecimal::of($r['quantity'])->isZero()) {
                continue;
            }
            $c = &$crypto[$r['instrument_id']];
            $c ??= ['instrument_id' => $r['instrument_id'], 'symbol' => $r['display_name'], 'name' => $r['instrument_name'],
                'icon_url' => $r['instrument_icon_url'],
                'quantity' => BigDecimal::zero(), 'value' => BigDecimal::zero(), 'invested' => BigDecimal::zero(),
                'total_return' => BigDecimal::zero(), 'cost_basis' => BigDecimal::zero(),
                'complete' => true, 'accounts' => []];
            $c['quantity'] = $c['quantity']->plus($r['quantity']);
            if ($r['value'] !== null) {
                $c['value'] = $c['value']->plus($r['value']);
                $c['invested'] = $c['invested']->plus($r['invested']);
                $c['total_return'] = $c['total_return']->plus($r['total_return']);
                $c['cost_basis'] = $c['cost_basis']->plus($r['cost_basis']);
            } else {
                $c['complete'] = false;
            }
            $c['accounts'][] = ['position_id' => $r['position_id'], 'account' => $r['institution_name'] . ' · ' . $r['account_name'],
                'quantity' => $r['quantity'], 'value' => $r['value']];
            unset($c);
        }
        // Celkový výnos (vč. prodejů, příjmů a poplatků) — stejný pojem jako v tabulkách pozic.
        $crypto = array_values(array_map(static fn (array $c) => [
            ...$c,
            'quantity'     => Decimal::out((string) $c['quantity']),
            'value'        => $c['complete'] ? self::out($c['value']) : null,
            'invested'     => self::out($c['invested']),
            'total_return' => $c['complete'] ? self::out($c['total_return']) : null,
            'return_pct'   => $c['complete'] ? self::pct($c['total_return'], $c['cost_basis']) : null,
            'cost_basis'   => self::out($c['cost_basis']),
        ], $crypto));
        usort($crypto, static fn (array $a, array $b) => BigDecimal::of($b['value'] ?? '0')->compareTo($a['value'] ?? '0'));

        $warnings = [];
        foreach ($rows as $r) {
            foreach ($r['warnings'] as $w) {
                $warnings[] = ['position_id' => $r['position_id'], 'display_name' => $r['display_name'], 'code' => $w];
            }
        }

        return [
            'currency'   => $currency,
            'as_of'      => $this->fx->today()->format('Y-m-d'),
            'totals'     => $totals,
            'allocation' => $allocation,
            'positions'  => array_values(array_filter($rows, static fn (array $r) => !$r['archived'])),
            'crypto'     => $crypto,
            'warnings'   => $warnings,
        ];
    }

    /**
     * Detail tržní pozice: loty a úbytky s hodnotami v měně zobrazení.
     *
     * @return array{lots:list<array<string,mixed>>,disposals:list<array<string,mixed>>}
     */
    public function lotDetail(int $userId, int $positionId, string $currency): array
    {
        $lots = [];
        foreach ($this->lots->lots($userId, $positionId) as $lot) {
            $remainingCost = $this->remainingCost($lot);
            $lots[] = $lot + [
                'remaining_cost'         => Decimal::out((string) $remainingCost->toScale(4, RoundingMode::HalfUp)),
                'remaining_cost_display' => $this->tryOut($remainingCost, $lot['cost_currency'], $currency, substr($lot['acquired_at'], 0, 10)),
            ];
        }
        $disposals = [];
        foreach ($this->lots->disposals($userId, $positionId) as $d) {
            $cost = $this->tryConvert(BigDecimal::of($d['cost_amount']), $d['cost_currency'], $currency, substr($d['acquired_at'], 0, 10));
            $proceeds = $this->tryConvert(BigDecimal::of($d['proceeds_amount']), $d['proceeds_currency'], $currency, substr($d['disposed_at'], 0, 10));
            $disposals[] = $d + [
                'holding_days'     => (int) (new \DateTimeImmutable(substr($d['acquired_at'], 0, 10)))
                    ->diff(new \DateTimeImmutable(substr($d['disposed_at'], 0, 10)))->days,
                'cost_display'     => $cost !== null ? self::out($cost) : null,
                'proceeds_display' => $proceeds !== null ? self::out($proceeds) : null,
                'gain_display'     => $cost !== null && $proceeds !== null ? self::out($proceeds->minus($cost)) : null,
            ];
        }
        return ['lots' => $lots, 'disposals' => $disposals];
    }

    /**
     * @param array<string,mixed> $position
     * @param list<array<string,mixed>> $lots
     * @param list<array<string,mixed>> $disposals
     * @param list<array<string,mixed>> $flows
     * @param array<string,mixed>|null $price
     * @return array<string,mixed>
     */
    private function market(array $position, array $lots, array $disposals, array $flows, ?array $price, string $currency, string $today): array
    {
        $warnings = [];
        $quantity = BigDecimal::zero();
        $openCost = BigDecimal::zero();
        $rewardIncome = BigDecimal::zero();
        $costComplete = true;
        foreach ($lots as $lot) {
            $quantity = $quantity->plus($lot['remaining_quantity']);
            $date = substr($lot['acquired_at'], 0, 10);
            if ($lot['source_type'] === 'reward' && $lot['origin_lot_id'] === null) {
                $rewardIncome = $rewardIncome->plus($this->convertOr($warnings, BigDecimal::of($lot['cost_amount']), $lot['cost_currency'], $currency, $date) ?? BigDecimal::zero());
            }
            if (BigDecimal::of($lot['remaining_quantity'])->isZero()) {
                continue;
            }
            $cost = $this->convertOr($warnings, $this->remainingCost($lot), $lot['cost_currency'], $currency, $date);
            $cost === null ? $costComplete = false : $openCost = $openCost->plus($cost);
        }

        $realized = BigDecimal::zero();
        $disposedCost = BigDecimal::zero();
        foreach ($disposals as $d) {
            $cost = $this->convertOr($warnings, BigDecimal::of($d['cost_amount']), $d['cost_currency'], $currency, substr($d['acquired_at'], 0, 10));
            $proceeds = $this->convertOr($warnings, BigDecimal::of($d['proceeds_amount']), $d['proceeds_currency'], $currency, substr($d['disposed_at'], 0, 10));
            if ($cost !== null && $proceeds !== null) {
                $realized = $realized->plus($proceeds)->minus($cost);
                $disposedCost = $disposedCost->plus($cost);
            }
        }

        [$income, $fees] = $this->incomeAndFees($flows, $currency, (string) $position['instrument_symbol'], $warnings);
        $income = $income->plus($rewardIncome);

        $value = null;
        $isZero = $quantity->isZero();
        if ($isZero) {
            $value = BigDecimal::zero();
        } elseif ($price === null) {
            $warnings[] = 'price_missing';
        } else {
            if ($price['stale']) {
                $warnings[] = 'price_stale';
            }
            $value = $this->convertOr($warnings, $quantity->multipliedBy($price['close']), $price['currency'], $currency, $today);
        }
        if (!$costComplete) {
            $value = null;
        }

        $unrealized = $value?->minus($openCost);
        $total = $unrealized?->plus($realized)->plus($income)->minus($fees);
        $costBasis = $openCost->plus($disposedCost);

        return [
            'quantity'     => Decimal::out((string) $quantity),
            'price'        => $price,
            // Poslední cena dnešním kurzem v měně zobrazení — vedle průměrné nákupní ceny ve stejné měně.
            'price_display' => $price !== null ? $this->priceDisplay(BigDecimal::of($price['close']), $price['currency'], $currency, $today) : null,
            'value'        => $value !== null ? self::out($value) : null,
            'invested'     => self::out($openCost),
            'cost_basis'   => self::out($costBasis),
            'avg_cost'     => $isZero ? null : self::out($openCost->dividedBy($quantity, self::SCALE, RoundingMode::HalfUp)),
            'unrealized'   => $unrealized !== null ? self::out($unrealized) : null,
            'realized'     => self::out($realized),
            'income'       => self::out($income),
            'fees'         => self::out($fees),
            'total_return' => $total !== null ? self::out($total) : null,
            'return_pct'   => $total !== null ? self::pct($total, $costBasis) : null,
            'valuation'    => null,
            'warnings'     => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Ocenění ruční pozice k datu `$today` — `$flows` a `$valuation` musí být k tomuto datu
     * (historie v M4 volá s prefixem toků a oceněním platným k danému dni).
     *
     * @param array<string,mixed> $position
     * @param list<array<string,mixed>> $flows
     * @param array<string,mixed>|null $valuation
     * @return array<string,mixed>
     */
    public function manualValue(array $position, array $flows, ?array $valuation, string $currency, string $today): array
    {
        $warnings = [];
        $own = (string) $position['currency'];
        $base = BigDecimal::zero();
        $since = null;
        // Hotovost se neoceňuje ručně — hodnota je jen součet toků (i kdyby ocenění existovalo ze starších dat)
        if ($valuation !== null && empty($position['is_cash'])) {
            $base = $this->convertOr($warnings, BigDecimal::of($valuation['value']), $valuation['currency'], $own, $valuation['date']);
            $since = $valuation['date'];
        }

        $invested = BigDecimal::zero();
        $income = BigDecimal::zero();
        $fees = BigDecimal::zero();
        $complete = $base !== null;
        foreach ($flows as $f) {
            $date = substr((string) $f['executed_at'], 0, 10);
            $amount = BigDecimal::of((string) ($f['amount'] ?? '0'));
            $flowCurrency = (string) ($f['price_currency'] ?? $own);
            $sign = match ($f['type']) {
                'deposit', 'interest', 'dividend' => 1,
                'withdrawal', 'fee'               => -1,
                default                           => 0,
            };
            if ($sign === 0) {
                continue;
            }
            $display = $this->convertOr($warnings, $amount, $flowCurrency, $currency, $date);
            if ($display === null) {
                $complete = false;
                continue;
            }
            match ($f['type']) {
                'deposit'              => $invested = $invested->plus($display),
                'withdrawal'           => $invested = $invested->minus($display),
                'interest', 'dividend' => $income = $income->plus($display),
                'fee'                  => $fees = $fees->plus($display),
            };
            if ($base !== null && ($since === null || $date > $since)) {
                $inOwn = $this->convertOr($warnings, $amount, $flowCurrency, $own, $date);
                $inOwn === null ? $complete = false : $base = $base->plus($sign === 1 ? $inOwn : $inOwn->negated());
            }
        }

        $value = $complete ? $this->convertOr($warnings, $base, $own, $currency, $today) : null;
        // Spořicí účet je přesný ze vkladů a úroků; investiční (Portu) bez ocenění jen odhad.
        if ($valuation === null && $flows !== [] && self::category($position) !== 'cash') {
            $warnings[] = 'valuation_missing';
        }
        $total = $value?->minus($invested);

        return [
            'quantity'     => null,
            'price'        => null,
            'price_display' => null,
            'value'        => $value !== null ? self::out($value) : null,
            'invested'     => self::out($invested),
            'cost_basis'   => self::out($invested),
            'avg_cost'     => null,
            // Hodnota už obsahuje příjmy i poplatky — nerealizovaný zisk je zhodnocení bez nich,
            // aby platilo total = nerealizovaný + realizovaný + příjmy − poplatky jako u tržních pozic.
            'unrealized'   => $total !== null ? self::out($total->minus($income)->plus($fees)) : null,
            'realized'     => '0.00',
            'income'       => self::out($income),
            'fees'         => self::out($fees),
            'total_return' => $total !== null ? self::out($total) : null,
            'return_pct'   => $total !== null ? self::pct($total, $invested) : null,
            'valuation'    => $valuation,
            'warnings'     => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Dividendy/úroky a samostatné poplatky tržní pozice (+ fiat poplatky převodů).
     *
     * @param list<array<string,mixed>> $flows
     * @param list<string> $warnings
     * @return array{0:BigDecimal,1:BigDecimal}
     */
    private function incomeAndFees(array $flows, string $currency, string $symbol, array &$warnings): array
    {
        $income = BigDecimal::zero();
        $fees = BigDecimal::zero();
        foreach ($flows as $f) {
            $date = substr((string) $f['executed_at'], 0, 10);
            if (in_array($f['type'], ['dividend', 'interest', 'fee'], true)) {
                $v = $this->convertOr($warnings, BigDecimal::of((string) $f['amount']), (string) $f['price_currency'], $currency, $date);
                if ($v !== null) {
                    $f['type'] === 'fee' ? $fees = $fees->plus($v) : $income = $income->plus($v);
                }
                // Srážková daň u dividendy / úroku (fee v měně výplaty) = poplatek pozice; příjem zůstává hrubý.
                if ($f['type'] !== 'fee' && $f['fee'] !== null && preg_match('/^[A-Z]{3}$/', strtoupper((string) ($f['fee_currency'] ?? ''))) === 1) {
                    $t = $this->convertOr($warnings, BigDecimal::of((string) $f['fee']), strtoupper((string) $f['fee_currency']), $currency, $date);
                    $fees = $t !== null ? $fees->plus($t) : $fees;
                }
            } elseif ($f['type'] === 'transfer_out' && $f['fee'] !== null) {
                $feeCurrency = strtoupper((string) ($f['fee_currency'] ?? ''));
                if ($feeCurrency === strtoupper($symbol)) {
                    continue; // v lotech
                }
                if (preg_match('/^[A-Z]{3}$/', $feeCurrency) !== 1) {
                    $warnings[] = 'fee_other_crypto';
                    continue;
                }
                $v = $this->convertOr($warnings, BigDecimal::of((string) $f['fee']), $feeCurrency, $currency, $date);
                $fees = $v !== null ? $fees->plus($v) : $fees;
            }
        }
        return [$income, $fees];
    }

    /**
     * Nákupní cena otevřených lotů v měně `$currency` (kurzem ke dni nabytí); null = chybí kurz.
     * Stejný výpočet jako „investováno" v market().
     *
     * @param list<array<string,mixed>> $lots řádky ve tvaru LotRepository::lots()
     */
    public function openCost(array $lots, string $currency): ?BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($lots as $lot) {
            if (BigDecimal::of($lot['remaining_quantity'])->isZero()) {
                continue;
            }
            $cost = $this->tryConvert($this->remainingCost($lot), $lot['cost_currency'], $currency, substr($lot['acquired_at'], 0, 10));
            if ($cost === null) {
                return null;
            }
            $total = $total->plus($cost);
        }
        return $total;
    }

    /** Nákupní cena zbývajícího množství lotu (poměrná část). */
    private function remainingCost(array $lot): BigDecimal
    {
        $quantity = BigDecimal::of($lot['quantity']);
        if ($quantity->isZero()) {
            return BigDecimal::zero();
        }
        return BigDecimal::of($lot['cost_amount'])->multipliedBy($lot['remaining_quantity'])
            ->dividedBy($quantity, self::SCALE, RoundingMode::HalfUp);
    }

    /** @param list<string> $warnings */
    private function convertOr(array &$warnings, BigDecimal $amount, string $from, string $to, string $date): ?BigDecimal
    {
        $v = $this->tryConvert($amount, $from, $to, $date);
        if ($v === null) {
            $warnings[] = "fx_missing:$from";
        }
        return $v;
    }

    private function tryConvert(BigDecimal $amount, string $from, string $to, string $date): ?BigDecimal
    {
        if ($amount->isZero() || $from === $to) {
            return $amount;
        }
        try {
            return $this->fx->convert($amount, $from, $to, $date);
        } catch (FxRateMissing) {
            return null;
        }
    }

    private function priceDisplay(BigDecimal $close, string $from, string $to, string $date): ?string
    {
        $v = $this->tryConvert($close, $from, $to, $date);
        return $v !== null ? (string) $v->toScale(self::SCALE, RoundingMode::HalfUp) : null;
    }

    private function tryOut(BigDecimal $amount, string $from, string $to, string $date): ?string
    {
        $v = $this->tryConvert($amount, $from, $to, $date);
        return $v !== null ? self::out($v) : null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,list<array<string,mixed>>>
     */
    private function group(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['position_id']][] = $row;
        }
        return $out;
    }

    /** Kategorie pro alokaci: třída aktiva; ruční pozice banka → cash, jinak fund. */
    public static function category(array $position): string
    {
        if (!empty($position['is_cash'])) {
            return 'cash';
        }
        if ($position['asset_class'] !== null) {
            return (string) $position['asset_class'];
        }
        return $position['institution_type'] === 'bank' ? 'cash' : 'fund';
    }

    private static function out(BigDecimal $v): string
    {
        return (string) $v->toScale(self::OUT_SCALE, RoundingMode::HalfUp);
    }

    private static function pct(BigDecimal $gain, BigDecimal|string $base): ?string
    {
        $base = BigDecimal::of($base);
        if (!$base->isPositive()) {
            return null;
        }
        return (string) $gain->multipliedBy(100)->dividedBy($base, 2, RoundingMode::HalfUp);
    }
}
