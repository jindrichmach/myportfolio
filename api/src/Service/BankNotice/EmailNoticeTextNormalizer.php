<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

final class EmailNoticeTextNormalizer
{
    public function normalize(string $input): string
    {
        $text = $this->decodeQuotedPrintable($this->extractBody($input));
        // MyInvoice #58: nevalidní UTF-8 (typicky windows-1250 ČSOB avízo) překóduj,
        // ne zahoď — jinak by zmizela diakritika a parser by avízo nepoznal.
        $text = EmailCharsetNormalizer::toUtf8($text);
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $text) ?? $text;
        if (str_contains($text, '<') && str_contains($text, '>')) {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/u', "\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * QP-decode jen tam, kde dává smysl. webklex/php-imap už transfer-encoding
     * těla dekóduje, takže příchozí tělo bývá platné UTF-8. Bezpodmínečný
     * quoted_printable_decode pak v patičkových marketingových URL ČS avíza
     * („…?web-api-key=142c39…&id=0729…&source-id=aauesx…") rozkóduje neúmyslné
     * =XX sekvence (např. „=aa" → bajt 0xAA), čímž rozbije validitu UTF-8.
     * Následný EmailCharsetNormalizer pak celé (jinak validní) tělo překlopí
     * jako windows-1250 → diakritika se zdvojí na mojibake („Směr"→„SmÄ›r") a
     * supports() přestane avízo poznávat: „žádný aktivní parser provider" (MyInvoice #158).
     * Pravidlo: pokud je vstup už platné UTF-8 a QP-decode by ho znevalidnil,
     * decode přeskoč. Pravé QP/windows-1250 avízo (MyInvoice #58) přichází jako nevalidní
     * UTF-8, takže touto podmínkou neprojde a dekóduje/překóduje se jako dřív.
     */
    private function decodeQuotedPrintable(string $body): string
    {
        $decoded = quoted_printable_decode($body);
        if ($decoded === $body) {
            return $body;
        }
        if (mb_check_encoding($body, 'UTF-8') && !mb_check_encoding($decoded, 'UTF-8')) {
            return $body;
        }
        return $decoded;
    }

    private function extractBody(string $input): string
    {
        foreach ([
            '/Content-Transfer-Encoding:\s*quoted-printable\b.*?Content-Type:\s*text\/html\b.*?\R\R(?<body>.*?)(?=\R-{2,}=_|\z)/is',
            '/Content-Type:\s*text\/html\b.*?Content-Transfer-Encoding:\s*quoted-printable\b.*?\R\R(?<body>.*?)(?=\R-{2,}=_|\z)/is',
            '/Content-Type:\s*text\/plain\b.*?\R\R(?<body>.*?)(?=\R-{2,}=_|\z)/is',
        ] as $pattern) {
            if (preg_match($pattern, $input, $m) === 1) {
                return (string) $m['body'];
            }
        }

        if (preg_match('/(?:<!DOCTYPE|<html\b)/i', $input, $m, PREG_OFFSET_CAPTURE) === 1) {
            return substr($input, (int) $m[0][1]);
        }

        return $input;
    }
}
