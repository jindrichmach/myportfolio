<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Tax;

/**
 * Pravidla osvobození příjmů z prodeje CP a kryptoaktiv (§ 4 ZDP) — čisté funkce bez DB.
 * Ověřeno 2026-10-09.
 */
final class CzTaxRules
{
    public const SECURITIES = 'securities';
    public const CRYPTO = 'crypto';

    /** Limit úhrnu hrubých příjmů za rok (§ 4/1/t a zj). */
    public const INCOME_LIMIT = '100000';
    /** Strop příjmů osvobozených časovým testem (§ 4/3). */
    public const TIME_TEST_CAP = '40000000';
    /** Osvobození kryptoaktiv platí pro prodeje od tohoto dne. */
    public const CRYPTO_EXEMPT_FROM = '2025-02-15';
    /** Sazba pro orientační výpočet daně z dividend a stropu zápočtu (§ 16a). */
    public const DIVIDEND_RATE = '0.15';

    /** Lze na prodej uplatnit osvobození (limit / časový test)? */
    public static function exemptionApplies(string $class, string $disposedAt): bool
    {
        return $class === self::SECURITIES || substr($disposedAt, 0, 10) >= self::CRYPTO_EXEMPT_FROM;
    }

    /** Časový test: doba mezi nabytím a prodejem přesáhla 3 roky (po dnech). */
    public static function heldOverThreeYears(string $acquiredAt, string $disposedAt): bool
    {
        $limit = (new \DateTimeImmutable(substr($acquiredAt, 0, 10)))->modify('+3 years')->format('Y-m-d');
        return substr($disposedAt, 0, 10) > $limit;
    }

    /** Které druhy sdílejí strop 40 mil. Kč v daném roce. @return list<string> */
    public static function capClasses(int $year): array
    {
        return match (true) {
            $year < 2025  => [],
            $year === 2025 => [self::SECURITIES, self::CRYPTO],
            default       => [self::CRYPTO],
        };
    }
}
