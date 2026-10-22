<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Report;

use MyPortfolio\Service\Report\ReportFormat;
use PHPUnit\Framework\TestCase;

final class ReportFormatTest extends TestCase
{
    public function testCzechAndEnglishFormatting(): void
    {
        $cs = new ReportFormat('cs');
        self::assertSame("1\u{00A0}234\u{00A0}567,89 Kč", $cs->money('1234567.891', 'CZK'));
        self::assertSame('+12,50 €', $cs->money('12.5', 'EUR', true));
        self::assertSame("−1\u{00A0}000,00 Kč", $cs->money('-1000', 'CZK', true));
        self::assertSame('0,00 Kč', $cs->money('0', 'CZK', true));
        self::assertSame('—', $cs->money(null, 'CZK'));
        self::assertSame('+4,88 %', $cs->pct('4.88'));
        self::assertSame('', $cs->pct(null));
        self::assertSame('0,00012345', $cs->quantity('0.000123450000'));
        self::assertSame('15. 9. 2026', $cs->date('2026-09-15'));
        self::assertSame(['#15803D', '#B91C1C', '#7A748C'], [$cs->color('1'), $cs->color('-0.01'), $cs->color('0')]);

        $en = new ReportFormat('en');
        self::assertSame('1,234.50 USD', $en->money('1234.5', 'USD'));
        self::assertSame('Sep 15', $en->date('2026-09-15', false));
    }
}
