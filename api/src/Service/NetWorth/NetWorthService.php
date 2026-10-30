<?php

declare(strict_types=1);

namespace MyPortfolio\Service\NetWorth;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\NetWorthRepository;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\History\HistoryService;
use MyPortfolio\Service\Portfolio\ValuationService;

/**
 * Čisté jmění = investice (ocenění pozic včetně hotovosti) + majetek mimo investice − závazky, v měně zobrazení.
 * Majetek drží poslední ocenění k datu, závazek se umořuje anuitou ({@see LoanSchedule}). Chybějící kurz →
 * hodnota null a upozornění, nikdy tichá náhrada.
 */
final class NetWorthService
{
    public const ASSET_KINDS = ['real_estate', 'other'];
    public const KINDS = [...self::ASSET_KINDS, 'liability'];

    public function __construct(
        private readonly NetWorthRepository $repository,
        private readonly ValuationService $valuation,
        private readonly HistoryService $history,
        private readonly FxService $fx,
    ) {}

    /** @return array<string,mixed> */
    /** `$archived` = v `items` i archivované položky; do součtů se nepočítají. */
    public function overview(int $userId, string $currency, bool $archived = false): array
    {
        $today = $this->fx->today()->format('Y-m-d');
        $investments = BigDecimal::zero();
        $incomplete = 0;
        foreach ($this->valuation->positions($userId, $currency) as $p) {
            if ($p['archived']) {
                continue;
            }
            $p['value'] !== null ? $investments = $investments->plus($p['value']) : $incomplete++;
        }

        $entries = $this->repository->entries($userId);
        $assets = BigDecimal::zero();
        $liabilities = BigDecimal::zero();
        $items = [];
        $warnings = [];
        foreach ($this->repository->items($userId, $archived) as $item) {
            $list = $entries[$item['id']] ?? [];
            $own = $this->valueOn($item, $list, [$today])[$today];
            $display = null;
            if ($own !== null) {
                try {
                    $display = $this->fx->convert($own, $item['currency'], $currency, $today);
                } catch (FxRateMissing) {
                    $warnings[] = ['item_id' => $item['id'], 'code' => 'fx_missing:' . $item['currency']];
                }
            }
            if ($display !== null && $item['archived_at'] === null) {
                $item['kind'] === 'liability' ? $liabilities = $liabilities->plus($display) : $assets = $assets->plus($display);
            }
            $last = $list !== [] ? $list[array_key_last($list)] : null;
            $row = $item + [
                'value'         => $own !== null ? self::out($own) : null,
                'value_display' => $display !== null ? self::out($display) : null,
                'last_date'     => $last['date'] ?? null,
                'entries'       => count($list),
            ];
            if ($item['kind'] === 'liability') {
                $loan = new LoanSchedule($list);
                $anchor = $loan->anchorOn($today);
                $row['rate_pct'] = $anchor !== null ? (string) $anchor['rate']->strippedOfTrailingZeros() : null;
                $row['payment'] = $anchor !== null ? self::out($anchor['payment']) : null;
                $row['payoff'] = $loan->payoff($today);
            }
            $items[] = $row;
        }

        return [
            'currency'    => $currency,
            'as_of'       => $today,
            'investments' => self::out($investments),
            'investments_incomplete' => $incomplete,
            'assets'      => self::out($assets),
            'liabilities' => self::out($liabilities),
            'net_worth'   => self::out($investments->plus($assets)->minus($liabilities)),
            'items'       => $items,
            'warnings'    => $warnings,
        ];
    }

    /**
     * Denní historie čistého jmění: investice z denních snapshotů (stejné jako graf vývoje) + položky k datu.
     *
     * @return array<string,mixed>
     */
    public function history(int $userId, string $currency, string $range): array
    {
        $h = $this->history->history($userId, $currency, 'total', null, $range);
        $dates = array_column($h['points'], 'date');
        $entries = $this->repository->entries($userId);
        $assets = array_fill_keys($dates, BigDecimal::zero());
        $liabilities = $assets;
        $missing = array_fill_keys($dates, false);
        foreach ($this->repository->items($userId, true) as $item) {
            foreach ($this->valueOn($item, $entries[$item['id']] ?? [], $dates) as $date => $own) {
                if ($own === null || $own->isZero()) {
                    continue;
                }
                try {
                    $value = $this->fx->convert($own, $item['currency'], $currency, $date);
                } catch (FxRateMissing) {
                    $missing[$date] = true;
                    continue;
                }
                if ($item['kind'] === 'liability') {
                    $liabilities[$date] = $liabilities[$date]->plus($value);
                } else {
                    $assets[$date] = $assets[$date]->plus($value);
                }
            }
        }

        $points = [];
        foreach ($h['points'] as $p) {
            $d = $p['date'];
            $points[] = [
                'date'        => $d,
                'investments' => $p['value'],
                'assets'      => self::out($assets[$d]),
                'liabilities' => self::out($liabilities[$d]),
                'net_worth'   => $p['value'] !== null ? self::out(BigDecimal::of($p['value'])->plus($assets[$d])->minus($liabilities[$d])) : null,
                'incomplete'  => $p['incomplete'] || $missing[$d],
            ];
        }
        return ['currency' => $currency, 'range' => $range, 'from' => $h['from'], 'to' => $h['to'], 'points' => $points];
    }

    /**
     * Hodnota položky v její měně pro vzestupně seřazené dny: majetek = poslední ocenění k datu, závazek = zůstatek úvěru.
     *
     * @param array<string,mixed> $item
     * @param list<array<string,mixed>> $entries
     * @param list<string> $dates
     * @return array<string,?BigDecimal>
     */
    private function valueOn(array $item, array $entries, array $dates): array
    {
        if ($item['kind'] === 'liability') {
            return (new LoanSchedule($entries))->balances($dates);
        }
        $out = [];
        $i = -1;
        foreach ($dates as $date) {
            while (isset($entries[$i + 1]) && $entries[$i + 1]['date'] <= $date) {
                $i++;
            }
            $out[$date] = $i >= 0 ? BigDecimal::of($entries[$i]['value']) : null;
        }
        return $out;
    }

    private static function out(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }
}
