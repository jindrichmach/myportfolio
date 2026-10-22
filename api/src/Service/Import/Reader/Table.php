<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Reader;

/**
 * Tabulka z importovaného souboru (CSV, nebo jeden list XLSX) jako surové řádky buněk.
 * Parsery si v ní najdou hlavičku (`withHeader`) — exporty mívají nad hlavičkou preambuli.
 */
final class Table
{
    /** @param list<list<string>> $rows */
    public function __construct(public readonly string $name, public readonly array $rows) {}

    /**
     * Najde první řádek, který obsahuje všechny `$required` sloupce (porovnání bez ohledu na velikost
     * písmen, diakritiku a okolní mezery), a vrátí datové řádky jako pole název sloupce → hodnota.
     * Klíče jsou původní názvy z hlavičky; `null` = hlavička nenalezena.
     *
     * @param list<string> $required
     * @return list<array<string,string>>|null
     */
    public function withHeader(array $required): ?array
    {
        $want = array_map([self::class, 'norm'], $required);
        foreach ($this->rows as $i => $row) {
            $have = array_map([self::class, 'norm'], $row);
            if (array_diff($want, $have) !== []) {
                continue;
            }
            $header = array_map('trim', $row);
            $out = [];
            foreach (array_slice($this->rows, $i + 1, null, true) as $lineIdx => $cells) {
                if (implode('', array_map('trim', $cells)) === '') {
                    continue;
                }
                $assoc = ['__line' => (string) ($lineIdx + 1)];
                foreach ($header as $c => $name) {
                    if ($name !== '') {
                        $assoc[$name] = trim((string) ($cells[$c] ?? ''));
                    }
                }
                $out[] = $assoc;
            }
            return $out;
        }
        return null;
    }

    public function hasHeader(array $required): bool
    {
        return $this->withHeader($required) !== null;
    }

    /** Normalizace názvu sloupce pro porovnání: malá písmena, bez diakritiky a vícenásobných mezer. */
    public static function norm(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o',
            'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u']);
        return (string) preg_replace('/\s+/', ' ', $value);
    }
}
