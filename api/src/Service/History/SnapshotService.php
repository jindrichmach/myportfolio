<?php

declare(strict_types=1);

namespace MyPortfolio\Service\History;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\ManualValuationRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\PriceRepository;
use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\Portfolio\LotRebuilder;
use MyPortfolio\Service\Portfolio\ValuationService;
use MyPortfolio\Service\Price\PriceService;

/**
 * Denní snapshoty pozic: hodnota, investováno a čistý vnější tok ve CZK / EUR / USD.
 *
 * Výpočet je stejný jako na Přehledu (ValuationService + LotEngine na prefixu transakcí),
 * takže dnešní snapshot odpovídá dashboardu. Tržní pozice: loty (= množství a investováno)
 * se přepočítají jen ve dny s transakcí pozice, hodnota = množství × cena dne × kurz dne.
 *
 * Tok (flow) = vnější vklad (+) / výběr (−) do pozice ke dni.
 */
final class SnapshotService
{
    private const OUT_SCALE = 4;

    public function __construct(
        private readonly PositionRepository $positions,
        private readonly TransactionRepository $transactions,
        private readonly InstrumentRepository $instruments,
        private readonly ManualValuationRepository $valuations,
        private readonly PriceRepository $prices,
        private readonly SnapshotRepository $snapshots,
        private readonly LotRebuilder $lots,
        private readonly ValuationService $valuation,
        private readonly PriceService $priceService,
        private readonly FxService $fx,
    ) {}

    /** Přepočítá snapshoty pozice od data (výchozí: od první transakce). Vrací počet dní. */
    public function recompute(int $userId, int $positionId, ?string $from = null): int
    {
        $position = $this->positions->find($userId, $positionId);
        if ($position === null) {
            return 0;
        }
        $today = $this->fx->today()->format('Y-m-d');
        $rows = $position['valuation_mode'] === 'market'
            ? $this->market($userId, $position, $from, $today)
            : $this->manual($userId, $position, $from, $today);
        $this->snapshots->replaceFrom($userId, $positionId, $rows['from'], $rows['rows']);
        return count($rows['rows']);
    }

    /** Zpracuje frontu pozic k přepočtu uživatele. */
    public function processDirty(int $userId): int
    {
        $days = 0;
        foreach ($this->snapshots->dirty($userId) as $positionId => $from) {
            $days += $this->recompute($userId, $positionId, $from);
            $this->snapshots->clearDirty($userId, $positionId, $from);
        }
        return $days;
    }

    /**
     * Přepočítá posledních `$days` dní všech pozic uživatele (dnešek = hodinové ceny). Když poslední snapshot
     * pozice je starší (cron neběžel a aplikaci nikdo neotevřel), dopočítá i dny od něj — jinak by v historii
     * zůstala díra.
     */
    public function refreshRecent(int $userId, int $days = 0): void
    {
        $from = $this->fx->today()->modify("-$days days")->format('Y-m-d');
        $last = $this->snapshots->lastDates($userId);
        foreach ($this->positions->list($userId, ['archived' => true]) as $position) {
            $id = (int) $position['id'];
            $start = isset($last[$id]) ? min($from, self::next($last[$id])) : $from;
            $this->recompute($userId, $id, $start);
        }
    }

    /** Celá historie všech pozic uživatele. */
    public function backfill(int $userId, ?string $from = null): int
    {
        $days = 0;
        foreach ($this->positions->list($userId, ['archived' => true]) as $position) {
            $days += $this->recompute($userId, (int) $position['id'], $from);
        }
        return $days;
    }

    /**
     * @param array<string,mixed> $position
     * @return array{from:string,rows:list<array<string,mixed>>}
     */
    private function market(int $userId, array $position, ?string $from, string $today): array
    {
        $positionId = (int) $position['id'];
        $instrument = $this->instruments->find((int) $position['instrument_id']);
        $all = $this->transactions->forInstrument($userId, (int) $position['instrument_id']);
        $own = array_values(array_filter($all, static fn (array $t) => (int) $t['position_id'] === $positionId));
        if ($instrument === null || $own === []) {
            return ['from' => $from ?? '1970-01-01', 'rows' => []];
        }
        $start = substr((string) $own[0]['executed_at'], 0, 10);
        $from = max($from ?? $start, $start);
        $symbol = strtoupper((string) $instrument['symbol']);

        $ownByDay = [];
        foreach ($own as $tx) {
            $ownByDay[substr((string) $tx['executed_at'], 0, 10)][] = $tx;
        }
        $history = $this->prices->history((int) $instrument['id'], '1970-01-01', $today);
        $priceIdx = -1;

        $rows = [];
        $state = null;
        for ($day = $from; $day <= $today; $day = self::next($day)) {
            if ($state === null || isset($ownByDay[$day])) {
                $state = $this->lotState($all, $instrument, $positionId, $day);
            }
            while (isset($history[$priceIdx + 1]) && $history[$priceIdx + 1]['date'] <= $day) {
                $priceIdx++;
            }
            $price = $history[$priceIdx] ?? null;

            $row = ['date' => $day, 'quantity' => (string) $state['quantity'], 'is_stale' => 0];
            if ($price !== null && !$state['quantity']->isZero()) {
                $row['is_stale'] = (int) $this->priceService->isStaleAt($instrument, $price['date'], $day);
            }
            foreach (SnapshotRepository::CURRENCIES as $cur) {
                $c = strtolower($cur);
                $invested = $state['invested'][$cur];
                $value = null;
                if ($state['quantity']->isZero()) {
                    $value = BigDecimal::zero();
                } elseif ($price !== null && $invested !== null) {
                    $value = $this->convert($state['quantity']->multipliedBy($price['close']), $price['currency'], $cur, $day);
                }
                $row["value_$c"] = self::out($value);
                $row["invested_$c"] = self::out($invested);
                $row["flow_$c"] = self::out($this->marketFlow($ownByDay[$day] ?? [], $symbol, $price, $cur, $day)) ?? '0';
            }
            $rows[] = $row;
        }
        return ['from' => $from, 'rows' => $rows];
    }

    /**
     * Množství a investováno pozice na konci dne (FIFO engine nad prefixem transakcí instrumentu).
     *
     * @param list<array<string,mixed>> $all
     * @param array<string,mixed> $instrument
     * @return array{quantity:BigDecimal,invested:array<string,?BigDecimal>}
     */
    private function lotState(array $all, array $instrument, int $positionId, string $day): array
    {
        $end = $day . ' 23:59:59';
        $prefix = array_values(array_filter($all, static fn (array $t) => (string) $t['executed_at'] <= $end));
        $result = $this->lots->compute($prefix, $instrument);
        $lots = [];
        $quantity = BigDecimal::zero();
        foreach ($result['lots'] as $lot) {
            if ($lot['position_id'] !== $positionId) {
                continue;
            }
            $quantity = $quantity->plus($lot['remaining']);
            $lots[] = [
                'quantity'           => (string) $lot['quantity'],
                'remaining_quantity' => (string) $lot['remaining'],
                'cost_amount'        => (string) $lot['cost'],
                'cost_currency'      => $lot['cost_currency'],
                'acquired_at'        => $lot['acquired_at'],
            ];
        }
        $invested = [];
        foreach (SnapshotRepository::CURRENCIES as $cur) {
            $invested[$cur] = $this->valuation->openCost($lots, $cur);
        }
        return ['quantity' => $quantity, 'invested' => $invested];
    }

    /**
     * @param list<array<string,mixed>> $txs transakce pozice v daný den
     * @param array<string,mixed>|null $price
     */
    private function marketFlow(array $txs, string $symbol, ?array $price, string $cur, string $day): BigDecimal
    {
        $flow = BigDecimal::zero();
        foreach ($txs as $tx) {
            $currency = (string) ($tx['price_currency'] ?? $cur);
            $amount = BigDecimal::of((string) ($tx['amount'] ?? '0'));
            $fee = BigDecimal::zero();
            $feeCurrency = strtoupper((string) ($tx['fee_currency'] ?? $currency));
            if ($tx['fee'] !== null && $feeCurrency !== $symbol && preg_match('/^[A-Z]{3}$/', $feeCurrency) === 1) {
                $fee = $this->convert(BigDecimal::of((string) $tx['fee']), $feeCurrency, $currency, $day) ?? BigDecimal::zero();
            }
            $inCurrency = match ($tx['type']) {
                'buy'          => $amount->plus($fee),
                'sell'         => $amount->minus($fee)->negated(),
                // Výplata odešla z pozice; srážková daň (fee na dividendě) se nevyplatila → čistý tok.
                'dividend', 'interest' => $amount->minus($fee)->negated(),
                'fee'          => $amount,
                'transfer_in', 'transfer_out' => $price === null ? BigDecimal::zero()
                    : BigDecimal::of((string) $tx['quantity'])->multipliedBy($price['close'])
                        ->multipliedBy($tx['type'] === 'transfer_in' ? 1 : -1),
                default        => BigDecimal::zero(),
            };
            if (in_array($tx['type'], ['transfer_in', 'transfer_out'], true) && $price !== null) {
                $currency = (string) $price['currency'];
            }
            $flow = $flow->plus($this->convert($inCurrency, $currency, $cur, $day) ?? BigDecimal::zero());
        }
        return $flow;
    }

    /**
     * @param array<string,mixed> $position
     * @return array{from:string,rows:list<array<string,mixed>>}
     */
    private function manual(int $userId, array $position, ?string $from, string $today): array
    {
        $positionId = (int) $position['id'];
        $flows = array_values(array_filter(
            $this->transactions->cashFlows($userId, $positionId),
            static fn (array $f) => in_array($f['type'], ['deposit', 'withdrawal', 'interest', 'dividend', 'fee'], true),
        ));
        $valuations = empty($position['is_cash']) ? array_reverse($this->valuations->list($userId, $positionId)) : [];
        $firstDates = array_filter([
            isset($flows[0]) ? substr((string) $flows[0]['executed_at'], 0, 10) : null,
            $valuations[0]['date'] ?? null,
        ]);
        if ($firstDates === []) {
            return ['from' => $from ?? '1970-01-01', 'rows' => []];
        }
        $start = min($firstDates);
        $from = max($from ?? $start, $start);

        $rows = [];
        $flowIdx = 0;
        $valIdx = -1;
        $prefix = [];
        for ($day = $from; $day <= $today; $day = self::next($day)) {
            $todays = [];
            while (isset($flows[$flowIdx]) && substr((string) $flows[$flowIdx]['executed_at'], 0, 10) <= $day) {
                $prefix[] = $flows[$flowIdx];
                if (substr((string) $flows[$flowIdx]['executed_at'], 0, 10) === $day) {
                    $todays[] = $flows[$flowIdx];
                }
                $flowIdx++;
            }
            while (isset($valuations[$valIdx + 1]) && $valuations[$valIdx + 1]['date'] <= $day) {
                $valIdx++;
            }
            $row = ['date' => $day, 'quantity' => null, 'is_stale' => 0];
            foreach (SnapshotRepository::CURRENCIES as $cur) {
                $c = strtolower($cur);
                $v = $this->valuation->manualValue($position, $prefix, $valuations[$valIdx] ?? null, $cur, $day);
                $row["value_$c"] = $v['value'] !== null ? (string) BigDecimal::of($v['value'])->toScale(self::OUT_SCALE) : null;
                $row["invested_$c"] = (string) BigDecimal::of($v['invested'])->toScale(self::OUT_SCALE);
                $flow = BigDecimal::zero();
                foreach ($todays as $f) {
                    if (in_array($f['type'], ['deposit', 'withdrawal'], true)) {
                        $amount = $this->convert(BigDecimal::of((string) $f['amount']), (string) ($f['price_currency'] ?? $position['currency']), $cur, $day) ?? BigDecimal::zero();
                        $flow = $f['type'] === 'deposit' ? $flow->plus($amount) : $flow->minus($amount);
                    }
                }
                $row["flow_$c"] = (string) $flow->toScale(self::OUT_SCALE, RoundingMode::HalfUp);
            }
            $rows[] = $row;
        }
        return ['from' => $from, 'rows' => $rows];
    }

    private function convert(BigDecimal $amount, string $from, string $to, string $date): ?BigDecimal
    {
        if ($from === $to || $amount->isZero()) {
            return $amount;
        }
        try {
            return $this->fx->convert($amount, $from, $to, $date);
        } catch (FxRateMissing) {
            return null;
        }
    }

    private static function out(?BigDecimal $v): ?string
    {
        return $v === null ? null : (string) $v->toScale(self::OUT_SCALE, RoundingMode::HalfUp);
    }

    private static function next(string $day): string
    {
        return (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    }
}
