<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Allocation;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cílová alokace: odchylky, návrh vkladu, plný rebalancing s FIFO odhadem zdanitelných kusů, cíle instrumentů,
 * validace a izolace. Syntetické instrumenty XTSA (krypto) a XTSE / XTSF (ETF) kotované v CZK.
 */
#[Group('integration')]
final class AllocationTest extends ApiTestCase
{
    private int $portfolio;

    /** @var array<string,int> */
    private array $instruments = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->pdo()->prepare("UPDATE users SET display_currency = 'CZK' WHERE id = ?")->execute([$this->userId]);
    }

    private function instrument(string $class, string $symbol, ?string $price): int
    {
        $r = $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => $class, 'symbol' => $symbol, 'name' => "Synthetic $symbol", 'quote_currency' => 'CZK', 'price_source' => 'manual',
        ]);
        self::assertSame(201, $r['status'], $r['raw']);
        if ($price !== null) {
            $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, CURDATE(), ?, 'CZK', 'manual')")
                ->execute([$r['body']['id'], $price]);
        }
        return $this->instruments[$symbol] = $r['body']['id'];
    }

    /** @param list<array{0:string,1:string,2:string}> $buys datum, kusy, částka */
    private function position(int $account, int $instrument, array $buys): int
    {
        $p = $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'market', 'portfolio_id' => $this->portfolio, 'account_id' => $account, 'instrument_id' => $instrument,
        ]);
        self::assertSame(201, $p['status'], $p['raw']);
        foreach ($buys as [$at, $qty, $amount]) {
            $t = $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $p['body']['id'], 'type' => 'buy',
                'executed_at' => $at, 'quantity' => $qty, 'amount' => $amount]);
            self::assertSame(201, $t['status'], $t['raw']);
        }
        return $p['body']['id'];
    }

    private function setUpHoldings(): void
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Broker A', 'type' => 'broker'])['body']['id'];
        $account = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Main', 'currency' => 'CZK'])['body']['id'];
        $this->portfolio = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Alokace'])['body']['id'];
        $recent = (new \DateTimeImmutable('-1 year'))->format('Y-m-d 10:00');
        $this->position($account, $this->instrument('crypto', 'XTSA', '4000'), [['2020-01-10 10:00', '1', '1000'], [$recent, '1', '1000']]);
        $this->position($account, $this->instrument('etf', 'XTSE', '200'), [['2024-03-01 10:00', '10', '1000']]);
        $this->instrument('etf', 'XTSF', null);
    }

    private function save(array $classes, ?int $portfolioId = null): array
    {
        return $this->call($this->userId, 'PUT', '/api/allocation/targets', ['portfolio_id' => $portfolioId, 'classes' => $classes]);
    }

    public function testDeviationDepositAndRebalance(): void
    {
        $this->setUpHoldings();

        $none = $this->call($this->userId, 'GET', '/api/allocation')['body'];
        self::assertFalse($none['has_targets']);
        self::assertSame('10000.00', $none['total']);
        self::assertSame([['crypto', '80.00'], ['etf', '20.00']], array_map(static fn (array $c) => [$c['category'], $c['pct']], $none['classes']));
        self::assertSame([], $none['rebalance']);

        $saved = $this->save([
            ['category' => 'crypto', 'target_pct' => '20', 'band_pct' => '5'],
            ['category' => 'etf', 'target_pct' => '70'],
            ['category' => 'cash', 'target_pct' => '10'],
        ]);
        self::assertSame(200, $saved['status'], $saved['raw']);
        $a = $saved['body'];
        self::assertTrue($a['has_targets']);
        $classes = array_column($a['classes'], null, 'category');
        self::assertSame(['80.00', '20.00', '60.00', '6000.00', true], [$classes['crypto']['pct'], $classes['crypto']['target_pct'],
            $classes['crypto']['diff_pct'], $classes['crypto']['diff_amount'], $classes['crypto']['out_of_band']]);
        self::assertSame(['0.00', '10.00', '-1000.00'], [$classes['cash']['pct'], $classes['cash']['target_pct'], $classes['cash']['diff_amount']]);
        self::assertSame(3, $a['out_of_band']);

        // Plný rebalancing: prodat 1,5 XTSA (FIFO: starý lot osvobozený, 0,5 z ročního lotu zdanitelné), koupit ETF a hotovost
        self::assertSame([['sell', 'crypto', '6000.00'], ['buy', 'etf', '5000.00'], ['buy', 'cash', '1000.00']],
            array_map(static fn (array $l) => [$l['action'], $l['category'], $l['amount']], $a['rebalance']));
        $sell = $a['rebalance'][0]['positions'][0];
        self::assertSame(['1.5', '0.5'], [$sell['quantity'], $sell['taxable_quantity']]);

        // Vklad 5 000 bez prodeje: po vkladu chybí ETF 8 500 a hotovosti 1 500 → poměrně
        $d = $this->call($this->userId, 'GET', '/api/allocation?deposit=5000')['body']['deposit'];
        self::assertSame([['etf', '4250.00'], ['cash', '750.00']], array_map(static fn (array $l) => [$l['category'], $l['amount']], $d['lines']));

        // Cíle instrumentů v ETF: XTSE 50 %, XTSF (zatím nedržený) 50 %
        $withInstruments = $this->save([
            ['category' => 'crypto', 'target_pct' => '20', 'band_pct' => '5'],
            ['category' => 'etf', 'target_pct' => '70', 'instruments' => [
                ['instrument_id' => $this->instruments['XTSE'], 'target_pct' => '50'],
                ['instrument_id' => $this->instruments['XTSF'], 'target_pct' => '50'],
            ]],
            ['category' => 'cash', 'target_pct' => '10'],
        ]);
        self::assertSame(200, $withInstruments['status'], $withInstruments['raw']);
        $d = $this->call($this->userId, 'GET', '/api/allocation?deposit=5000')['body']['deposit'];
        self::assertSame([['XTSE', '1125.00'], ['XTSF', '3125.00'], [null, '750.00']],
            array_map(static fn (array $l) => [$l['label'], $l['amount']], $d['lines']));
    }

    public function testValidationScopesAndIsolation(): void
    {
        $this->setUpHoldings();

        $bad = $this->save([['category' => 'crypto', 'target_pct' => '50'], ['category' => 'etf', 'target_pct' => '40']]);
        self::assertSame(400, $bad['status']);
        self::assertArrayHasKey('classes', $bad['body']['error']['fields']);
        $wrongClass = $this->save([['category' => 'crypto', 'target_pct' => '100', 'instruments' => [['instrument_id' => $this->instruments['XTSE'], 'target_pct' => '100']]]]);
        self::assertSame(400, $wrongClass['status']);
        self::assertSame(400, $this->save([['category' => 'gold', 'target_pct' => '100']])['status']);
        self::assertSame(400, $this->call($this->userId, 'GET', '/api/allocation?deposit=abc')['status']);

        // Portfolio má vlastní sadu; celý majetek zůstane bez cílů
        self::assertSame(200, $this->save([['category' => 'crypto', 'target_pct' => '100']], $this->portfolio)['status']);
        self::assertTrue($this->call($this->userId, 'GET', "/api/allocation?portfolio_id={$this->portfolio}")['body']['has_targets']);
        self::assertFalse($this->call($this->userId, 'GET', '/api/allocation')['body']['has_targets']);

        // Cizí portfolio → 404; cizí uživatel nevidí hodnoty ani cíle
        self::assertSame(404, $this->call($this->adminId, 'GET', "/api/allocation?portfolio_id={$this->portfolio}")['status']);
        self::assertSame(404, $this->call($this->adminId, 'PUT', '/api/allocation/targets', ['portfolio_id' => $this->portfolio, 'classes' => []])['status']);
        self::assertSame('0.00', $this->call($this->adminId, 'GET', '/api/allocation')['body']['total']);

        // Prázdná sada = smazat
        self::assertFalse($this->save([], $this->portfolio)['body']['has_targets']);
    }
}
