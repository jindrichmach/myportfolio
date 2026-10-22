<?php

declare(strict_types=1);

namespace MyPortfolio\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Parsování a normalizace desetinných čísel (peníze, ceny, množství).
 *
 * Pravidlo projektu: nikdy float. Vstup z API je string (nebo int); float z JSON
 * se odmítá, protože už při json_decode ztratil přesnost. Přijímá `,` i `.`
 * jako desetinný oddělovač a mezery (včetně nezlomitelných) jako oddělovač tisíců.
 */
final class Decimal
{
    /** Počet desetinných míst podle sloupců v DB. */
    public const SCALE_QUANTITY = 18;
    public const SCALE_PRICE    = 10;
    public const SCALE_AMOUNT   = 4;
    /** Poplatek může být v kryptu (0.00005 BTC) — stejná přesnost jako množství. */
    public const SCALE_FEE      = 18;
    public const SCALE_RATE     = 4;

    private function __construct() {}

    /**
     * @throws \InvalidArgumentException neplatný formát, float nebo víc desetinných míst než $maxScale
     */
    public static function parse(mixed $value, int $maxScale): ?BigDecimal
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value)) {
            throw new \InvalidArgumentException('Číslo pošli jako text (např. "0.015"), ne jako float.');
        }
        if (is_int($value)) {
            return BigDecimal::of($value);
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Neplatné číslo.');
        }

        $normalized = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);
        if ($normalized === '') {
            return null;
        }
        if (preg_match('/^-?\d+(?:\.(\d+))?$/', $normalized, $m) !== 1) {
            throw new \InvalidArgumentException('Neplatné číslo.');
        }

        $decimal = BigDecimal::of($normalized)->strippedOfTrailingZeros();
        if ($decimal->getScale() > $maxScale) {
            throw new \InvalidArgumentException(sprintf('Maximální počet desetinných míst: %d.', $maxScale));
        }
        return $decimal;
    }

    /**
     * Cena z externího JSON API (tam už je float) → desetinný string. Jediné místo, kde
     * float vstupuje — přesnost zdroje je beztak float; zaokrouhlí na $scale míst.
     */
    public static function fromExternalFloat(float|int|string $value, int $scale = self::SCALE_PRICE): BigDecimal
    {
        if (is_string($value)) {
            return BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp)->strippedOfTrailingZeros();
        }
        if (!is_finite((float) $value)) {
            throw new \InvalidArgumentException('Neplatné číslo ze zdroje.');
        }
        return BigDecimal::of(sprintf('%.' . $scale . 'F', $value))->strippedOfTrailingZeros();
    }

    /** Hodnota pro uložení do DECIMAL sloupce daného scale. */
    public static function toDb(?BigDecimal $value, int $scale): ?string
    {
        return $value?->toScale($scale)->toString();
    }

    /** Hodnota z DB (DECIMAL string) pro JSON — bez koncových nul. */
    public static function out(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return BigDecimal::of($value)->strippedOfTrailingZeros()->toString();
    }
}
