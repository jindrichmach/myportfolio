<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Portfolio;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ocenění, loty a přehled. Kurzy jsou syntetické v testovací měně XTS (ISO 4217),
 * aby se nemíchaly se skutečnými kurzy ČNB: 10 Kč v roce 2001, 20 Kč dnes.
 */
#[Group('integration')]
final class ValuationApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $pdo->exec("DELETE FROM fx_rates WHERE currency = 'XTS'");
        $pdo->exec("INSERT INTO fx_rates (date, currency, amount, rate) VALUES ('2001-01-02', 'XTS', 1, 10), (CURDATE(), 'XTS', 1, 20)");
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
    private function marketPosition(): array
    {
        $institution = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Exchange V', 'type' => 'exchange']);
        $instrument = $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => 'crypto', 'symbol' => 'SYNV', 'name' => 'Synthetic coin', 'quote_currency' => 'XTS', 'price_source' => 'manual',
        ]);
        self::assertSame(201, $instrument['status'], json_encode($instrument['body']) ?: '');
        $account = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $institution['body']['id'], 'name' => 'Main', 'currency' => 'XTS']);
        $portfolio = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Valuation']);
        $position = $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'market', 'portfolio_id' => $portfolio['body']['id'],
            'account_id' => $account['body']['id'], 'instrument_id' => $instrument['body']['id'],
        ]);
        self::assertSame(201, $position['status'], json_encode($position['body']) ?: '');
        return ['position' => $position['body']['id'], 'instrument' => $instrument['body']['id'], 'portfolio' => $portfolio['body']['id']];
    }

    private function tx(array $data): void
    {
        $r = $this->call($this->userId, 'POST', '/api/transactions', $data);
        self::assertSame(201, $r['status'], json_encode($r['body']) ?: '');
    }

    public function testMarketPositionUsesHistoricalRateForCostAndTodayForValue(): void
    {
        $ids = $this->marketPosition();
        $this->tx(['position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => '2001-01-02 10:00', 'quantity' => '2', 'amount' => '100']);
        $this->tx(['position_id' => $ids['position'], 'type' => 'sell', 'executed_at' => '2001-01-03 10:00', 'quantity' => '1', 'amount' => '60']);

        // Bez ceny: hodnota chybí, upozornění, žádná tichá nula.
        $noPrice = $this->call($this->userId, 'GET', '/api/positions/' . $ids['position'] . '/valuation')['body'];
        self::assertNull($noPrice['value']);
        self::assertContains('price_missing', $noPrice['warnings']);

        $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, CURDATE(), 80, 'XTS', 'manual')")
            ->execute([$ids['instrument']]);

        $v = $this->call($this->userId, 'GET', '/api/positions/' . $ids['position'] . '/valuation');
        self::assertSame(200, $v['status']);
        $b = $v['body'];
        self::assertSame('CZK', $b['currency']);
        self::assertSame('1', $b['quantity']);
        self::assertSame('1600.00', $b['value']);      // 1 × 80 XTS × 20 Kč
        self::assertSame('500.00', $b['invested']);    // 50 XTS × 10 Kč (kurz ke dni nákupu)
        self::assertSame('1100.00', $b['unrealized']);
        self::assertSame('100.00', $b['realized']);    // 600 − 500
        self::assertSame('1200.00', $b['total_return']);
        self::assertSame('120.00', $b['return_pct']);  // 1200 / (500 + 500)
        self::assertSame([], $b['warnings']);

        self::assertCount(1, $b['lots']);
        self::assertSame('1', $b['lots'][0]['remaining_quantity']);
        self::assertCount(1, $b['disposals']);
        self::assertSame(1, $b['disposals'][0]['holding_days']);
        self::assertSame('100.00', $b['disposals'][0]['gain_display']);
    }

    public function testManualValuationPlusLaterFlowsAndDashboard(): void
    {
        $ids = $this->marketPosition();
        $this->tx(['position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => '2001-01-02', 'quantity' => '1', 'amount' => '50']);
        $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, CURDATE(), 80, 'XTS', 'manual')")
            ->execute([$ids['instrument']]);

        $bank = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Bank V', 'type' => 'bank']);
        $account = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $bank['body']['id'], 'name' => 'Savings']);
        $position = $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'manual', 'portfolio_id' => $ids['portfolio'], 'account_id' => $account['body']['id'], 'name' => 'Spořák',
        ]);
        $pid = $position['body']['id'];
        $this->tx(['position_id' => $pid, 'type' => 'deposit', 'executed_at' => '2001-01-02', 'amount' => '1000']);

        $before = $this->call($this->userId, 'GET', "/api/positions/$pid/valuation")['body'];
        self::assertSame('1000.00', $before['value']);
        self::assertSame([], $before['warnings']); // spořicí účet (banka) je přesný i bez ocenění

        $created = $this->call($this->userId, 'POST', "/api/positions/$pid/valuations", ['date' => '2001-06-30', 'value' => '1200']);
        self::assertSame(201, $created['status'], json_encode($created['body']) ?: '');
        $this->tx(['position_id' => $pid, 'type' => 'deposit', 'executed_at' => '2001-07-01', 'amount' => '100']);
        $this->tx(['position_id' => $pid, 'type' => 'interest', 'executed_at' => '2001-07-02', 'amount' => '5']);

        $after = $this->call($this->userId, 'GET', "/api/positions/$pid/valuation")['body'];
        self::assertSame('1305.00', $after['value']);
        self::assertSame('1100.00', $after['invested']);
        self::assertSame('205.00', $after['total_return']);
        self::assertSame('5.00', $after['income']);

        // Seznam pozic: ruční pozice nese dnešní hodnotu v měně pozice, tržní ne.
        $list = array_column($this->call($this->userId, 'GET', '/api/positions')['body'], null, 'id');
        self::assertSame(['value' => '1305.00', 'valuation_date' => '2001-06-30'], $list[$pid]['current_value']);
        self::assertNull($list[$ids['position']]['current_value']);

        $dash = $this->call($this->userId, 'GET', '/api/dashboard');
        self::assertSame(200, $dash['status']);
        self::assertSame('2905.00', $dash['body']['totals']['value']);   // 1600 + 1305
        self::assertSame('1600.00', $dash['body']['totals']['invested']); // 500 + 1100
        self::assertSame(0, $dash['body']['totals']['incomplete']);
        $categories = array_column($dash['body']['allocation']['category'], 'value', 'label');
        self::assertSame(['crypto' => '1600.00', 'cash' => '1305.00'], $categories);
        self::assertSame('SYNV', $dash['body']['crypto'][0]['symbol']);

        // Ocenění jen u ručních pozic; validace data a hodnoty.
        self::assertSame(422, $this->call($this->userId, 'POST', '/api/positions/' . $ids['position'] . '/valuations', ['date' => '2001-06-30', 'value' => '1'])['status']);
        $bad = $this->call($this->userId, 'POST', "/api/positions/$pid/valuations", ['date' => '2999-01-01', 'value' => 12.5]);
        self::assertSame(400, $bad['status']);
        self::assertArrayHasKey('date', $bad['body']['error']['fields']);
        self::assertArrayHasKey('value', $bad['body']['error']['fields']);
    }

    public function testOtherUserCannotReadOrDeleteValuations(): void
    {
        $bank = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Bank W', 'type' => 'bank']);
        $account = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $bank['body']['id'], 'name' => 'Savings']);
        $portfolio = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Cash W']);
        $pid = $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'manual', 'portfolio_id' => $portfolio['body']['id'], 'account_id' => $account['body']['id'], 'name' => 'Portu',
        ])['body']['id'];
        $list = $this->call($this->userId, 'POST', "/api/positions/$pid/valuations", ['date' => '2001-06-30', 'value' => '10']);
        $valuationId = $list['body'][0]['id'];

        self::assertSame(404, $this->call($this->adminId, 'GET', "/api/positions/$pid/valuation")['status']);
        self::assertSame(404, $this->call($this->adminId, 'GET', "/api/positions/$pid/valuations")['status']);
        self::assertSame(404, $this->call($this->adminId, 'POST', "/api/positions/$pid/valuations", ['date' => '2001-06-30', 'value' => '1'])['status']);
        self::assertSame(404, $this->call($this->adminId, 'DELETE', "/api/valuations/$valuationId")['status']);
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/dashboard')['body']['positions']);

        self::assertSame(204, $this->call($this->userId, 'DELETE', "/api/valuations/$valuationId")['status']);
        self::assertSame([], $this->call($this->userId, 'GET', "/api/positions/$pid/valuations")['body']);
    }
}
