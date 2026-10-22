<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Tax;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Daňový report na syntetických datech: FIFO přes více lotů, převod se zachováním data nabytí,
 * limit 100 000 Kč, časový test, kompenzace ztráty, krypto před / po 15. 2. 2025, cizí měna
 * (syntetická XTS: denní kurz 10, k poslednímu dni měsíce 12 → jednotný kurz 12), dividenda se srážkou.
 */
#[Group('integration')]
final class TaxReportTest extends ApiTestCase
{
    private int $inst = 0;
    private int $pf = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $pdo->exec("DELETE FROM fx_rates WHERE currency = 'XTS'");
        $ins = $pdo->prepare("INSERT INTO fx_rates (date, currency, amount, rate) VALUES (?, 'XTS', 1, ?)");
        for ($d = new \DateTimeImmutable('2024-01-01'); $d < new \DateTimeImmutable('2025-01-01'); $d = $d->modify('+1 day')) {
            $ins->execute([$d->format('Y-m-d'), $d->format('Y-m-d') === $d->modify('last day of this month')->format('Y-m-d') ? '12' : '10']);
        }
        $this->inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Broker T', 'type' => 'broker'])['body']['id'];
        $this->pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Daně'])['body']['id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->exec("DELETE FROM fx_rates WHERE currency = 'XTS'");
        }
        parent::tearDown();
    }

    private function account(string $name, string $currency = 'CZK'): int
    {
        return $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $this->inst, 'name' => $name, 'currency' => $currency])['body']['id'];
    }

    private function position(int $account, string $class, string $symbol, string $quote = 'CZK', ?string $isin = null): int
    {
        $ins = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => $class, 'symbol' => $symbol, 'name' => "Synthetic $symbol",
            'quote_currency' => $quote, 'price_source' => 'manual', 'isin' => $isin])['body']['id'];
        return $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'market', 'portfolio_id' => $this->pf,
            'account_id' => $account, 'instrument_id' => $ins])['body']['id'];
    }

    /** @param array<string,mixed> $data */
    private function tx(int $position, array $data): void
    {
        $r = $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $position] + $data);
        self::assertSame(201, $r['status'], json_encode($r['body']) ?: '');
    }

    public function testSecuritiesAndCrypto2025(): void
    {
        $main = $this->account('Main');
        $old = $this->account('Old broker');
        $synaOld = $this->position($old, 'stock', 'SYNA');
        $syna = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'market', 'portfolio_id' => $this->pf, 'account_id' => $main,
            'instrument_id' => $this->db->pdo()->query("SELECT instrument_id FROM positions WHERE id = $synaOld")->fetchColumn()])['body']['id'];
        $synb = $this->position($main, 'stock', 'SYNB');
        $sync = $this->position($main, 'crypto', 'SYNC');

        // Lot 1 nabytý 2021 u starého brokera, převedený 2023 (datum nabytí zůstává)
        $this->tx($synaOld, ['type' => 'buy', 'executed_at' => '2021-06-01 10:00', 'quantity' => '10', 'amount' => '50000']);
        self::assertSame(201, $this->call($this->userId, 'POST', '/api/transfers', ['from_position_id' => $synaOld, 'to_position_id' => $syna,
            'quantity' => '10', 'executed_at' => '2023-01-01 10:00'])['status']);
        $this->tx($syna, ['type' => 'buy', 'executed_at' => '2024-01-10 10:00', 'quantity' => '10', 'amount' => '60000', 'fee' => '100', 'fee_currency' => 'CZK']);
        $this->tx($syna, ['type' => 'sell', 'executed_at' => '2025-03-01 10:00', 'quantity' => '15', 'amount' => '120000', 'fee' => '150', 'fee_currency' => 'CZK']);
        // Ztráta kompenzuje zisk
        $this->tx($synb, ['type' => 'buy', 'executed_at' => '2025-01-05 10:00', 'quantity' => '5', 'amount' => '20000']);
        $this->tx($synb, ['type' => 'sell', 'executed_at' => '2025-05-01 10:00', 'quantity' => '5', 'amount' => '15000']);
        // Krypto: prodej před 15. 2. zdanitelný, po něm v limitu 100 000 Kč
        $this->tx($sync, ['type' => 'buy', 'executed_at' => '2024-01-01 10:00', 'quantity' => '1', 'amount' => '30000']);
        $this->tx($sync, ['type' => 'sell', 'executed_at' => '2025-02-01 10:00', 'quantity' => '0.5', 'amount' => '20000']);
        $this->tx($sync, ['type' => 'sell', 'executed_at' => '2025-03-01 10:00', 'quantity' => '0.5', 'amount' => '25000']);

        $r = $this->call($this->userId, 'GET', '/api/tax-report?year=2025&rate=daily');
        self::assertSame(200, $r['status'], $r['raw']);
        $s = $r['body']['securities'];
        self::assertSame(['135000.00', '100200.00', '80000.00', '55000.00', '50100.00', '4900.00', '4900.00', false],
            [$s['income'], $s['expense'], $s['exempt_income'], $s['taxable_income'], $s['taxable_expense'], $s['result'], $s['tax_base'], $s['under_limit']]);
        $byLot = array_map(static fn (array $x) => [$x['symbol'], $x['quantity'], substr((string) $x['acquired_at'], 0, 10), $x['income'], $x['expense'], $x['exemption']], $s['rows']);
        self::assertSame([
            ['SYNA', '10', '2021-06-01', '80000.00', '50100.00', 'time_test'],
            ['SYNA', '5', '2024-01-10', '40000.00', '30100.00', 'none'],
            ['SYNB', '5', '2025-01-05', '15000.00', '20000.00', 'none'],
        ], $byLot);

        $c = $r['body']['crypto'];
        self::assertSame(['25000.00', true, '25000.00', '20000.00', '15000.00', '5000.00'],
            [$c['eligible_income'], $c['under_limit'], $c['exempt_income'], $c['taxable_income'], $c['taxable_expense'], $c['tax_base']]);
        self::assertSame(['before_crypto_exemption', 'limit'], array_column($c['rows'], 'exemption'));

        // Cizí uživatel nic nevidí
        $foreign = $this->call($this->adminId, 'GET', '/api/tax-report?year=2025&rate=daily')['body'];
        self::assertSame([[], []], [$foreign['securities']['rows'], $foreign['crypto']['rows']]);
        self::assertSame([2025], $this->call($this->userId, 'GET', '/api/tax-report/years')['body']);

        $csv = $this->call($this->userId, 'GET', '/api/tax-report/export.csv?year=2025&rate=daily');
        self::assertSame(200, $csv['status']);
        self::assertStringContainsString('"Cenné papíry";2025-03-01;SYNA', $csv['raw']);
        self::assertStringContainsString('80000,00;50100,00;29900,00;"osvobozeno — časový test 3 roky"', $csv['raw']);

        // Podklad k přepisu: příloha 2 po druzích (jen zdanitelná část), ř. 207–209 → ř. 40
        $g = $this->call($this->userId, 'GET', '/api/tax-report/filing?year=2025&rate=daily');
        self::assertSame(200, $g['status'], $g['raw']);
        $a2 = $g['body']['attachment2'];
        self::assertSame([['D', '55000', '50100', '4900'], ['C', '20000', '15000', '5000']],
            array_map(static fn (array $r) => [$r['code'], $r['income'], $r['expense'], $r['difference']], $a2['rows']));
        self::assertSame(['75000', '65100', '9900', '9900'], [$a2['r207'], $a2['r208'], $a2['r209'], $a2['main_r40']]);
        self::assertTrue($g['body']['verified']);
        self::assertContains('crypto_code', $g['body']['warnings']);
        self::assertSame(['80000', '25000'], [$g['body']['exempt']['securities'], $g['body']['exempt']['crypto']]);
    }

    public function testForeignCurrencyDailyVsUnifiedAndDividends(): void
    {
        $acc = $this->account('Foreign', 'XTS');
        $synf = $this->position($acc, 'stock', 'SYNF', 'XTS', 'US0000000001');
        $this->tx($synf, ['type' => 'buy', 'executed_at' => '2024-01-15 10:00', 'quantity' => '10', 'amount' => '100', 'fee' => '1', 'fee_currency' => 'XTS']);
        $this->tx($synf, ['type' => 'sell', 'executed_at' => '2024-06-14 10:00', 'quantity' => '10', 'amount' => '150']);
        $this->tx($synf, ['type' => 'dividend', 'executed_at' => '2024-03-15 10:00', 'amount' => '10', 'fee' => '3', 'fee_currency' => 'XTS']);

        $daily = $this->call($this->userId, 'GET', '/api/tax-report?year=2024&rate=daily')['body'];
        self::assertSame(['1500.00', '1010.00', true, '1500.00'], [$daily['securities']['income'], $daily['securities']['expense'],
            $daily['securities']['under_limit'], $daily['securities']['exempt_income']]);
        $div = $daily['dividends']['countries'][0];
        // hrubě 100 Kč, sraženo 30 Kč, započitatelné max. 15 % = 15 Kč, česká daň 15 Kč → doplatek 0
        self::assertSame(['US', '100.00', '30.00', '15.00', '15.00', '0.00'],
            [$div['country'], $div['gross'], $div['withheld'], $div['creditable'], $div['cz_tax'], $div['to_pay']]);

        // Podklad: prodej v limitu 100 000 Kč se neuvádí; dividenda v samostatném základu (příloha 4) nebo na ř. 38 + příloha 3
        $sep = $this->call($this->userId, 'GET', '/api/tax-report/filing?year=2024&rate=daily&dividends=16a')['body'];
        self::assertSame([[], '0'], [$sep['attachment2']['rows'], $sep['attachment2']['r207']]);
        $d = $sep['dividends'];
        self::assertSame(['100', '100', '100', '15', '100', '30', '15', '0', '0'],
            [$d['r401a'], $d['r406'], $d['r409'], $d['r410'], $d['r411'], $d['r412'], $d['r413'], $d['r414'], $d['main_r74a']]);
        $gen = $this->call($this->userId, 'GET', '/api/tax-report/filing?year=2024&rate=daily&dividends=38')['body']['dividends'];
        self::assertSame(['100', [['country' => 'US', 'r321' => '100', 'r323' => '30']]], [$gen['main_r38'], $gen['countries']]);
        $csv = $this->call($this->userId, 'GET', '/api/tax-report/filing.csv?year=2024&rate=daily&dividends=16a');
        self::assertStringContainsString('"Příloha 4";414;', $csv['raw']);
        self::assertSame(400, $this->call($this->userId, 'GET', '/api/tax-report/filing?year=2024&dividends=99')['status']);

        $unified = $this->call($this->userId, 'GET', '/api/tax-report?year=2024&rate=unified')['body'];
        self::assertSame(['1800.00', '1212.00'], [$unified['securities']['income'], $unified['securities']['expense']]);
        self::assertSame([['currency' => 'XTS', 'year' => 2024, 'rate' => '12.0000']], $unified['unified_rates']);

        // Rok bez kurzů → chyba, nikdy kurz 1
        $this->db->pdo()->prepare("INSERT INTO fx_rates (date, currency, amount, rate) VALUES ('2025-01-02', 'XTS', 1, 10)")->execute();
        $this->tx($synf, ['type' => 'dividend', 'executed_at' => '2025-01-03 10:00', 'amount' => '1']);
        $missing = $this->call($this->userId, 'GET', '/api/tax-report?year=2025&rate=unified');
        self::assertSame(422, $missing['status']);
        self::assertSame('fx_missing', $missing['body']['error']['code']);
    }

    public function testCryptoFeesInUnitsAndInThirdAsset(): void
    {
        $syne = $this->position($this->account('Crypto'), 'crypto', 'SYNE');
        $this->tx($syne, ['type' => 'buy', 'executed_at' => '2025-03-01 10:00', 'quantity' => '2', 'amount' => '300000']);
        // Poplatek 0,1 SYNE: kusy spotřebované na poplatek (nabývací cena 15 000) jsou výdaj prodeje
        $this->tx($syne, ['type' => 'sell', 'executed_at' => '2025-06-01 10:00', 'quantity' => '1', 'amount' => '200000', 'fee' => '0.1', 'fee_currency' => 'SYNE']);
        // Poplatek v BNB: kurz ČNB ho neocení → vynechá se s varováním, report nespadne
        $this->tx($syne, ['type' => 'sell', 'executed_at' => '2025-07-01 10:00', 'quantity' => '0.5', 'amount' => '60000', 'fee' => '1', 'fee_currency' => 'BNB']);

        $r = $this->call($this->userId, 'GET', '/api/tax-report?year=2025&rate=daily');
        self::assertSame(200, $r['status'], $r['raw']);
        $c = $r['body']['crypto'];
        self::assertSame(['260000.00', '240000.00'], [$c['taxable_income'], $c['taxable_expense']]);   // 150 000 + 15 000 + 75 000
        self::assertSame(1, count(array_filter($c['rows'], static fn (array $x) => $x['fee_lot'])));
        self::assertSame(['BNB'], array_values(array_filter(array_column($c['rows'], 'fee_skipped'))));

        // CSV: název instrumentu jako vzorec se v Excelu nespustí
        $this->db->pdo()->prepare("UPDATE instruments SET name = '=HYPERLINK(\"x\")' WHERE symbol = 'SYNE'")->execute();
        $csv = $this->call($this->userId, 'GET', '/api/tax-report/export.csv?year=2025&rate=daily')['raw'];
        self::assertStringContainsString(";\"'=HYPERLINK(\"\"x\"\")\";", $csv);
        self::assertStringContainsString('poplatek v BNB není ve výdajích', $csv);
    }

    public function testFilingCreditNeverExceedsTaxOnRoundedBase(): void
    {
        // 109,90 XTS × 10 = 1 099 Kč hrubě, sraženo 16,50 XTS = 165 Kč: ř. 409 = 1 000 → ř. 410 = 150, 15 % z ř. 411 = 164
        $synd = $this->position($this->account('Div', 'XTS'), 'stock', 'SYND', 'XTS', 'US0000000002');
        $this->tx($synd, ['type' => 'buy', 'executed_at' => '2024-01-15 10:00', 'quantity' => '1', 'amount' => '100']);
        $this->tx($synd, ['type' => 'dividend', 'executed_at' => '2024-03-15 10:00', 'amount' => '109.90', 'fee' => '16.50', 'fee_currency' => 'XTS']);
        $d = $this->call($this->userId, 'GET', '/api/tax-report/filing?year=2024&rate=daily&dividends=16a')['body']['dividends'];
        self::assertSame(['1099', '1000', '150', '1099', '165', '150', '0'], [$d['r401a'], $d['r409'], $d['r410'], $d['r411'], $d['r412'], $d['r413'], $d['r414']]);
    }

    public function testIncomeLimitBoundary(): void
    {
        $acc = $this->account('Limit');
        $pos = $this->position($acc, 'stock', 'SYNL');
        $this->tx($pos, ['type' => 'buy', 'executed_at' => '2022-01-03 10:00', 'quantity' => '2', 'amount' => '100000']);
        $this->tx($pos, ['type' => 'sell', 'executed_at' => '2023-06-01 10:00', 'quantity' => '1', 'amount' => '100000']);
        $this->tx($pos, ['type' => 'sell', 'executed_at' => '2024-06-01 10:00', 'quantity' => '1', 'amount' => '100000.01']);

        $at = $this->call($this->userId, 'GET', '/api/tax-report?year=2023&rate=daily')['body']['securities'];
        self::assertSame([true, 'limit', '0.00'], [$at['under_limit'], $at['rows'][0]['exemption'], $at['tax_base']]);
        $over = $this->call($this->userId, 'GET', '/api/tax-report?year=2024&rate=daily')['body']['securities'];
        self::assertSame([false, 'none', '50000.01'], [$over['under_limit'], $over['rows'][0]['exemption'], $over['tax_base']]);
    }
}
