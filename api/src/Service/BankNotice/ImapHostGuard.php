<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Support\PublicIp;

/**
 * IMAP schránku zadává uživatel — server se pak připojí na jeho host a port. Aby to nešlo zneužít k dotazům
 * do vnitřní sítě serveru (SSRF), host se přeloží a odmítne se loopback, privátní, link-local, CGNAT a rezervované
 * adresy (vč. metadat cloudu 169.254.169.254). Kontroluje se při uložení i těsně před připojením (DNS rebinding).
 * Self-hosted instance s poštovním serverem v LAN to povolí `bank_notices.allow_private_imap = true`.
 */
final class ImapHostGuard
{
    public function __construct(private readonly Config $config) {}

    /**
     * Port jen ze seznamu (výchozí IMAP 143 a IMAPS 993) — libovolný port by šel použít ke zkoumání
     * služeb na veřejných serverech. Jiný port správce povolí v `bank_notices.allowed_ports`.
     */
    public function checkPort(int $port): ?string
    {
        $allowed = array_map('intval', (array) $this->config->get('bank_notices.allowed_ports', [143, 993]));
        return in_array($port, $allowed, true) ? null : 'Port musí být ' . implode(' nebo ', $allowed) . ' (IMAP).';
    }

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
            if (!PublicIp::isPublic($ip)) {
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
}
