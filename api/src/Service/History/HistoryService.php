<?php

declare(strict_types=1);

namespace MyPortfolio\Service\History;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\Inflation\CpiService;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Portfolio\ValuationService;

/**
 * Historie hodnoty pro graf a metriky za období. Před čtením dopočítá snapshoty
 * pozic ve frontě (změny transakcí, ocenění, cen) a dnešní den. K tomu benchmark uživatele
 * (stejné toky do zvoleného instrumentu) a inflace ČR za období s reálnými metrikami.
 */
final class HistoryService
{
    public const SCOPES = ['total', 'portfolio', 'position', 'class'];
    public const RANGES = ['1M', '3M', 'YTD', '1Y', 'ALL'];

    public function __construct(
        private readonly PositionRepository $positions,
        private readonly SnapshotRepository $snapshots,
        private readonly SnapshotService $snapshotService,
        private readonly ReturnsCalculator $returns,
        private readonly FxService $fx,
        private readonly BenchmarkService $benchmarks,
        private readonly CpiService $cpi,
        private readonly UserPreferenceRepository $preferences,
        private readonly InstrumentRepository $instruments,
    ) {}

    /** @return array<string,mixed> */
    /** `$viewerId` = čí benchmark (předvolba diváka; při sdílení se liší od vlastníka dat) */
    public function history(int $userId, string $currency, string $scope, ?string $id, string $range, ?int $viewerId = null): array
    {
        $this->refresh($userId);

        $positionIds = $this->select($userId, $scope, $id);
        $today = $this->fx->today();
        $from = match ($range) {
            '1M'  => $today->modify('-1 month'),
            '3M'  => $today->modify('-3 months'),
            'YTD' => $today->setDate((int) $today->format('Y'), 1, 1),
            '1Y'  => $today->modify('-1 year'),
            default => null,
        };
        $first = $this->snapshots->firstDate($userId, $positionIds);
        $fromDate = $from?->format('Y-m-d');
        if ($first !== null && ($fromDate === null || $fromDate < $first)) {
            $fromDate = $first;
        }
        $fromDate ??= $today->format('Y-m-d');
        $baselineDate = (new \DateTimeImmutable($fromDate))->modify('-1 day')->format('Y-m-d');

        $rows = $this->snapshots->series($userId, $positionIds, $currency, $baselineDate, $today->format('Y-m-d'));
        $baseline = null;
        if ($rows !== [] && $rows[0]['date'] === $baselineDate) {
            $b = array_shift($rows);
            $baseline = ['date' => $b['date'], 'value' => $b['missing'] === 0 ? $b['value'] : null];
        }

        $benchmark = $this->benchmark($viewerId ?? $userId, $currency, $baseline, $rows);
        $points = [];
        $metricPoints = [];
        foreach ($rows as $r) {
            $complete = $r['missing'] === 0;
            $points[] = [
                'date'       => $r['date'],
                'value'      => $r['value'] !== null ? self::out($r['value']) : null,
                'invested'   => $r['invested'] !== null ? self::out($r['invested']) : null,
                'incomplete' => !$complete,
                'stale'      => $r['stale'] > 0,
            ] + ($benchmark !== null ? ['benchmark' => $benchmark['values'][$r['date']] ?? null] : []);
            $metricPoints[] = ['date' => $r['date'], 'value' => $complete ? $r['value'] : null, 'flow' => $r['flow']];
        }
        $m = $this->returns->metrics($baseline, $metricPoints);

        // Částky přesně (BigDecimal); float jen pro poměrové metriky.
        $netFlow = array_reduce($rows, static fn (BigDecimal $c, array $r) => $c->plus($r['flow']), BigDecimal::zero());
        $lastComplete = null;
        foreach (array_reverse($metricPoints) as $p) {
            if ($p['value'] !== null) {
                $lastComplete = $p;
                break;
            }
        }
        $startValue = $baseline['value'] ?? null;
        $gain = $lastComplete !== null
            ? BigDecimal::of($lastComplete['value'])->minus($startValue ?? '0')->minus($netFlow)
            : null;

        // Inflace od měsíce před začátkem období do konce (poslední zveřejněný měsíc)
        $inflation = $this->cpi->between(substr($baselineDate, 0, 7), $today->format('Y-m'));
        $days = (int) (new \DateTimeImmutable($baselineDate))->diff($today)->days;
        $months = $inflation !== null ? self::monthsBetween($inflation['from'], $inflation['to']) : 0;
        $real = $inflation !== null ? $this->returns->real($m, (float) (string) $inflation['rate'], $months, $days) : null;

        return [
            'currency' => $currency,
            'scope'    => $scope,
            'range'    => $range,
            'from'     => $fromDate,
            'to'       => $today->format('Y-m-d'),
            'points'   => $points,
            'metrics'  => [
                'start_value'    => $startValue !== null ? self::out($startValue) : null,
                'end_value'      => $lastComplete !== null ? self::out($lastComplete['value']) : null,
                'net_flow'       => self::out((string) $netFlow),
                'gain'           => $gain !== null ? self::out((string) $gain) : null,
                'simple_pct'     => self::pct($m['simple']),
                'twr_pct'        => self::pct($m['twr']),
                'twr_annual_pct' => self::pct($m['twr_annualized']),
                'xirr_pct'       => self::pct($m['xirr']),
                'inflation_pct'  => CpiService::pct($inflation['rate'] ?? null),
                'inflation_from' => $inflation['from'] ?? null,
                'inflation_to'   => $inflation['to'] ?? null,
                'twr_real_pct'        => $real !== null ? self::pct($real['twr']) : null,
                'twr_annual_real_pct' => $real !== null ? self::pct($real['twr_annualized']) : null,
                'xirr_real_pct'       => $real !== null ? self::pct($real['xirr']) : null,
            ],
            'benchmark' => $benchmark !== null ? array_diff_key($benchmark, ['values' => true]) : $this->benchmarkInfo($viewerId ?? $userId),
        ];
    }

    /**
     * Simulace benchmarku uživatele na tocích období.
     *
     * @param array{date:string,value:?string}|null $baseline
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function benchmark(int $userId, string $currency, ?array $baseline, array $rows): ?array
    {
        $info = $this->benchmarkInfo($userId);
        if ($info === null) {
            return null;
        }
        $sim = $this->benchmarks->simulate($info['instrument_id'], $currency, $baseline,
            array_map(static fn (array $r) => ['date' => $r['date'], 'flow' => (string) $r['flow']], $rows));
        if ($sim === null) {
            return null;
        }
        $twr = BigDecimal::of($sim['end_price'])->dividedBy($sim['start_price'], 10, RoundingMode::HalfUp)->minus(1);
        return array_merge($info, [
            'available' => true,
            'values'    => $sim['values'],
            'end_value' => $sim['end_value'],
            'gain'      => (string) BigDecimal::of($sim['end_value'])->minus($sim['start_value'])->minus($sim['net_flow'])->toScale(2, RoundingMode::HalfUp),
            'twr_pct'   => CpiService::pct($twr),
        ]);
    }

    /** Zvolený benchmark (bez simulace = nedostupný pro období). @return array<string,mixed>|null */
    private function benchmarkInfo(int $userId): ?array
    {
        $id = $this->preferences->benchmark($userId);
        $instrument = $id !== null ? $this->instruments->find($id) : null;
        if ($instrument === null) {
            return null;
        }
        return ['instrument_id' => (int) $instrument['id'], 'symbol' => $instrument['symbol'], 'name' => $instrument['name'],
            'available' => false, 'end_value' => null, 'gain' => null, 'twr_pct' => null];
    }

    /** Dopočítá frontu, nové pozice a dnešek. */
    public function refresh(int $userId): void
    {
        foreach ($this->snapshots->positionsWithoutSnapshots($userId) as $positionId) {
            $this->snapshotService->recompute($userId, $positionId);
        }
        $this->snapshotService->processDirty($userId);
        $this->snapshotService->refreshRecent($userId, 0);
    }

    /** @return list<int> */
    private function select(int $userId, string $scope, ?string $id): array
    {
        if ($scope === 'position') {
            $position = $this->positions->find($userId, (int) $id) ?? throw DomainError::notFound('Pozice nenalezena.');
            return [(int) $position['id']];
        }
        $all = $this->positions->list($userId, ['archived' => true]);
        $filtered = match ($scope) {
            'portfolio' => array_filter($all, static fn (array $p) => (int) $p['portfolio_id'] === (int) $id),
            'class'     => array_filter($all, static fn (array $p) => ValuationService::category($p) === $id),
            default     => $all,
        };
        if ($scope === 'portfolio' && $filtered === []) {
            throw DomainError::notFound('Portfolio nenalezeno.');
        }
        return array_values(array_map(static fn (array $p) => (int) $p['id'], $filtered));
    }

    private static function monthsBetween(string $from, string $to): int
    {
        [$fy, $fm] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));
        return ($ty - $fy) * 12 + $tm - $fm;
    }

    private static function out(string $v): string
    {
        return (string) BigDecimal::of($v)->toScale(2, RoundingMode::HalfUp);
    }

    private static function pct(?float $v): ?string
    {
        return $v === null || !is_finite($v) ? null : number_format($v * 100, 2, '.', '');
    }
}
