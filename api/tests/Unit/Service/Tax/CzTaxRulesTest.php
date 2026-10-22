<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Tax;

use MyPortfolio\Service\Tax\CzTaxRules;
use PHPUnit\Framework\TestCase;

final class CzTaxRulesTest extends TestCase
{
    public function testThreeYearBoundaryIsExclusive(): void
    {
        self::assertFalse(CzTaxRules::heldOverThreeYears('2022-03-01 10:00:00', '2025-03-01 18:00:00'));
        self::assertTrue(CzTaxRules::heldOverThreeYears('2022-03-01 10:00:00', '2025-03-02 08:00:00'));
        self::assertFalse(CzTaxRules::heldOverThreeYears('2024-06-30 00:00:00', '2024-12-31 00:00:00'));
    }

    public function testCryptoExemptionStartsOn15February2025(): void
    {
        self::assertFalse(CzTaxRules::exemptionApplies(CzTaxRules::CRYPTO, '2025-02-14 23:59:59'));
        self::assertTrue(CzTaxRules::exemptionApplies(CzTaxRules::CRYPTO, '2025-02-15 00:00:00'));
        self::assertTrue(CzTaxRules::exemptionApplies(CzTaxRules::SECURITIES, '2020-01-01 00:00:00'));
    }

    public function testCapClassesByYear(): void
    {
        self::assertSame([], CzTaxRules::capClasses(2024));
        self::assertSame([CzTaxRules::SECURITIES, CzTaxRules::CRYPTO], CzTaxRules::capClasses(2025));
        self::assertSame([CzTaxRules::CRYPTO], CzTaxRules::capClasses(2026));
    }
}
