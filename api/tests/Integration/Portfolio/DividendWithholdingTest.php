<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Portfolio;

use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dividenda se srážkovou daní (hrubá částka + daň jako fee): příjem hrubý, daň v poplatcích pozice,
 * tok historie = čistá výplata. Syntetická měna XTS: 1 XTS = 10 Kč.
 */
#[Group('integration')]
final class DividendWithholdingTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $pdo->exec("DELETE FROM fx_rates WHERE currency = 'XTS'");
        $ins = $pdo->prepare("INSERT INTO fx_rates (date, currency, amount, rate) VALUES (?, 'XTS', 1, 10)");
        foreach ([0, 1, 2, 3] as $d) {
            $ins->execute([(new \DateTimeImmutable('today'))->modify("-$d days")->format('Y-m-d')]);
        }
        $pdo->prepare("UPDATE users SET display_currency = 'CZK' WHERE id = ?")->execute([$this->userId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->exec("DELETE FROM fx_rates WHERE currency = 'XTS'");
        }
        parent::tearDown();
    }

    public function testWithholdingTaxIsFeeAndHistoryFlowIsNet(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $d2 = (new \DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Broker D', 'type' => 'broker']);
        $ins = $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => 'stock', 'symbol' => 'SYND', 'name' => 'Synthetic Dividend Co', 'quote_currency' => 'XTS', 'price_source' => 'manual',
        ]);
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst['body']['id'], 'name' => 'Main', 'currency' => 'XTS']);
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Dividends']);
        $pos = $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'market', 'portfolio_id' => $pf['body']['id'], 'account_id' => $acc['body']['id'], 'instrument_id' => $ins['body']['id'],
        ])['body']['id'];
        $price = $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, ?, 80, 'XTS', 'manual')");
        $price->execute([$ins['body']['id'], $d2]);
        $price->execute([$ins['body']['id'], $today]);

        self::assertSame(201, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pos, 'type' => 'buy', 'executed_at' => "$d2 10:00", 'quantity' => '2', 'amount' => '160'])['status']);
        $div = $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $pos, 'type' => 'dividend', 'executed_at' => "$today 09:00", 'amount' => '10', 'fee' => '1.5', 'fee_currency' => 'XTS',
        ]);
        self::assertSame(201, $div['status'], json_encode($div['body']) ?: '');

        $v = $this->call($this->userId, 'GET', "/api/positions/$pos/valuation")['body'];
        self::assertSame('100.00', $v['income']);   // hrubá dividenda 10 XTS
        self::assertSame('15.00', $v['fees']);      // srážková daň 1,5 XTS

        $this->call($this->userId, 'GET', '/api/history?range=1M');  // přepočet snapshotů
        $snap = $this->container->get(SnapshotRepository::class)->find($this->userId, $pos, $today);
        self::assertSame('-85.0000', $snap['flow_czk'] ?? null);    // čistá výplata 8,5 XTS opustila pozici
    }
}
