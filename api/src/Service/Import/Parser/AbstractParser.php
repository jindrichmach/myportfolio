<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Service\Import\DraftHelpers;
use MyPortfolio\Service\Import\Reader\Table;

/** Společné helpery parserů: čísla v různých formátech, data (i Excel serial), hledání hlavičky. */
abstract class AbstractParser implements ImportParserInterface
{
    use DraftHelpers;

    /** První tabulka s danou hlavičkou. @param list<Table> $tables @param list<string> $required */
    protected function rows(array $tables, array $required): ?array
    {
        foreach ($tables as $t) {
            $rows = $t->withHeader($required);
            if ($rows !== null) {
                return $rows;
            }
        }
        return null;
    }

    /** @param list<Table> $tables @param list<string> $required */
    protected function hasHeader(array $tables, array $required): bool
    {
        return $this->rows($tables, $required) !== null;
    }

    /**
     * Hodnota sloupce podle prvního existujícího z aliasů (porovnání jako u hlavičky).
     *
     * @param array<string,string> $row
     */
    protected function col(array $row, string ...$names): string
    {
        foreach ($names as $name) {
            $want = Table::norm($name);
            foreach ($row as $k => $v) {
                if (Table::norm((string) $k) === $want) {
                    return trim((string) $v);
                }
            }
        }
        return '';
    }

    /**
     * Číslo z exportu → desetinný string (bez znaménka, pokud $abs). Zvládá „1,234.56", „1 234,56",
     * „1.234,56", měnu/procenta kolem, závorky jako záporné, vědecký zápis. Prázdné → null.
     * `$decimal` = desetinný oddělovač, pokud ho parser zná (jinak odhad; „1,234" bez něj = 1.234).
     */
    protected function num(string $value, bool $abs = true, ?string $decimal = null): ?string
    {
        $v = trim(str_replace(["\u{00A0}", "\u{202F}", "'"], ' ', $value));
        if ($v === '' || $v === '-' || $v === '--') {
            return null;
        }
        $negative = str_starts_with($v, '-') || str_starts_with($v, '−') || (str_starts_with($v, '(') && str_ends_with($v, ')'));
        if (preg_match('/^[+\-]?\d+(\.\d+)?[eE][+\-]?\d+$/', $v) === 1) {
            $d = BigDecimal::of(ltrim($v, '+-'));
        } else {
            $v = preg_replace('/[^\d,.]/u', '', $v) ?? '';
            if ($v === '') {
                return null;
            }
            $lastComma = strrpos($v, ',');
            $lastDot = strrpos($v, '.');
            if ($decimal === '.') {
                $v = str_replace(',', '', $v);
            } elseif ($decimal === ',') {
                $v = str_replace(',', '.', str_replace('.', '', $v));
            } elseif ($lastComma !== false && $lastDot !== false) {
                $v = $lastComma > $lastDot ? str_replace(',', '.', str_replace('.', '', $v)) : str_replace(',', '', $v);
            } elseif ($lastComma !== false) {
                // Jediná čárka: desetinná, pokud za ní není přesně skupina 3 číslic s dalšími čárkami.
                $v = substr_count($v, ',') > 1 ? str_replace(',', '', $v) : str_replace(',', '.', $v);
            } elseif (substr_count($v, '.') > 1) {
                $v = str_replace('.', '', $v);
            }
            $d = BigDecimal::of($v);
        }
        $d = $d->strippedOfTrailingZeros();
        return (string) ($negative && !$abs ? $d->negated() : $d);
    }

    /** Je číslo záporné (−, -, závorky)? */
    protected function negative(string $value): bool
    {
        $v = trim($value);
        return str_starts_with($v, '-') || str_starts_with($v, '−') || (str_starts_with($v, '(') && str_ends_with($v, ')'));
    }

    /**
     * Datum a čas → 'Y-m-d H:i:s' v časové zóně aplikace. `$sourceTz` = zóna, ve které export čas uvádí
     * (např. UTC); explicitní posun v textu (Z, +01:00) má přednost. Excel serial (XLSX) se převede.
     */
    protected function dateTime(string $value, string $sourceTz = 'UTC', ?string $format = null): string
    {
        $v = trim($value);
        $local = new \DateTimeZone(date_default_timezone_get());
        if ($v === '') {
            throw new \InvalidArgumentException('Chybí datum.');
        }
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $v) === 1 && (float) $v > 20000) {
            // Excel serial (dny od 1899-12-30), čas v zóně exportu
            $seconds = (int) round(((float) $v - 25569) * 86400);
            return (new \DateTimeImmutable(gmdate('Y-m-d H:i:s', $seconds), new \DateTimeZone($sourceTz)))
                ->setTimezone($local)->format('Y-m-d H:i:s');
        }
        $tz = new \DateTimeZone($sourceTz);
        if ($format !== null) {
            $d = \DateTimeImmutable::createFromFormat('!' . $format, $v, $tz);
            if ($d === false) {
                throw new \InvalidArgumentException("Neplatné datum „{$v}\".");
            }
            return $d->setTimezone($local)->format('Y-m-d H:i:s');
        }
        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'd. m. Y H:i:s', 'd. m. Y H:i', 'd. m. Y', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . $f, $v, $tz);
            if ($d !== false && \DateTimeImmutable::getLastErrors() === false) {
                return $d->setTimezone($local)->format('Y-m-d H:i:s');
            }
        }
        try {
            // ISO 8601 a podobné („2024-01-31 12:00:00 UTC", „2024-01-31T12:00:00Z", „2024-01-31 12:00:00.123")
            $d = new \DateTimeImmutable(preg_replace('/\s+UTC$/', 'Z', $v) ?? $v, $tz);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Neplatné datum „{$v}\".");
        }
        return $d->setTimezone($local)->format('Y-m-d H:i:s');
    }

    /** Stabilní ID řádku bez vlastního ID v exportu. @param array<string,string> $row */
    protected function rowHash(array $row): string
    {
        unset($row['__line']);
        return substr(hash('sha256', json_encode(array_values($row), JSON_UNESCAPED_UNICODE) ?: ''), 0, 32);
    }

    protected function line(array $row): int
    {
        return (int) ($row['__line'] ?? 0);
    }
}
