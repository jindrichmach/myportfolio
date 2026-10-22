<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Report;

use Brick\Math\BigDecimal;

/** Formátování čísel a dat do e-mailu (cs: „1 234,56 Kč", en: „1,234.56 CZK"). */
final class ReportFormat
{
    private const SYMBOLS = ['CZK' => 'Kč', 'EUR' => '€', 'USD' => '$'];

    public function __construct(private readonly string $locale) {}

    public function money(?string $value, ?string $currency, bool $signed = false): string
    {
        if ($value === null) {
            return '—';
        }
        $number = $this->number($value, 2, $signed);
        if ($currency === null || $currency === '') {
            return $number;
        }
        return $this->locale === 'cs' ? $number . ' ' . (self::SYMBOLS[$currency] ?? $currency) : $number . ' ' . $currency;
    }

    /** Jednotková cena: aspoň 2 desetinná místa, víc jen když je cena má (drobné kryptoměny), nejvýš 8. */
    public function price(string $value, string $currency): string
    {
        $scale = BigDecimal::of($value)->strippedOfTrailingZeros()->getScale();
        $number = $this->number($value, min(8, max(2, $scale)));
        return $this->locale === 'cs' ? $number . ' ' . (self::SYMBOLS[$currency] ?? $currency) : $number . ' ' . $currency;
    }

    public function pct(?string $value): string
    {
        return $value === null ? '' : $this->number($value, 2, true) . ' %';
    }

    public function quantity(string $value): string
    {
        $scale = BigDecimal::of($value)->strippedOfTrailingZeros()->getScale();
        return $this->number($value, max(0, $scale));
    }

    /** CSS barva podle znaménka (zisk zelená, ztráta červená). */
    public function color(?string $value): string
    {
        if ($value === null) {
            return '#7A748C';
        }
        $sign = BigDecimal::of($value)->getSign();
        return $sign > 0 ? '#15803D' : ($sign < 0 ? '#B91C1C' : '#7A748C');
    }

    public function date(string $ymd, bool $withYear = true): string
    {
        $d = new \DateTimeImmutable($ymd);
        if ($this->locale === 'cs') {
            return $d->format($withYear ? 'j. n. Y' : 'j. n.');
        }
        return $d->format($withYear ? 'M j, Y' : 'M j');
    }

    private function number(string $value, int $decimals, bool $signed = false): string
    {
        $d = BigDecimal::of($value)->toScale($decimals, \Brick\Math\RoundingMode::HalfUp);
        $negative = $d->isNegative();
        [$int, $frac] = array_pad(explode('.', (string) $d->abs()), 2, '');
        $thousands = $this->locale === 'cs' ? "\u{00A0}" : ',';
        $int = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', $thousands, $int);
        $out = $frac !== '' ? $int . ($this->locale === 'cs' ? ',' : '.') . $frac : $int;
        $sign = $negative ? '−' : ($signed && !$d->isZero() ? '+' : '');
        return $sign . $out;
    }
}
