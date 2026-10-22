<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Summary;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Service\History\HistoryService;
use MyPortfolio\Service\Inflation\CpiService;
use MyPortfolio\Service\Portfolio\DomainError;

/**
 * Měsíční a roční souhrn z denních snapshotů v měně zobrazení:
 * hodnota na začátku a na konci, vklady a výběry, výnos (změna − toky) a výnos % metodou Modified Dietz
 * (výnos / (hodnota na začátku + ½ toků)) — stejně jako týdenní report. K tomu dividendy, úroky, poplatky
 * a inflace ČR za období s reálným výnosem (bez zveřejněného indexu null).
 */
final class PeriodSummaryService
{
    public function __construct(
        private readonly PositionRepository $positions,
        private readonly SnapshotRepository $snapshots,
        private readonly IncomeService $income,
        private readonly HistoryService $history,
        private readonly CpiService $cpi,
    ) {}

    /**
     * @param array{portfolio_id?:?int,account_id?:?int} $filter
     * @return array{currency:string,months:list<array<string,mixed>>,years:list<array<string,mixed>>,missing_fx:list<string>}
     */
    public function build(int $userId, string $currency, array $filter = [], bool $refresh = true): array
    {
        if ($refresh) {
            $this->history->refresh($userId);
        }
        $positions = $this->select($userId, $filter);
        $ids = array_keys($positions);
        $monthly = $this->snapshots->monthly($userId, $ids, $currency);

        $income = $this->income->rows($userId, $currency);
        $byMonth = [];
        foreach ($income['rows'] as $r) {
            if (!isset($positions[$r['position_id']])) {
                continue;
            }
            $m = substr($r['date'], 0, 7);
            $byMonth[$m] ??= ['dividends' => BigDecimal::zero(), 'interest' => BigDecimal::zero(), 'fees' => BigDecimal::zero(), 'withheld' => BigDecimal::zero()];
            match ($r['type']) {
                'dividend' => $byMonth[$m]['dividends'] = $byMonth[$m]['dividends']->plus($r['gross']),
                'interest' => $byMonth[$m]['interest'] = $byMonth[$m]['interest']->plus($r['gross']),
                default    => $byMonth[$m]['fees'] = $byMonth[$m]['fees']->plus($r['gross']),
            };
            $byMonth[$m]['withheld'] = $byMonth[$m]['withheld']->plus($r['withheld']);
        }

        $current = (new \DateTimeImmutable('today'))->format('Y-m');
        $months = [];
        $prev = ['value' => BigDecimal::zero(), 'missing' => false];
        foreach ($monthly as $row) {
            $to = BigDecimal::of($row['value']);
            $flow = BigDecimal::of($row['flow']);
            $metrics = self::metrics($prev['value'], $to, $flow, $prev['missing'] || $row['missing'] > 0);
            $prevMonth = (new \DateTimeImmutable($row['month'] . '-01'))->modify('-1 month')->format('Y-m');
            $months[] = ['period' => $row['month'], 'partial' => $row['month'] >= $current]
                + $metrics
                + $this->inflation($prevMonth, $row['month'], $metrics['gain_pct'])
                + self::income($byMonth[$row['month']] ?? null);
            $prev = ['value' => $to, 'missing' => $row['missing'] > 0];
        }

        $years = [];
        foreach ($months as $m) {
            $y = substr($m['period'], 0, 4);
            $years[$y] ??= ['first' => $m, 'last' => $m, 'flow' => BigDecimal::zero(), 'incomplete' => false, 'income' => []];
            $years[$y]['last'] = $m;
            $years[$y]['flow'] = $years[$y]['flow']->plus($m['flow']);
            $years[$y]['incomplete'] = $years[$y]['incomplete'] || $m['incomplete'];
            foreach (['dividends', 'interest', 'fees', 'withheld'] as $k) {
                $years[$y]['income'][$k] = ($years[$y]['income'][$k] ?? BigDecimal::zero())->plus($m[$k]);
            }
        }
        $yearRows = [];
        foreach ($years as $y => $v) {
            $metrics = self::metrics(BigDecimal::of($v['first']['value_from']), BigDecimal::of($v['last']['value_to']), $v['flow'], $v['incomplete']);
            $yearRows[] = ['period' => (string) $y, 'partial' => (string) $y >= substr($current, 0, 4)]
                + $metrics
                // inflace od stejného okamžiku jako výnos: měsíc před prvním měsícem roku v datech (obvykle prosinec)
                + $this->inflation((new \DateTimeImmutable($v['first']['period'] . '-01'))->modify('-1 month')->format('Y-m'), $y . '-12', $metrics['gain_pct'])
                + self::income($v['income']);
        }
        return ['currency' => $currency, 'months' => $months, 'years' => $yearRows, 'missing_fx' => $income['missing_fx']];
    }

    /**
     * Souhrn jednoho měsíce (`YYYY-MM`) nebo roku (`YYYY`) — celkem a po portfoliích (pro e-mail).
     *
     * @return array{total:?array<string,mixed>,portfolios:list<array<string,mixed>>,months:list<array<string,mixed>>}
     */
    public function period(int $userId, string $currency, string $period): array
    {
        $this->history->refresh($userId);
        $pick = static function (array $summary) use ($period): ?array {
            foreach (strlen($period) === 4 ? $summary['years'] : $summary['months'] as $row) {
                if ($row['period'] === $period) {
                    return $row;
                }
            }
            return null;
        };
        $all = $this->build($userId, $currency, [], false);
        $portfolios = [];
        $seen = [];
        foreach ($this->positions->list($userId, ['archived' => true]) as $p) {
            $pid = (int) $p['portfolio_id'];
            if (isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $row = $pick($this->build($userId, $currency, ['portfolio_id' => $pid], false));
            if ($row !== null && ($row['value_to'] !== '0.00' || $row['value_from'] !== '0.00' || $row['flow'] !== '0.00')) {
                $portfolios[] = ['name' => (string) $p['portfolio_name']] + $row;
            }
        }
        $months = strlen($period) === 4
            ? array_values(array_filter($all['months'], static fn (array $m) => str_starts_with($m['period'], $period . '-')))
            : [];
        return ['total' => $pick($all), 'portfolios' => $portfolios, 'months' => $months];
    }

    /**
     * @param array{portfolio_id?:?int,account_id?:?int} $filter
     * @return array<int,array<string,mixed>>
     */
    private function select(int $userId, array $filter): array
    {
        $out = [];
        foreach ($this->positions->list($userId, ['archived' => true]) as $p) {
            if ((!empty($filter['portfolio_id']) && (int) $p['portfolio_id'] !== (int) $filter['portfolio_id'])
                || (!empty($filter['account_id']) && (int) $p['account_id'] !== (int) $filter['account_id'])) {
                continue;
            }
            $out[(int) $p['id']] = $p;
        }
        if ($out === [] && (!empty($filter['portfolio_id']) || !empty($filter['account_id']))) {
            throw DomainError::notFound('Portfolio nebo účet nenalezen.');
        }
        return $out;
    }

    /** @return array<string,mixed> */
    /**
     * Neúplné období (na začátku nebo na konci chybí cena / kurz části pozic — v součtu jsou nulou) výnos nemá:
     * návrat ceny by jinak vypadal jako zisk z nuly. Hodnota zůstane s příznakem `incomplete`.
     */
    private static function metrics(BigDecimal $from, BigDecimal $to, BigDecimal $flow, bool $incomplete): array
    {
        $change = $to->minus($from);
        $gain = $change->minus($flow);
        $base = $from->plus($flow->dividedBy(2, 4, RoundingMode::HalfUp));
        return [
            'value_from' => self::money($from),
            'value_to'   => self::money($to),
            'flow'       => self::money($flow),
            'change'     => $incomplete ? null : self::money($change),
            'gain'       => $incomplete ? null : self::money($gain),
            'gain_pct'   => !$incomplete && $base->isPositive() ? (string) $gain->multipliedBy(100)->dividedBy($base, 2, RoundingMode::HalfUp) : null,
            'incomplete' => $incomplete,
        ];
    }

    /**
     * Inflace mezi měsíci (u probíhajícího období do posledního zveřejněného) a reálný výnos.
     *
     * @return array{inflation_pct:?string,inflation_to:?string,real_pct:?string}
     */
    private function inflation(string $from, string $to, ?string $gainPct): array
    {
        $i = $this->cpi->between($from, $to);
        if ($i === null) {
            return ['inflation_pct' => null, 'inflation_to' => null, 'real_pct' => null];
        }
        return [
            'inflation_pct' => CpiService::pct($i['rate']),
            'inflation_to'  => $i['to'],
            'real_pct'      => $gainPct !== null ? CpiService::pct(CpiService::real(BigDecimal::of($gainPct)->dividedBy(100, 10, RoundingMode::HalfUp), $i['rate'])) : null,
        ];
    }

    /** @param array<string,BigDecimal>|null $i @return array<string,string> */
    private static function income(?array $i): array
    {
        $z = BigDecimal::zero();
        return [
            'dividends' => self::money($i['dividends'] ?? $z),
            'interest'  => self::money($i['interest'] ?? $z),
            'fees'      => self::money($i['fees'] ?? $z),
            'withheld'  => self::money($i['withheld'] ?? $z),
        ];
    }

    private static function money(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }
}
