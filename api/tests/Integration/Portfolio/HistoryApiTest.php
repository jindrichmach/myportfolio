<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Portfolio;

use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Snapshoty a historie. Syntetická měna XTS: 1 XTS = 10 Kč (kurz platí 7 dní zpět),
 * cena instrumentu 80 XTS od předvčerejška, 100 XTS dnes.
 */
#[Group('integration')]
final class HistoryApiTest extends ApiTestCase
{
    private string $today;
    private string $d2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->d2 = (new \DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');
        $pdo = $this->db->pdo();
        $pdo->exec("DELETE FROM fx_rates WHERE currency = 'XTS'");
        $pdo->prepare("INSERT INTO fx_rates (date, currency, amount, rate) VALUES (?, 'XTS', 1, 10), (?, 'XTS', 1, 10)")
            ->execute([$this->d2, $this->today]);
        $pdo->prepare("UPDATE users SET display_currency = 'CZK' WHERE id = ?")->execute([$this->userId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->exec("DELETE FROM fx_rates WHERE currency = 'XTS'");
        }
        parent::tearDown();
    }

    /** @return array{position:int,instrument:int,portfolio:int} */
    private function setupPosition(): array
    {
        $institution = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Exchange H', 'type' => 'exchange']);
        $instrument = $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => 'etf', 'symbol' => 'SYNH', 'name' => 'Synthetic ETF', 'quote_currency' => 'XTS', 'price_source' => 'manual',
        ]);
        $account = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $institution['body']['id'], 'name' => 'Main', 'currency' => 'XTS']);
        $portfolio = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'History']);
        $position = $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'market', 'portfolio_id' => $portfolio['body']['id'],
            'account_id' => $account['body']['id'], 'instrument_id' => $instrument['body']['id'],
        ]);
        self::assertSame(201, $position['status'], json_encode($position['body']) ?: '');
        $ins = $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, ?, ?, 'XTS', 'manual')");
        $ins->execute([$instrument['body']['id'], $this->d2, '80']);
        $ins->execute([$instrument['body']['id'], $this->today, '100']);
        return ['position' => $position['body']['id'], 'instrument' => $instrument['body']['id'], 'portfolio' => $portfolio['body']['id']];
    }

    public function testHistoryMatchesDashboardAndComputesMetrics(): void
    {
        $ids = $this->setupPosition();
        $r = $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => $this->d2 . ' 10:00', 'quantity' => '2', 'amount' => '160',
        ]);
        self::assertSame(201, $r['status'], json_encode($r['body']) ?: '');

        $h = $this->call($this->userId, 'GET', '/api/history?range=1M');
        self::assertSame(200, $h['status'], json_encode($h['body']) ?: '');
        $points = array_column($h['body']['points'], null, 'date');
        self::assertCount(3, $points);
        self::assertSame('1600.00', $points[$this->d2]['value']);  // 2 × 80 XTS × 10 Kč
        self::assertSame('1600.00', $points[$this->d2]['invested']);
        self::assertSame('2000.00', $points[$this->today]['value']); // 2 × 100 XTS × 10 Kč

        $dash = $this->call($this->userId, 'GET', '/api/dashboard')['body'];
        self::assertSame($dash['totals']['value'], $points[$this->today]['value']);
        self::assertSame($dash['totals']['invested'], $points[$this->today]['invested']);

        $m = $h['body']['metrics'];
        self::assertSame('1600.00', $m['net_flow']);
        self::assertSame('400.00', $m['gain']);
        self::assertSame('25.00', $m['simple_pct']);
        self::assertSame('25.00', $m['twr_pct']);
        self::assertNotNull($m['xirr_pct']);

        // Po úpravě transakce se snapshoty přepočítají (fronta snapshot_dirty).
        $txId = $r['body']['id'];
        $u = $this->call($this->userId, 'PUT', "/api/transactions/$txId", ['type' => 'buy', 'executed_at' => $this->d2 . ' 10:00', 'quantity' => '3', 'amount' => '240']);
        self::assertSame(200, $u['status'], json_encode($u['body']) ?: '');
        self::assertNotSame([], $this->container->get(SnapshotRepository::class)->dirty($this->userId));
        $after = array_column($this->call($this->userId, 'GET', '/api/history?range=1M')['body']['points'], null, 'date');
        self::assertSame('3000.00', $after[$this->today]['value']);
        self::assertSame([], $this->container->get(SnapshotRepository::class)->dirty($this->userId));

        // Úrovně: portfolio, třída aktiv, pozice.
        self::assertCount(3, $this->call($this->userId, 'GET', '/api/history?scope=portfolio&id=' . $ids['portfolio'] . '&range=1M')['body']['points']);
        self::assertCount(3, $this->call($this->userId, 'GET', '/api/history?scope=class&id=etf&range=ALL')['body']['points']);
        self::assertSame([], $this->call($this->userId, 'GET', '/api/history?scope=class&id=crypto&range=ALL')['body']['points']);
        self::assertCount(3, $this->call($this->userId, 'GET', '/api/history?scope=position&id=' . $ids['position'] . '&range=ALL')['body']['points']);
    }

    public function testValidationAndIsolation(): void
    {
        $ids = $this->setupPosition();
        self::assertSame(400, $this->call($this->userId, 'GET', '/api/history?range=5Y')['status']);
        self::assertSame(400, $this->call($this->userId, 'GET', '/api/history?scope=position')['status']);
        self::assertSame(404, $this->call($this->adminId, 'GET', '/api/history?scope=position&id=' . $ids['position'])['status']);
        self::assertSame(404, $this->call($this->adminId, 'GET', '/api/history?scope=portfolio&id=' . $ids['portfolio'])['status']);
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/history')['body']['points']);
    }

    public function testRefreshFillsDaysMissedWithoutCron(): void
    {
        $p = $this->setupPosition();
        self::assertSame(201, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $p['position'], 'type' => 'buy',
            'executed_at' => "{$this->d2} 10:00", 'quantity' => '1', 'amount' => '80'])['status']);
        $this->call($this->userId, 'GET', '/api/history?range=1M');
        // Cron neběžel a aplikaci nikdo neotevřel: chybí včerejšek i dnešek
        $this->db->pdo()->prepare('DELETE FROM daily_snapshots WHERE user_id = ? AND date > ?')->execute([$this->userId, $this->d2]);
        $this->db->pdo()->prepare('DELETE FROM snapshot_dirty WHERE user_id = ?')->execute([$this->userId]);

        $this->call($this->userId, 'GET', '/api/history?range=1M');
        $repo = $this->container->get(SnapshotRepository::class);
        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
        self::assertNotNull($repo->find($this->userId, $p['position'], $yesterday), 'díra po dni bez cronu');
        self::assertNotNull($repo->find($this->userId, $p['position'], $this->today));
    }
}
