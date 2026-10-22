<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

use MyPortfolio\Infrastructure\Config\Config;

/**
 * IMAP schránku zadává uživatel — server se pak připojí na jeho host a port. Aby to nešlo zneužít k dotazům
 * do vnitřní sítě serveru (SSRF), host se přeloží a odmítne se loopback, privátní, link-local, CGNAT a rezervované
 * adresy (vč. metadat cloudu 169.254.169.254). Kontroluje se při uložení i těsně před připojením (DNS rebinding).
 * Self-hosted instance s poštovním serverem v LAN to povolí `bank_notices.allow_private_imap = true`.
 */
final class ImapHostGuard
{
    public function __construct(private readonly Config $config) {}

    /** Chybová hláška, nebo null když je host povolený. */
    public function check(string $host): ?string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));
        if ($host === '' || preg_match('/^[a-z0-9.\-:]+$/', $host) !== 1) {
            return 'Neplatný název serveru.';
        }
        if ((bool) $this->config->get('bank_notices.allow_private_imap', false)) {
            return null;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        if ($ips === []) {
            return 'Server ' . $host . ' nejde přeložit na IP adresu.';
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                return 'Server ' . $host . ' vede na neveřejnou adresu (místní nebo vnitřní síť) — to není povolené.';
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (isset($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

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
