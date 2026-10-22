<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Sharing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\ShareRepository;
use MyPortfolio\Service\NetWorth\NetWorthService;
use MyPortfolio\Service\Portfolio\ValuationService;

/**
 * Domácnost: přihlášený + vlastníci, kteří mu sdílí (v rozsahu sdílení), v měně diváka. Investice z Přehledu
 * každého člena, čisté jmění jen u členů, kteří sdílí sekci čisté jmění (jinak se počítají jen investice).
 */
final class HouseholdService
{
    public function __construct(
        private readonly ShareRepository $shares,
        private readonly AccessContext $context,
        private readonly ValuationService $valuation,
        private readonly NetWorthService $netWorth,
        private readonly Connection $db,
    ) {}

    /** @return array<string,mixed> */
    public function overview(int $userId, string $currency): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $members = [['user_id' => $userId, 'name' => (string) $stmt->fetchColumn(), 'me' => true, 'portfolios' => null, 'net_worth' => true]];
        foreach ($this->shares->received($userId) as $s) {
            $members[] = ['user_id' => $s['owner_user_id'], 'name' => (string) $s['owner_name'], 'me' => false,
                'portfolios' => $s['scope'] === 'portfolios' ? array_column($s['portfolios'], 'id') : null,
                'net_worth' => $s['scope'] === 'all' && in_array('net_worth', $s['sections'], true)];
        }

        $sum = ['value' => BigDecimal::zero(), 'invested' => BigDecimal::zero(), 'total_return' => BigDecimal::zero(),
            'assets' => BigDecimal::zero(), 'liabilities' => BigDecimal::zero()];
        $allocation = [];
        $out = [];
        $incomplete = 0;
        foreach ($members as $m) {
            $row = $this->context->as($m['user_id'], $userId, $m['portfolios'], function () use ($m, $currency): array {
                $d = $this->valuation->dashboard($m['user_id'], $currency);
                $nw = $m['net_worth'] ? $this->netWorth->overview($m['user_id'], $currency) : null;
                return ['totals' => self::activeTotals($d['positions']), 'allocation' => self::activeAllocation($d['positions']), 'net_worth' => $nw];
            });
            foreach (['value', 'invested', 'total_return'] as $k) {
                $sum[$k] = $sum[$k]->plus($row['totals'][$k]);
            }
            $incomplete += (int) $row['totals']['incomplete'];
            foreach ($row['allocation'] as $slice) {
                $allocation[$slice['label']] = ($allocation[$slice['label']] ?? BigDecimal::zero())->plus($slice['value']);
            }
            if ($row['net_worth'] !== null) {
                $sum['assets'] = $sum['assets']->plus($row['net_worth']['assets']);
                $sum['liabilities'] = $sum['liabilities']->plus($row['net_worth']['liabilities']);
            }
            $out[] = [
                'user_id'     => $m['user_id'],
                'name'        => $m['name'],
                'me'          => $m['me'],
                'scope'       => $m['portfolios'] === null ? 'all' : 'portfolios',
                'value'       => $row['totals']['value'],
                'invested'    => $row['totals']['invested'],
                'total_return' => $row['totals']['total_return'],
                'return_pct'  => $row['totals']['return_pct'],
                'assets'      => $row['net_worth']['assets'] ?? null,
                'liabilities' => $row['net_worth']['liabilities'] ?? null,
                'net_worth'   => $row['net_worth']['net_worth'] ?? null,
            ];
        }
        arsort($allocation);
        return [
            'currency'   => $currency,
            'members'    => $out,
            'totals'     => [
                'value'        => self::out($sum['value']),
                'invested'     => self::out($sum['invested']),
                'total_return' => self::out($sum['total_return']),
                'assets'       => self::out($sum['assets']),
                'liabilities'  => self::out($sum['liabilities']),
                'net_worth'    => self::out($sum['value']->plus($sum['assets'])->minus($sum['liabilities'])),
                'incomplete'   => $incomplete,
            ],
            'allocation' => array_map(static fn (string $label, BigDecimal $v) => ['label' => $label, 'value' => self::out($v)],
                array_map('strval', array_keys($allocation)), array_values($allocation)),
            'net_worth_partial' => count(array_filter($members, static fn (array $m) => !$m['net_worth'])) > 0,
        ];
    }

    /**
     * Součty jen z aktivních (nearchivovaných) pozic — stejně jako investice v čistém jmění.
     *
     * @param list<array<string,mixed>> $positions
     * @return array{value:string,invested:string,total_return:string,return_pct:?string,incomplete:int}
     */
    private static function activeTotals(array $positions): array
    {
        $sum = ['value' => BigDecimal::zero(), 'invested' => BigDecimal::zero(), 'total_return' => BigDecimal::zero(), 'cost_basis' => BigDecimal::zero()];
        $incomplete = 0;
        foreach ($positions as $p) {
            if ($p['value'] === null) {
                $incomplete++;
                continue;
            }
            foreach (array_keys($sum) as $k) {
                $sum[$k] = $sum[$k]->plus($p[$k] ?? '0');
            }
        }
        return ['value' => self::out($sum['value']), 'invested' => self::out($sum['invested']), 'total_return' => self::out($sum['total_return']),
            'return_pct' => $sum['cost_basis']->isPositive()
                ? (string) $sum['total_return']->multipliedBy(100)->dividedBy($sum['cost_basis'], 2, RoundingMode::HalfUp) : null,
            'incomplete' => $incomplete];
    }

    /** @param list<array<string,mixed>> $positions @return list<array{label:string,value:string}> */
    private static function activeAllocation(array $positions): array
    {
        $out = [];
        foreach ($positions as $p) {
            if ($p['value'] !== null) {
                $out[$p['category']] = ($out[$p['category']] ?? BigDecimal::zero())->plus($p['value']);
            }
        }
        return array_map(static fn (string $l, BigDecimal $v) => ['label' => $l, 'value' => self::out($v)], array_map('strval', array_keys($out)), array_values($out));
    }

    private static function out(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }
}
