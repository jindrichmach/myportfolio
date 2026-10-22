<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Report;

use DI\Container;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Service\Report\WeeklyReportBuilder;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Týdenní report nad syntetickými snapshoty (období 8. 9. → 15. 9. 2026). */
#[Group('integration')]
final class WeeklyReportTest extends ApiTestCase
{
    /** @return array<string,int> */
    private function positions(): array
    {
        $bank = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Report Bank', 'type' => 'bank']);
        $acc1 = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $bank['body']['id'], 'name' => 'One']);
        $acc2 = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $bank['body']['id'], 'name' => 'Two']);
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Report']);
        $make = fn (array $acc, string $name) => $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'manual', 'portfolio_id' => $pf['body']['id'], 'account_id' => $acc['body']['id'], 'name' => $name,
        ])['body']['id'];
        return ['p1' => $make($acc1, 'Old'), 'p2' => $make($acc1, 'New'), 'p3' => $make($acc2, 'Archived'), 'p4' => $make($acc2, 'NoPrice')];
    }

    private function snapshot(int $position, string $date, ?string $value, string $flow = '0'): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO daily_snapshots (user_id, position_id, date, value_czk, invested_czk, flow_czk) VALUES (?, ?, ?, ?, 0, ?)'
        )->execute([$this->userId, $position, $date, $value, $flow]);
    }

    public function testBuildsPortfolioAccountPositionMetrics(): void
    {
        $p = $this->positions();
        $tx = fn (string $at) => $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $p['p1'], 'type' => 'deposit', 'executed_at' => $at, 'amount' => '50']);
        self::assertSame(201, $tx('2026-09-10 12:00')['status']);
        self::assertSame(201, $tx('2026-09-08 12:00')['status']); // den začátku období — už v počáteční hodnotě
        $this->db->pdo()->prepare('UPDATE positions SET archived_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$p['p3']]);
        $this->db->pdo()->prepare('DELETE FROM daily_snapshots WHERE user_id = ?')->execute([$this->userId]);

        $this->snapshot($p['p1'], '2026-09-08', '1000');
        $this->snapshot($p['p1'], '2026-09-10', '1050', '50');
        $this->snapshot($p['p1'], '2026-09-15', '1100');
        $this->snapshot($p['p2'], '2026-09-12', '200', '200');   // nová pozice v týdnu
        $this->snapshot($p['p2'], '2026-09-15', '210');
        $this->snapshot($p['p3'], '2026-09-08', '0');            // archivovaná bez pohybu → vynechá se
        $this->snapshot($p['p3'], '2026-09-15', '0');
        $this->snapshot($p['p4'], '2026-09-08', '300');
        $this->snapshot($p['p4'], '2026-09-15', null);           // chybí cena

        $r = $this->container->get(WeeklyReportBuilder::class)->build($this->userId, 'CZK', new \DateTimeImmutable('2026-09-15'));

        self::assertSame(['2026-09-08', '2026-09-15'], [$r['from'], $r['to']]);
        self::assertCount(1, $r['portfolios']);
        [$acc1, $acc2] = $r['portfolios'][0]['accounts'];
        self::assertStringEndsWith('One', $acc1['name']);
        self::assertSame(['New', 'Old'], array_column($acc1['positions'], 'name')); // abecedně jako seznam pozic
        self::assertSame(['NoPrice'], array_column($acc2['positions'], 'name'));

        [$new, $old] = $acc1['positions'];
        self::assertSame(['1000.00', '1100.00', '100.00', '10.00', '50.00', '50.00', '4.88'],
            [$old['value_from'], $old['value_to'], $old['change'], $old['change_pct'], $old['flow'], $old['gain'], $old['gain_pct']]);
        self::assertSame(['0.00', '210.00', null, '200.00', '10.00', '10.00'],
            [$new['value_from'], $new['value_to'], $new['change_pct'], $new['flow'], $new['gain'], $new['gain_pct']]);
        self::assertSame(1, $old['transactions']);

        self::assertSame([null, null, null], [$acc2['positions'][0]['value_to'], $acc2['positions'][0]['change'], $acc2['positions'][0]['gain']]);
        self::assertTrue($acc2['incomplete']);
        self::assertSame(['1310.00', '310.00', '250.00', '60.00'], [$acc1['value_to'], $acc1['change'], $acc1['flow'], $acc1['gain']]);
        self::assertTrue($r['total']['incomplete']);
        self::assertSame('1310.00', $r['total']['value_to']);

        self::assertSame(['2026-09-10'], array_column($r['transactions'], 'date'));
        self::assertSame(['deposit', '50.00', 'CZK'], [$r['transactions'][0]['type'], $r['transactions'][0]['amount'], $r['transactions'][0]['currency']]);
    }

    public function testSettingsAndPreview(): void
    {
        $this->db->pdo()->prepare('UPDATE users SET weekly_report_enabled = 0, weekly_report_recipients = NULL, weekly_report_sent_at = NULL WHERE id IN (?, ?)')
            ->execute([$this->userId, $this->adminId]);
        $get = $this->call($this->userId, 'GET', '/api/settings/weekly-report');
        self::assertSame(['enabled' => false, 'recipients' => []], array_intersect_key($get['body'], ['enabled' => 1, 'recipients' => 1]));

        $bad = $this->call($this->userId, 'PUT', '/api/settings/weekly-report', ['enabled' => true, 'recipients' => ['not-an-email']]);
        self::assertSame(400, $bad['status']);
        $tooMany = $this->call($this->userId, 'PUT', '/api/settings/weekly-report', ['enabled' => true, 'recipients' => array_map(fn ($i) => "r$i@example.invalid", range(1, 6))]);
        self::assertSame(400, $tooMany['status']);

        $ok = $this->call($this->userId, 'PUT', '/api/settings/weekly-report', ['enabled' => true, 'recipients' => 'A@example.invalid, b@example.invalid; a@example.invalid']);
        self::assertSame(200, $ok['status'], json_encode($ok['body']) ?: '');
        self::assertSame([true, true, ['a@example.invalid', 'b@example.invalid']], [$ok['body']['enabled'], $ok['body']['detailed'], $ok['body']['recipients']]);
        self::assertContains($this->userId, $this->container->get(UserPreferenceRepository::class)->weeklyReportUsers());
        self::assertNotContains($this->adminId, $this->container->get(UserPreferenceRepository::class)->weeklyReportUsers());

        $preview = $this->call($this->userId, 'GET', '/api/settings/weekly-report/preview');
        self::assertSame(200, $preview['status']);
        self::assertStringContainsString('text/html', $preview['headers']['Content-Type'][0]);
        self::assertStringContainsString('MyPortfolio', $preview['raw']);

        // Bez detailu jen portfolia a účty — pozice (instrumenty) v e-mailu nejsou.
        $this->positions();
        $detailed = $this->call($this->userId, 'GET', '/api/settings/weekly-report/preview')['raw'];
        self::assertStringContainsString('NoPrice', $detailed);
        $off = $this->call($this->userId, 'PUT', '/api/settings/weekly-report', ['enabled' => true, 'detailed' => false, 'recipients' => []]);
        self::assertFalse($off['body']['detailed']);
        $brief = $this->call($this->userId, 'GET', '/api/settings/weekly-report/preview')['raw'];
        self::assertStringNotContainsString('NoPrice', $brief);
        self::assertStringContainsString('Synthetic Report Bank', $brief);
    }

    public function testSendUsesConfiguredRecipients(): void
    {
        $this->container->get(UserPreferenceRepository::class)->setWeeklyReport($this->userId, true, ['a@example.invalid', 'b@example.invalid']);
        $sent = [];
        $mailer = $this->createMock(Mailer::class);
        $mailer->method('render')->willReturnCallback(fn () => ['subject' => '', 'html' => '', 'text' => '']);
        $mailer->expects(self::once())->method('sendTemplate')->willReturnCallback(function (string $code, string $locale, array $to, array $vars) use (&$sent): string {
            $sent = [$code, $to, $vars['subject']];
            return '250 OK';
        });
        $container = $this->container;
        self::assertInstanceOf(Container::class, $container);
        $container->set(Mailer::class, $mailer);

        $send = $this->call($this->userId, 'POST', '/api/settings/weekly-report/send');
        self::assertSame(200, $send['status'], $send['raw']);
        self::assertSame(['a@example.invalid', 'b@example.invalid'], $send['body']['sent_to']);
        self::assertSame('weekly_report', $sent[0]);
        self::assertStringStartsWith('Týdenní přehled portfolia', $sent[2]);
        self::assertNotNull($this->call($this->userId, 'GET', '/api/settings/weekly-report')['body']['sent_at']);
    }
}
