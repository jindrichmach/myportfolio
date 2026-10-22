<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

/**
 * Převod textu bankovních avíz na UTF-8 (MyInvoice #58).
 *
 * ČSOB „Moje info – Avízo" chodí (zvlášť přeposlané přes jiný server) ve
 * windows-1250, ne v UTF-8. Původní kód nevalidní UTF-8 bajty ZAHAZOVAL
 * (`iconv('UTF-8','UTF-8//IGNORE', …)` v normalizéru těla, resp.
 * `iconv_mime_decode(…, 'UTF-8')` u hlaviček), takže česká diakritika beze
 * stopy mizela („Částka"→„stka", „Avízo"→„Avzo", „ČSOB"→„SOB"). Tím padla jak
 * detekce parseru (`supports()` hledá „avízo"/„částka"), tak shoda účtu.
 *
 * Tady místo zahození bajtů zkusíme text PŘEKÓDOVAT z typických českých legacy
 * sad (windows-1250, ISO-8859-2). Výhradně přes iconv — mbstring na tomto PHP
 * buildu windows-1250 nezná (`mb_list_encodings()` ji nemá). Single-byte sada
 * převedená do UTF-8 je vždy validní, takže windows-1250 (dominantní CZ sada)
 * se uplatní jako první; ISO-8859-2 a finální `UTF-8//IGNORE` jsou pojistka.
 */
final class EmailCharsetNormalizer
{
    /** @var list<string> */
    private const LEGACY_CHARSETS = ['WINDOWS-1250', 'ISO-8859-2'];

    public static function toUtf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        foreach (self::LEGACY_CHARSETS as $charset) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        return is_string($clean) ? $clean : $text;
    }
}
