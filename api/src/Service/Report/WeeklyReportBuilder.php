<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Report;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Repository\TransactionRepository;

/**
 * Data týdenního reportu: portfolio → účet → pozice za posledních 7 dní
 * z denních snapshotů v měně zobrazení. Částky jsou stringy na 2 desetinná místa, procenta na 2 místa;
 * `null` = chybí cena nebo kurz (nikdy 0).
 *
 * Ukazatele: změna = hodnota teď − hodnota na začátku; toky = vklady/nákupy − výběry/prodeje;
 * výnos = změna − toky; výnos % = výnos / (hodnota na začátku + ½ toků) (Modified Dietz).
 */
final class WeeklyReportBuilder
{
    public const DAYS = 7;

    public function __construct(
        private readonly PositionRepository $positions,
        private readonly SnapshotRepository $snapshots,
        private readonly TransactionRepository $transactions,
    ) {}

    /** @return array<string,mixed> */
    public function build(int $userId, string $currency, \DateTimeImmutable $today, int $days = self::DAYS): array
    {
        $to = $today->format('Y-m-d');
        $from = $today->modify('-' . $days . ' days')->format('Y-m-d');
        $period = $this->snapshots->periodByPosition($userId, $currency, $from, $to);

        $txRows = $this->transactions->list($userId, [
            'from' => $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
            'to'   => $to,
        ], 1, 500, '-executed_at')['data'];
        $txCount = array_count_values(array_map(static fn (array $t) => (int) $t['position_id'], $txRows));

        $portfolios = [];
        $total = self::emptyTotals();
        foreach ($this->positions->list($userId, ['archived' => true]) as $p) {
            $id = (int) $p['id'];
            $s = $period[$id] ?? null;
            $valueFrom = $s === null ? BigDecimal::zero() : ($s['from_missing'] ? null : BigDecimal::of($s['value_from'] ?? '0'));
            $valueTo = $s === null ? BigDecimal::zero() : ($s['to_missing'] || !$s['has_to'] ? null : BigDecimal::of($s['value_to'] ?? '0'));
            $flow = BigDecimal::of($s['flow'] ?? '0');
            $active = !($valueFrom?->isZero() ?? false) || !($valueTo?->isZero() ?? false) || !$flow->isZero() || isset($txCount[$id]);
            if ($p['archived_at'] !== null && !$active) {
                continue;
            }

            $row = ['name' => (string) $p['display_name'], 'detail' => $p['instrument_name'] ?? null, 'transactions' => $txCount[$id] ?? 0]
                + self::metrics($valueFrom, $valueTo, $flow);

            $pf = &$portfolios[(int) $p['portfolio_id']];
            $pf ??= ['name' => (string) $p['portfolio_name'], 'accounts' => [], 'sum' => self::emptyTotals()];
            $acc = &$pf['accounts'][(int) $p['account_id']];
            $acc ??= ['name' => $p['institution_name'] . ' · ' . $p['account_name'], 'positions' => [], 'sum' => self::emptyTotals()];
            $acc['positions'][] = $row;
            foreach ([&$acc['sum'], &$pf['sum'], &$total] as &$sum) {
                self::add($sum, $valueFrom, $valueTo, $flow);
            }
            unset($sum, $acc, $pf);
        }

        $out = [];
        foreach ($portfolios as $pf) {
            $accounts = [];
            foreach ($pf['accounts'] as $acc) {
                $accounts[] = ['name' => $acc['name'], 'positions' => $acc['positions']] + self::totals($acc['sum']);
            }
            $out[] = ['name' => $pf['name'], 'accounts' => $accounts] + self::totals($pf['sum']);
        }

        $transactions = array_map(static fn (array $t) => [
            'date'      => substr((string) $t['executed_at'], 0, 10),
            'type'      => (string) $t['type'],
            'position'  => (string) $t['position_name'],
            'account'   => $t['institution_name'] . ' · ' . $t['account_name'],
            'quantity'  => $t['quantity'] !== null && !in_array($t['type'], ['deposit', 'withdrawal', 'interest', 'dividend', 'fee'], true)
                ? (string) BigDecimal::of((string) $t['quantity'])->strippedOfTrailingZeros() : null,
            'amount'    => $t['amount'] !== null ? (string) BigDecimal::of((string) $t['amount'])->toScale(2, RoundingMode::HalfUp) : null,
            'currency'  => $t['price_currency'],
        ], $txRows);

        return [
            'currency'     => $currency,
            'from'         => $from,
            'to'           => $to,
            'total'        => self::totals($total),
            'portfolios'   => $out,
            'transactions' => $transactions,
        ];
    }

    /** @return array<string,?string> */
    private static function metrics(?BigDecimal $from, ?BigDecimal $to, BigDecimal $flow): array
    {
        $change = $from !== null && $to !== null ? $to->minus($from) : null;
        $gain = $change?->minus($flow);
        $base = $from?->plus($flow->dividedBy(2, 4, RoundingMode::HalfUp));
        return [
            'value_from' => self::money($from),
            'value_to'   => self::money($to),
            'change'     => self::money($change),
            'change_pct' => $change !== null && $from !== null && $from->isPositive() ? self::pct($change, $from) : null,
            'flow'       => self::money($flow),
            'gain'       => self::money($gain),
            'gain_pct'   => $gain !== null && $base !== null && $base->isPositive() ? self::pct($gain, $base) : null,
        ];
    }

    /** @return array{from:BigDecimal,to:BigDecimal,flow:BigDecimal,incomplete:bool} */
    private static function emptyTotals(): array
    {
        return ['from' => BigDecimal::zero(), 'to' => BigDecimal::zero(), 'flow' => BigDecimal::zero(), 'incomplete' => false];
    }

    /** Pozice s chybějící cenou se do součtu nezapočte, součet je pak označený jako neúplný. */
    private static function add(array &$sum, ?BigDecimal $from, ?BigDecimal $to, BigDecimal $flow): void
    {
        if ($from === null || $to === null) {
            $sum['incomplete'] = true;
            return;
        }
        $sum['from'] = $sum['from']->plus($from);
        $sum['to'] = $sum['to']->plus($to);
        $sum['flow'] = $sum['flow']->plus($flow);
    }

    /** @return array<string,mixed> */
    private static function totals(array $sum): array
    {
        return self::metrics($sum['from'], $sum['to'], $sum['flow']) + ['incomplete' => $sum['incomplete']];
    }

    private static function money(?BigDecimal $v): ?string
    {
        return $v !== null ? (string) $v->toScale(2, RoundingMode::HalfUp) : null;
    }

    private static function pct(BigDecimal $part, BigDecimal $whole): string
    {
        return (string) $part->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HalfUp);
    }
}
