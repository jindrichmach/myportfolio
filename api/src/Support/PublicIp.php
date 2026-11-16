<?php

declare(strict_types=1);

namespace MyPortfolio\Support;

/**
 * Je IP adresa veřejná? Odmítne loopback, privátní, rezervované, link-local (metadata cloudu 169.254.169.254),
 * CGNAT 100.64.0.0/10 (mj. Tailscale) a IPv4-mapped IPv6 podle IPv4 části. Sdílí ji ochrana proti SSRF
 * u IMAP schránek i u stahování loga z webu instituce.
 */
final class PublicIp
{
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            $n = unpack('N', $bin)[1];
            // 100.64.0.0/10 (CGNAT), 169.254.0.0/16 (link-local, metadata), 0.0.0.0/8
            return ($n & 0xFFC00000) !== 0x64400000 && ($n & 0xFFFF0000) !== 0xA9FE0000 && ($n >> 24) !== 0;
        }
        // IPv6: ::1, fe80::/10, fc00::/7, IPv4-mapped (::ffff:a.b.c.d) podle IPv4 části
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF")) {
            return self::isPublic((string) inet_ntop(substr($bin, 12)));
        }
        $first = ord($bin[0]);
        return $bin !== str_repeat("\0", 15) . "\1" && ($first & 0xFE) !== 0xFC && !($first === 0xFE && (ord($bin[1]) & 0xC0) === 0x80);
    }
}
