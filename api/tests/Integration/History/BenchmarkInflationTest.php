<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\History;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Benchmark a inflace na syntetických datech v CZK. Pozice XTSP: nákup 1 ks za 1 000 (T−40) a 1 ks za 1 100 (T−10),
 * cena dnes 1 200. Benchmark XTSB: 100 → 110 (T−10) → 132 (dnes); stejné vklady = 10 + 10 ks → 2 640.
 * Index cen: měsíc před začátkem 100, tento měsíc 103 → inflace 3 %.
 */
#[Group('integration')]
final class BenchmarkInflationTest extends ApiTestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = new \DateTimeImmutable('today');
        $pdo = $this->db->pdo();
        $pdo->exec('DELETE FROM cpi_index');
        $pdo->prepare("UPDATE users SET display_currency = 'CZK' WHERE id = ?")->execute([$this->userId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->exec('DELETE FROM cpi_index');
        }
        parent::tearDown();
    }

    private function day(int $offset): string
    {
        return $this->today->modify("$offset days")->format('Y-m-d');
    }

    /** Denní ceny od T−40: do T−11 `$a`, od T−10 `$b`, dnes `$c`. */
    private function instrument(string $class, string $symbol, string $a, string $b, string $c): int
    {
        $id = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => $class, 'symbol' => $symbol, 'name' => "Synthetic $symbol",
            'quote_currency' => 'CZK', 'price_source' => 'manual'])['body']['id'];
        $ins = $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, ?, ?, 'CZK', 'manual')");
        for ($d = -40; $d <= 0; $d++) {
            $ins->execute([$id, $this->day($d), $d === 0 ? $c : ($d < -10 ? $a : $b)]);
        }
        return $id;
    }

    public function testBenchmarkSimulationInflationAndRealReturn(): void
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Broker B', 'type' => 'broker'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Main', 'currency' => 'CZK'])['body']['id'];
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Benchmark'])['body']['id'];
        $xtsp = $this->instrument('etf', 'XTSP', '1000', '1100', '1200');
        $xtsb = $this->instrument('crypto', 'XTSB', '100', '110', '132');
        $pos = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'market', 'portfolio_id' => $pf, 'account_id' => $acc,
            'instrument_id' => $xtsp])['body']['id'];
        foreach ([[-40, '1000'], [-10, '1100']] as [$d, $amount]) {
            self::assertSame(201, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pos, 'type' => 'buy',
                'executed_at' => $this->day($d) . ' 10:00', 'quantity' => '1', 'amount' => $amount])['status']);
        }

        // Inflace: měsíc dne před začátkem období = 100, tento měsíc 103 (případně předchozí měsíc 101)
        $baseMonth = substr($this->day(-41), 0, 7);
        $prevMonth = $this->today->modify('first day of last month')->format('Y-m');
        $cpi = $this->db->pdo()->prepare("INSERT INTO cpi_index (month, value, source) VALUES (?, ?, 'test')");
        $cpi->execute([$baseMonth, '100']);
        if ($prevMonth !== $baseMonth) {
            $cpi->execute([$prevMonth, '101']);
        }
        $cpi->execute([$this->today->format('Y-m'), '103']);

        // Bez benchmarku
        $plain = $this->call($this->userId, 'GET', '/api/history?range=ALL')['body'];
        self::assertNull($plain['benchmark']);
        self::assertArrayNotHasKey('benchmark', $plain['points'][0]);
        self::assertSame('14.29', $plain['metrics']['twr_pct']);  // 2400 / 2100
        self::assertSame(['3.00', $baseMonth], [$plain['metrics']['inflation_pct'], $plain['metrics']['inflation_from']]);
        // Inflace 3 % za měsíce indexu → roční míra → délka období výnosu (41 dní), teprve pak Fisher
        [$fy, $fm] = array_map('intval', explode('-', $baseMonth));
        $months = ((int) $this->today->format('Y') - $fy) * 12 + (int) $this->today->format('n') - $fm;
        $periodInflation = (1.03 ** (12 / $months)) ** (41 / 365) - 1;
        self::assertSame(number_format(((2400 / 2100) / (1 + $periodInflation) - 1) * 100, 2, '.', ''), $plain['metrics']['twr_real_pct']);

        self::assertSame(400, $this->call($this->userId, 'PUT', '/api/settings/benchmark', ['instrument_id' => 999999999])['status']);
        $set = $this->call($this->userId, 'PUT', '/api/settings/benchmark', ['instrument_id' => $xtsb]);
        self::assertSame(200, $set['status'], $set['raw']);
        self::assertSame('XTSB', $set['body']['symbol']);

        $h = $this->call($this->userId, 'GET', '/api/history?range=ALL')['body'];
        $b = $h['benchmark'];
        self::assertSame(['XTSB', true, '2640.00', '540.00', '32.00'], [$b['symbol'], $b['available'], $b['end_value'], $b['gain'], $b['twr_pct']]);
        $points = array_column($h['points'], null, 'date');
        self::assertSame('1000.00', $points[$this->day(-40)]['benchmark']);
        self::assertSame('2200.00', $points[$this->day(-10)]['benchmark']);  // 20 ks × 110
        self::assertSame('2400.00', $points[$this->day(0)]['value']);

        // Souhrn: tento měsíc inflace vůči předchozímu měsíci a reálný výnos
        $summary = $this->call($this->userId, 'GET', '/api/summary')['body'];
        $month = array_column($summary['months'], null, 'period')[$this->today->format('Y-m')];
        $prevValue = $prevMonth !== $baseMonth ? 101 : 100;
        self::assertSame(number_format((103 / $prevValue - 1) * 100, 2, '.', ''), $month['inflation_pct']);
        self::assertNotNull($month['real_pct']);

        // Zrušení benchmarku; jiný uživatel benchmark nevidí
        self::assertNull($this->call($this->adminId, 'GET', '/api/history?range=ALL')['body']['benchmark']);
        self::assertSame(200, $this->call($this->userId, 'PUT', '/api/settings/benchmark', ['instrument_id' => null])['status']);
        self::assertNull($this->call($this->userId, 'GET', '/api/history?range=ALL')['body']['benchmark']);
    }

    public function testBenchmarkStopsOnLongPriceGapAndNeverGoesShort(): void
    {
        $id = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => 'crypto', 'symbol' => 'XTSG', 'name' => 'Synthetic XTSG',
            'quote_currency' => 'CZK', 'price_source' => 'manual'])['body']['id'];
        $ins = $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, ?, ?, 'CZK', 'manual')");
        foreach (['2026-01-01' => '100', '2026-01-02' => '100', '2026-01-03' => '200', '2026-02-01' => '300'] as $date => $close) {
            $ins->execute([$id, $date, $close]);
        }
        $service = $this->container->get(\MyPortfolio\Service\History\BenchmarkService::class);
        // Výběr 5 000 při držených 1 000 → prodá jen 10 ks, dál 0 ks (žádný short, růst ceny nezhorší výsledek)
        $sim = $service->simulate($id, 'CZK', null, [
            ['date' => '2026-01-01', 'flow' => '1000'], ['date' => '2026-01-02', 'flow' => '-5000'], ['date' => '2026-01-03', 'flow' => '0'],
        ]);
        self::assertSame(['1000.00', '0.00', '0.00'], array_values($sim['values']));
        self::assertSame(['0.00', '0.00'], [$sim['end_value'], $sim['net_flow']]);
        // Cena chybí 4 týdny (3. 1. → 1. 2.) → benchmark nedostupný, ne stará cena
        $days = [];
        for ($d = new \DateTimeImmutable('2026-01-01'); $d <= new \DateTimeImmutable('2026-02-01'); $d = $d->modify('+1 day')) {
            $days[] = ['date' => $d->format('Y-m-d'), 'flow' => $d->format('Y-m-d') === '2026-01-01' ? '1000' : '0'];
        }
        self::assertNull($service->simulate($id, 'CZK', null, $days));
    }

    public function testMissingInflationIsNullNotZero(): void
    {
        $h = $this->call($this->userId, 'GET', '/api/history?range=1Y')['body'];
        self::assertNull($h['metrics']['inflation_pct']);
        self::assertNull($h['metrics']['twr_real_pct']);
    }
}
