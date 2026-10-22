<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Summary;

use MyPortfolio\Service\Summary\IncomeOverviewService;
use MyPortfolio\Service\Summary\PeriodReportService;
use MyPortfolio\Service\Summary\PeriodSummaryService;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Měsíční / roční souhrn a přehled příjmů nad syntetickými snapshoty (červenec–září 2026). */
#[Group('integration')]
final class SummaryTest extends ApiTestCase
{
    /** @var array<string,int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $bank = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Summary Bank', 'type' => 'bank'])['body']['id'];
        $acc1 = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $bank, 'name' => 'One'])['body']['id'];
        $acc2 = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $bank, 'name' => 'Two'])['body']['id'];
        $pf1 = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Spoření'])['body']['id'];
        $pf2 = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Rezerva'])['body']['id'];
        $make = fn (int $pf, int $acc, string $name) => $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'manual', 'portfolio_id' => $pf, 'account_id' => $acc, 'name' => $name,
        ])['body']['id'];
        $this->ids = ['pf1' => $pf1, 'pf2' => $pf2, 'acc2' => $acc2, 'p1' => $make($pf1, $acc1, 'Main'), 'p2' => $make($pf2, $acc2, 'Reserve')];

        // Úrok s daní; frontu přepočtu pak smažeme, aby ruční snapshoty níž zůstaly
        self::assertSame(201, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $this->ids['p1'], 'type' => 'interest',
            'executed_at' => '2026-08-20 08:00', 'amount' => '30', 'fee' => '4.5', 'fee_currency' => 'CZK'])['status']);
        $this->db->pdo()->prepare('DELETE FROM daily_snapshots WHERE user_id = ?')->execute([$this->userId]);
        $this->db->pdo()->prepare('DELETE FROM snapshot_dirty WHERE user_id = ?')->execute([$this->userId]);
        $snap = $this->db->pdo()->prepare('INSERT INTO daily_snapshots (user_id, position_id, date, value_czk, invested_czk, flow_czk) VALUES (?, ?, ?, ?, 0, ?)');
        foreach ([
            ['p1', '2026-07-31', '1000', '0'], ['p1', '2026-08-15', '1150', '100'], ['p1', '2026-08-31', '1200', '0'], ['p1', '2026-09-30', '1100', '0'],
            ['p2', '2026-08-10', '500', '500'], ['p2', '2026-08-31', '500', '0'], ['p2', '2026-09-30', '500', '0'],
        ] as [$p, $date, $value, $flow]) {
            $snap->execute([$this->userId, $this->ids[$p], $date, $value, $flow]);
        }
    }

    public function testMonthlyAndYearlyMetrics(): void
    {
        $s = $this->container->get(PeriodSummaryService::class)->build($this->userId, 'CZK', [], false);
        $brief = array_map(static fn (array $m) => [$m['period'], $m['value_from'], $m['value_to'], $m['flow'], $m['gain'], $m['gain_pct'], $m['interest'], $m['withheld']], $s['months']);
        self::assertSame([
            ['2026-07', '0.00', '1000.00', '0.00', '1000.00', null, '0.00', '0.00'],
            ['2026-08', '1000.00', '1700.00', '600.00', '100.00', '7.69', '30.00', '4.50'],
            ['2026-09', '1700.00', '1600.00', '0.00', '-100.00', '-5.88', '0.00', '0.00'],
        ], $brief);
        self::assertSame(['2026', '0.00', '1600.00', '600.00', '1000.00', '30.00'],
            [$s['years'][0]['period'], $s['years'][0]['value_from'], $s['years'][0]['value_to'], $s['years'][0]['flow'], $s['years'][0]['gain'], $s['years'][0]['interest']]);

        $only = $this->container->get(PeriodSummaryService::class)->build($this->userId, 'CZK', ['portfolio_id' => $this->ids['pf2']], false);
        self::assertSame(['2026-08', '0.00', '500.00', '500.00', '0.00', '0.00'],
            [$only['months'][0]['period'], $only['months'][0]['value_from'], $only['months'][0]['value_to'], $only['months'][0]['flow'], $only['months'][0]['gain'], $only['months'][0]['interest']]);
    }

    public function testIncompleteMonthHasNoReturn(): void
    {
        // Rezerva v září bez ocenění (chybí cena / kurz) → součet 1 100 není skutečná hodnota, výnos se nepočítá
        $this->db->pdo()->prepare("UPDATE daily_snapshots SET value_czk = NULL WHERE user_id = ? AND position_id = ? AND date = '2026-09-30'")
            ->execute([$this->userId, $this->ids['p2']]);
        $s = $this->container->get(PeriodSummaryService::class)->build($this->userId, 'CZK', [], false);
        $sep = array_column($s['months'], null, 'period')['2026-09'];
        self::assertSame([true, null, null, null], [$sep['incomplete'], $sep['gain'], $sep['gain_pct'], $sep['change']]);
        self::assertSame('100.00', array_column($s['months'], null, 'period')['2026-08']['gain']);   // úplný srpen beze změny
        self::assertSame([true, null], [$s['years'][0]['incomplete'], $s['years'][0]['gain']]);
    }

    public function testIncomeOverviewApiAndIsolation(): void
    {
        $i = $this->container->get(IncomeOverviewService::class)->overview($this->userId, 'CZK', 2026);
        self::assertSame(['dividends' => '0.00', 'interest' => '30.00', 'withheld' => '4.50', 'net' => '25.50'], $i['total']);
        self::assertCount(12, $i['months']);
        self::assertSame('30.00', $i['months'][7]['interest']);
        self::assertSame([['Main', 1, '25.50']], array_map(static fn (array $p) => [$p['position'], $p['count'], $p['net']], $i['positions']));
        self::assertSame(['2026-08-20', 'interest', '30.00'], [$i['payments'][0]['date'], $i['payments'][0]['type'], $i['payments'][0]['gross']]);

        $api = $this->call($this->userId, 'GET', '/api/income?year=2026');
        self::assertSame(['200', '25.50'], [(string) $api['status'], $api['body']['total']['net']]);
        self::assertSame(200, $this->call($this->userId, 'GET', '/api/summary')['status']);
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/summary')['body']['months']);
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/income?year=2026')['body']['payments']);
        self::assertSame(404, $this->call($this->adminId, 'GET', "/api/summary?portfolio_id={$this->ids['pf1']}")['status']);
    }

    public function testPeriodReportRenders(): void
    {
        $this->container->get(\MyPortfolio\Repository\UserPreferenceRepository::class)->setWeeklyReport($this->userId, false, [], true, true, true);
        self::assertSame(['monthly' => true, 'yearly' => true], array_intersect_key($this->call($this->userId, 'GET', '/api/settings/weekly-report')['body'], ['monthly' => 1, 'yearly' => 1]));
        $mail = $this->container->get(PeriodReportService::class)->render($this->userId, '2026-08');
        self::assertSame('Měsíční souhrn portfolia — srpen 2026', $mail['subject']);
        self::assertStringContainsString('Spoření', $mail['text']);
        self::assertStringContainsString('Rezerva', $mail['text']);
        self::assertStringContainsString('+100,00 Kč +7,69 %', $mail['text']);
        $preview = $this->call($this->userId, 'GET', '/api/settings/period-report/preview?period=2026');
        self::assertSame(200, $preview['status']);
        self::assertStringContainsString('Roční souhrn portfolia', $preview['raw']);
        self::assertSame(['2026-09', '2025'], [PeriodReportService::previous('monthly', new \DateTimeImmutable('2026-10-01')),
            PeriodReportService::previous('yearly', new \DateTimeImmutable('2026-01-01'))]);
    }
}
