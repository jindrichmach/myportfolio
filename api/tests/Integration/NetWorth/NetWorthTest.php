<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\NetWorth;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Čisté jmění na syntetických datech v CZK: investice (1 pozice za 1 000), nemovitost 3 000 000,
 * hypotéka 1 000 000 / 6 % / splátka 10 000 od 40 dní zpět (1 splátka → 995 000), validace, izolace,
 * vyloučení z investičních metrik.
 */
#[Group('integration')]
final class NetWorthTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->pdo()->prepare("UPDATE users SET display_currency = 'CZK' WHERE id = ?")->execute([$this->userId]);
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable('today'))->modify("$offset days")->format('Y-m-d');
    }

    private function investment(): void
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Broker N', 'type' => 'broker'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Main', 'currency' => 'CZK'])['body']['id'];
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Jmění'])['body']['id'];
        $ins = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => 'etf', 'symbol' => 'XTSN', 'name' => 'Synthetic XTSN',
            'quote_currency' => 'CZK', 'price_source' => 'manual'])['body']['id'];
        $price = $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, ?, 1000, 'CZK', 'manual')");
        for ($d = -45; $d <= 0; $d++) {
            $price->execute([$ins, $this->day($d)]);
        }
        $pos = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'market', 'portfolio_id' => $pf, 'account_id' => $acc, 'instrument_id' => $ins])['body']['id'];
        self::assertSame(201, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pos, 'type' => 'buy',
            'executed_at' => $this->day(-45) . ' 10:00', 'quantity' => '1', 'amount' => '1000'])['status']);
    }

    public function testOverviewHistoryAndIsolation(): void
    {
        $this->investment();
        $house = $this->call($this->userId, 'POST', '/api/net-worth/items', ['kind' => 'real_estate', 'name' => 'Byt', 'date' => $this->day(-30), 'value' => '3000000']);
        self::assertSame(201, $house['status'], $house['raw']);
        $loan = $this->call($this->userId, 'POST', '/api/net-worth/items', ['kind' => 'liability', 'name' => 'Hypotéka',
            'date' => $this->day(-40), 'value' => '1000000', 'rate_pct' => '6', 'payment' => '10000']);
        self::assertSame(201, $loan['status'], $loan['raw']);

        $o = $this->call($this->userId, 'GET', '/api/net-worth')['body'];
        self::assertSame(['1000.00', '3000000.00', '995000.00', '2006000.00'], [$o['investments'], $o['assets'], $o['liabilities'], $o['net_worth']]);
        $items = array_column($o['items'], null, 'kind');
        self::assertSame(['6', '10000.00'], [$items['liability']['rate_pct'], $items['liability']['payment']]);
        self::assertNotNull($items['liability']['payoff']);

        // Historie: před ocenitelným bytem jen investice − úvěr; dnes vše
        $points = array_column($this->call($this->userId, 'GET', '/api/net-worth/history?range=3M')['body']['points'], null, 'date');
        self::assertSame(['0.00', '1000000.00', '-999000.00'], [$points[$this->day(-35)]['assets'], $points[$this->day(-35)]['liabilities'], $points[$this->day(-35)]['net_worth']]);
        self::assertSame('2006000.00', $points[$this->day(0)]['net_worth']);

        // Korekce zůstatku po mimořádné splátce (úrok a splátka beze změny) a ocenění bytu
        $id = $loan['body']['id'];
        self::assertSame(201, $this->call($this->userId, 'POST', "/api/net-worth/items/$id/entries", ['date' => $this->day(0), 'value' => '800000'])['status']);
        // Zůstatek na víc desetinných míst nesmí shodit výhled splátek
        $this->call($this->userId, 'POST', "/api/net-worth/items/$id/entries", ['date' => $this->day(0), 'value' => '800000.1234']);
        self::assertSame(200, $this->call($this->userId, 'GET', "/api/net-worth/items/$id/entries")['status']);
        $this->call($this->userId, 'POST', "/api/net-worth/items/$id/entries", ['date' => $this->day(0), 'value' => '800000']);
        $entries = $this->call($this->userId, 'GET', "/api/net-worth/items/$id/entries")['body'];
        self::assertCount(2, $entries['entries']);
        self::assertNotEmpty($entries['schedule']);
        self::assertSame('800000.00', $this->call($this->userId, 'GET', '/api/net-worth')['body']['liabilities']);

        // Investiční metriky a alokace závazky ani nemovitosti nezahrnují
        self::assertSame('1000.00', $this->call($this->userId, 'GET', '/api/dashboard')['body']['totals']['value']);
        self::assertSame('1000.00', $this->call($this->userId, 'GET', '/api/allocation')['body']['total']);

        // Izolace: cizí uživatel položky nevidí ani nemění
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/net-worth')['body']['items']);
        self::assertSame(404, $this->call($this->adminId, 'GET', "/api/net-worth/items/$id/entries")['status']);
        self::assertSame(404, $this->call($this->adminId, 'POST', "/api/net-worth/items/$id/entries", ['date' => $this->day(0), 'value' => '1'])['status']);
        self::assertSame(404, $this->call($this->adminId, 'DELETE', "/api/net-worth/items/$id")['status']);
        self::assertSame(404, $this->call($this->adminId, 'DELETE', "/api/net-worth/entries/{$entries['entries'][0]['id']}")['status']);

        // Archivace vyřadí položku z přehledu
        self::assertSame(200, $this->call($this->userId, 'PUT', "/api/net-worth/items/{$house['body']['id']}", ['name' => 'Byt', 'archived' => true])['status']);
        self::assertSame('0.00', $this->call($this->userId, 'GET', '/api/net-worth')['body']['assets']);
        self::assertSame(204, $this->call($this->userId, 'DELETE', "/api/net-worth/items/$id")['status']);
    }

    public function testValidation(): void
    {
        $bad = $this->call($this->userId, 'POST', '/api/net-worth/items', ['kind' => 'liability', 'name' => 'Úvěr', 'date' => '2026-13-01', 'value' => '-5']);
        self::assertSame(400, $bad['status']);
        self::assertSame(['date', 'value', 'rate_pct', 'payment'], array_keys($bad['body']['error']['fields']));
        self::assertSame(400, $this->call($this->userId, 'POST', '/api/net-worth/items', ['kind' => 'yacht', 'name' => 'X'])['status']);
        // Penzijko a DIP jsou investice (pozice u penzijní společnosti), ne položka čistého jmění
        self::assertSame(400, $this->call($this->userId, 'POST', '/api/net-worth/items', ['kind' => 'pension', 'name' => 'DPS'])['status']);
        self::assertSame(201, $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Pension Co', 'type' => 'pension'])['status']);
        self::assertSame(400, $this->call($this->userId, 'GET', '/api/net-worth/history?range=5Y')['status']);
        $other = $this->call($this->userId, 'POST', '/api/net-worth/items', ['kind' => 'other', 'name' => 'Auto']);
        self::assertSame(201, $other['status'], $other['raw']);
        self::assertNull(array_column($this->call($this->userId, 'GET', '/api/net-worth')['body']['items'], null, 'kind')['other']['value']);
    }
}
