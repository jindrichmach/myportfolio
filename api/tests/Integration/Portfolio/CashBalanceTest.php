<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Portfolio;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Hotovost na účtu: zadaný zůstatek → rozdíl jako vklad / výběr, hodnota = investováno, žádný výnos. */
#[Group('integration')]
final class CashBalanceTest extends ApiTestCase
{
    private function day(int $offset): string
    {
        return (new \DateTimeImmutable('today'))->modify("$offset days")->format('Y-m-d');
    }

    public function testBalanceChangesBecomeFlows(): void
    {
        $this->db->pdo()->prepare("UPDATE users SET display_currency = 'CZK' WHERE id = ?")->execute([$this->userId]);
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Cash Broker', 'type' => 'broker'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Main', 'currency' => 'CZK'])['body']['id'];
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Cash'])['body']['id'];
        $created = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'manual', 'is_cash' => true, 'portfolio_id' => $pf, 'account_id' => $acc]);
        self::assertSame(201, $created['status'], $created['raw']);
        self::assertSame(['Hotovost CZK', true, 'CZK'], [$created['body']['name'], $created['body']['is_cash'], $created['body']['currency']]);
        $id = $created['body']['id'];
        $set = fn (string $date, mixed $balance) => $this->call($this->userId, 'POST', "/api/positions/$id/cash-balance", ['date' => $date, 'balance' => $balance]);

        $first = $set($this->day(-10), '1000');
        self::assertSame(200, $first['status'], $first['raw']);
        self::assertSame(['1000', '0', '1000'], [$first['body']['balance'], $first['body']['previous'], $first['body']['delta']]);
        $second = $set($this->day(-5), '750.50')['body'];
        self::assertSame(['750.5', '-249.5'], [$second['balance'], $second['delta']]);
        self::assertNull($set($this->day(-5), '750.5')['body']['transaction_id']);   // beze změny nic nezapíše

        $tx = $this->call($this->userId, 'GET', "/api/transactions?position_id=$id&sort=executed_at")['body']['data'];
        self::assertSame([['deposit', '1000'], ['withdrawal', '249.5']], array_map(static fn (array $t) => [$t['type'], $t['amount']], $tx));

        $v = $this->call($this->userId, 'GET', "/api/positions/$id/valuation")['body'];
        self::assertSame(['750.50', '750.50', 'cash'], [$v['value'], $v['invested'], $v['category']]);
        self::assertNotContains('valuation_missing', $v['warnings']);

        // Ocenění u hotovosti (např. zůstatek z bankovního avíza) se nepočítá — zůstatek je jen součet toků,
        // jinak by se k výpisu přičetl i další vklad a zůstatek by se zdvojil
        $this->db->pdo()->prepare("INSERT INTO manual_valuations (user_id, position_id, date, value, currency) VALUES (?, ?, ?, 900, 'CZK')")
            ->execute([$this->userId, $id, $this->day(-1)]);
        self::assertSame('750.50', $this->call($this->userId, 'GET', "/api/positions/$id/valuation")['body']['value']);
        self::assertSame('249.5', $set($this->day(0), '1000')['body']['delta']);
        self::assertSame('1000.00', $this->call($this->userId, 'GET', "/api/positions/$id/valuation")['body']['value']);

        // Validace: dřív než poslední pohyb, záporný, float
        self::assertArrayHasKey('date', $set($this->day(-7), '10')['body']['error']['fields']);
        self::assertArrayHasKey('balance', $set($this->day(-1), '-5')['body']['error']['fields']);
        self::assertArrayHasKey('balance', $set($this->day(-1), 10.5)['body']['error']['fields']);

        self::assertSame('cash_balance', $this->call($this->userId, 'POST', "/api/positions/$id/valuations", ['date' => $this->day(0), 'value' => '1'])['body']['error']['code']);

        // Jiná ruční pozice zůstatek nastavit nejde; cizí uživatel pozici nevidí
        $savings = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'manual', 'name' => 'Spoření', 'portfolio_id' => $pf, 'account_id' => $acc])['body']['id'];
        self::assertSame('not_cash', $this->call($this->userId, 'POST', "/api/positions/$savings/cash-balance", ['date' => $this->day(0), 'balance' => '1'])['body']['error']['code']);
        self::assertSame(404, $this->call($this->adminId, 'POST', "/api/positions/$id/cash-balance", ['date' => $this->day(0), 'balance' => '1'])['status']);
    }
}
