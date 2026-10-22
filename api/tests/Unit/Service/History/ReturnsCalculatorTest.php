<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\History;

use MyPortfolio\Service\History\ReturnsCalculator;
use PHPUnit\Framework\TestCase;

final class ReturnsCalculatorTest extends TestCase
{
    public function testXirrMatchesSpreadsheetReferenceExample(): void
    {
        // Referenční příklad funkce XIRR (Excel / LibreOffice): 0,373362535
        $xirr = (new ReturnsCalculator())->xirr([
            ['2008-01-01', -10000.0],
            ['2008-03-01', 2750.0],
            ['2008-10-30', 4250.0],
            ['2009-02-15', 3250.0],
            ['2009-04-01', 2750.0],
        ]);
        self::assertEqualsWithDelta(0.373362535, $xirr, 1e-6);
    }

    public function testXirrWithoutSignChangeIsNull(): void
    {
        self::assertNull((new ReturnsCalculator())->xirr([['2024-01-01', -100.0], ['2024-06-01', -50.0]]));
    }

    public function testTwrIgnoresDepositTiming(): void
    {
        // +10 % na 100, pak vklad 1000, pak +10 % → TWR 21 %, i když většina peněz vydělala jen 10 %.
        $m = (new ReturnsCalculator())->metrics(['date' => '2024-01-01', 'value' => '100'], [
            ['date' => '2024-01-02', 'value' => '110', 'flow' => '0'],
            ['date' => '2024-01-03', 'value' => '1110', 'flow' => '1000'],
            ['date' => '2024-01-04', 'value' => '1221', 'flow' => '0'],
        ]);
        self::assertEqualsWithDelta(0.21, $m['twr'], 1e-9);
        self::assertEqualsWithDelta(121.0, $m['gain'], 1e-9);
        self::assertEqualsWithDelta(121 / 1100, $m['simple'], 1e-9);
        self::assertEqualsWithDelta(1000.0, $m['net_flow'], 1e-9);
        self::assertNull($m['twr_annualized']);
    }

    public function testFullSaleUsesEndOfDayConvention(): void
    {
        // Prodej všeho za 120 (hodnota předtím 100) → +20 %, ne dělení nulou.
        $m = (new ReturnsCalculator())->metrics(['date' => '2024-01-01', 'value' => '100'], [
            ['date' => '2024-01-02', 'value' => '0', 'flow' => '-120'],
        ]);
        self::assertEqualsWithDelta(0.2, $m['twr'], 1e-9);
        self::assertEqualsWithDelta(20.0, $m['gain'], 1e-9);
    }

    public function testIncompleteDaysAreSkippedAndFlowCarried(): void
    {
        $m = (new ReturnsCalculator())->metrics(null, [
            ['date' => '2024-01-01', 'value' => null, 'flow' => '100'],
            ['date' => '2024-01-02', 'value' => '105', 'flow' => '0'],
        ]);
        self::assertEqualsWithDelta(0.05, $m['twr'], 1e-9);
        self::assertEqualsWithDelta(100.0, $m['net_flow'], 1e-9);
    }

    public function testEmptySeries(): void
    {
        $m = (new ReturnsCalculator())->metrics(null, []);
        self::assertNull($m['twr']);
        self::assertNull($m['xirr']);
    }
}
