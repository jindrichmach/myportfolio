<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\NetWorth;

use MyPortfolio\Service\NetWorth\LoanSchedule;
use PHPUnit\Framework\TestCase;

/** Syntetický úvěr 1 000 000 Kč, 6 % p. a., splátka 10 000 Kč měsíčně od 31. 1. 2025. */
final class LoanScheduleTest extends TestCase
{
    private const START = ['date' => '2025-01-31', 'value' => '1000000', 'rate_pct' => '6', 'payment' => '10000'];

    public function testAnnuityPaymentsOnAnchorDayOfMonth(): void
    {
        $loan = new LoanSchedule([self::START]);
        self::assertNull($loan->balanceOn('2025-01-30'));
        self::assertSame('1000000', (string) $loan->balanceOn('2025-02-27'));
        self::assertSame('995000.00', (string) $loan->balanceOn('2025-02-28'));   // únor: poslední den; úrok 5 000
        self::assertSame('989975.00', (string) $loan->balanceOn('2025-03-31'));   // úrok 4 975
    }

    public function testCorrectionWithNewRateAndPayment(): void
    {
        // Mimořádná splátka + refixace: k 15. 6. 2025 zůstatek 500 000, úrok 4,8 %, splátka 20 000
        $loan = new LoanSchedule([self::START, ['date' => '2025-06-15', 'value' => '500000', 'rate_pct' => '4.8', 'payment' => '20000']]);
        self::assertSame('500000', (string) $loan->balanceOn('2025-07-14'));
        self::assertSame('482000.00', (string) $loan->balanceOn('2025-07-15'));   // úrok 2 000
        // Korekce jen zůstatku si nechá úrok a splátku
        $loan = new LoanSchedule([self::START, ['date' => '2025-06-15', 'value' => '100000', 'rate_pct' => null, 'payment' => null]]);
        self::assertSame('90500.00', (string) $loan->balanceOn('2025-07-15'));    // úrok 500 (6 %)
    }

    public function testPayoffAndNonAmortizingLoan(): void
    {
        $small = new LoanSchedule([['date' => '2026-01-10', 'value' => '25000', 'rate_pct' => '0', 'payment' => '10000']]);
        self::assertSame(['date' => '2026-04-10', 'payments_left' => 2], $small->payoff('2026-02-15'));
        self::assertSame('0', (string) $small->balanceOn('2026-12-31'));
        self::assertNull((new LoanSchedule([['date' => '2026-01-10', 'value' => '1000000', 'rate_pct' => '12', 'payment' => '10000']]))->payoff('2026-01-10'));
    }
}
