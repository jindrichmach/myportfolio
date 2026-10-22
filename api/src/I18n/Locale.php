<?php

declare(strict_types=1);

namespace MyPortfolio\I18n;

/**
 * Per-request locale state. Set by AuthMiddleware podle user.locale,
 * fallback 'cs'. Json::error a ErrorCatalog ho čtou pro překlad hlášek.
 */
final class Locale
{
    private static string $current = 'cs';

    public static function set(string $locale): void
    {
        self::$current = in_array($locale, ['cs', 'en'], true) ? $locale : 'cs';
    }

    public static function current(): string
    {
        return self::$current;
    }
}
