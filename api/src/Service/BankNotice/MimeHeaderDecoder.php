<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

/**
 * Dekódování MIME hlaviček avíz (Subject, From) na UTF-8 (MyInvoice #58).
 *
 * Obě standardní funkce mají slepé místo, kvůli kterému mizela diakritika:
 *  - `iconv_mime_decode(…, 'UTF-8')` sice umí dekódovat i windows-1250
 *    encoded-word, ale NEenkódovaný (literal) 8-bit/UTF-8 text v hlavičce bere
 *    jako us-ascii a high bajty zahodí → „ČSOB" i „Avízo" doslova přijdou o
 *    diakritiku („SOB"/„Avzo"), i když jsou ve validním UTF-8.
 *  - `mb_decode_mimeheader()` literal text zachová, ale windows-1250
 *    encoded-word neumí (mbstring tu sadu na tomto buildu nemá).
 *
 * Proto: je-li v hlavičce RFC 2047 encoded-word (`=?charset?B/Q?…?=`), necháme
 * ho dekódovat `iconv_mime_decode` (respektuje uvedenou sadu vč. windows-1250);
 * jinak je to literal text, který jen překódujeme přes EmailCharsetNormalizer
 * (windows-1250 → UTF-8) BEZ zahazování bajtů.
 */
final class MimeHeaderDecoder
{
    public static function decode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '=?') && function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && trim($decoded) !== '') {
                return EmailCharsetNormalizer::toUtf8(trim($decoded));
            }
        }

        return EmailCharsetNormalizer::toUtf8($value);
    }
}
