<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange;

/**
 * JSON z API burz bez ztráty přesnosti: číselné literály (mimo řetězce) se před dekódováním zabalí
 * do uvozovek, takže částky dorazí jako desetinné řetězce (pravidlo „nikdy float"), ne jako PHP float.
 */
final class ExchangeJson
{
    private function __construct() {}

    /** @throws ExchangeException neplatný JSON */
    public static function decode(string $json): mixed
    {
        $out = '';
        $len = strlen($json);
        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];
            if ($ch === '"') {
                // řetězec zkopírovat beze změny (včetně escapovaných uvozovek)
                $j = $i + 1;
                while ($j < $len && $json[$j] !== '"') {
                    $j += $json[$j] === '\\' ? 2 : 1;
                }
                $out .= substr($json, $i, $j - $i + 1);
                $i = $j;
                continue;
            }
            if ($ch === '-' || ctype_digit($ch)) {
                preg_match('/-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/A', $json, $m, 0, $i);
                $out .= '"' . $m[0] . '"';
                $i += strlen($m[0]) - 1;
                continue;
            }
            $out .= $ch;
        }
        try {
            return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ExchangeException('Burza vrátila neplatnou odpověď: ' . $e->getMessage(), 0, $e);
        }
    }
}
