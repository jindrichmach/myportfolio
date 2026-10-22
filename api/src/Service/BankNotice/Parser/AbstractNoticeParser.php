<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\BankNotice\EmailNoticeTextNormalizer;
use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\SenderDomain;

/**
 * Společné helpery parserů avíz (převzato z MyInvoice `AbstractBankEmailNoticeParser`):
 * normalizace textu, regex s pojmenovanými skupinami, částky (jako desetinný string, ne float),
 * data, účty a měny.
 */
abstract class AbstractNoticeParser implements NoticeParserInterface
{
    private readonly EmailNoticeTextNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new EmailNoticeTextNormalizer();
    }

    abstract protected function label(): string;

    protected function normalizeText(string $text): string
    {
        return $this->normalizer->normalize($text);
    }

    protected function senderMatches(NoticeMessage $message): bool
    {
        return SenderDomain::matches($message->sender, $this->senderDomain());
    }

    /** Sklopí diakritiku na ASCII („Částka" → „Castka") pro tolerantní matchování. */
    protected function foldDiacritics(string $text): string
    {
        static $map = null;
        if ($map === null) {
            $lower = [
                'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'ë' => 'e', 'í' => 'i',
                'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ř' => 'r', 'ŕ' => 'r',
                'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z',
            ];
            $map = $lower;
            foreach ($lower as $from => $to) {
                $map[mb_strtoupper($from, 'UTF-8')] = mb_strtoupper($to, 'UTF-8');
            }
        }
        return strtr($text, $map);
    }

    protected function compact(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @return array<string,string>|null */
    protected function match(string $text, string $pattern): ?array
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }
        $out = [];
        foreach ($m as $key => $value) {
            if (is_string($key)) {
                $out[$key] = trim((string) $value);
            }
        }
        return $out;
    }

    protected function required(string $text, string $pattern, string $what): string
    {
        return $this->optional($text, $pattern) ?? throw new \RuntimeException($this->label() . " parser nenašel $what.");
    }

    protected function optional(string $text, string $pattern): ?string
    {
        $m = $this->match($text, $pattern);
        return $m !== null && isset($m['value']) ? $this->cleanNullable($m['value']) : null;
    }

    /**
     * Částka z avíza → desetinný string („35 000,00" → „35000.00", „-1.234,56" → „-1234.56").
     * Desetinný oddělovač = poslední z čárky/tečky (CZ banky uvádějí haléře vždy).
     */
    protected function parseAmount(string $value): string
    {
        $value = preg_replace('/[^\d,.\-+]/u', '', trim($value)) ?? '';
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $value = str_replace(',', '.', str_replace('.', '', $value));
        } else {
            $value = str_replace(',', '', $value);
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new \RuntimeException($this->label() . " parser: neplatná částka „{$value}\".");
        }
        $decimal = BigDecimal::of($value);
        return (string) ($negative ? $decimal->negated() : $decimal);
    }

    /** Odchozí pohyb → záporná částka. */
    protected function signed(string $amount, bool $outgoing): string
    {
        $abs = (string) BigDecimal::of($amount)->abs();
        return $outgoing ? '-' . $abs : $abs;
    }

    protected function parseDate(string $value): string
    {
        $value = $this->compact($value);
        foreach (['!d.m.Y H:i:s', '!d.m.Y H:i', '!d. m. Y H:i', '!d.m.Y', '!d. m. Y'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }
        throw new \RuntimeException($this->label() . " parser: neplatné datum „{$value}\".");
    }

    /** „Jméno 1234567890/0800" → „1234567890/0800"; bez kódu banky vrací hodnotu, jak je. */
    protected function normalizeAccount(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/[0-9\-]+\/[0-9]{4}/', $value, $m) === 1) {
            return $m[0];
        }
        return $value !== '' ? $value : null;
    }

    protected function cleanNullable(string $value): ?string
    {
        $value = $this->compact($value);
        if ($value === '' || in_array(strtoupper($value), ['N/A', 'NA'], true)) {
            return null;
        }
        return mb_substr($value, 0, 255);
    }

    protected function normalizeCurrency(string $value): string
    {
        $upper = mb_strtoupper(trim($value), 'UTF-8');
        return match ($upper) {
            'KČ', 'KC', '' => 'CZK',
            '€'            => 'EUR',
            '$'            => 'USD',
            default        => substr(preg_replace('/[^A-Z]/', '', $upper) ?: 'CZK', 0, 3),
        };
    }
}
