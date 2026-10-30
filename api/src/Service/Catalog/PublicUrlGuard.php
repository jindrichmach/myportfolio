<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Catalog;

use MyPortfolio\Service\Portfolio\DomainError;

/**
 * Povolí jen `https` adresy, jejichž host se překládá výhradně na veřejné IP — server tak na pokyn uživatele
 * nestahuje z interní sítě (SSRF). Kontroluje se úvodní adresa i každé přesměrování.
 */
final class PublicUrlGuard
{
    /** @param \Closure(string):list<string>|null $resolve DNS → IP adresy (v testech podvržené) */
    public function __construct(private readonly ?\Closure $resolve = null) {}

    /** @return string normalizovaná URL (https://host/cesta) */
    public function assert(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw DomainError::validation(['website' => ['Web instituce musí být adresa https://…']]);
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve ?? self::dns(...))($host);
        if ($ips === []) {
            throw DomainError::validation(['website' => ['Adresu webu se nepodařilo najít.']]);
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw DomainError::validation(['website' => ['Web instituce musí být veřejná adresa.']]);
            }
        }
        return "https://$host" . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** @return list<string> */
    private static function dns(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');
        return [...$v4, ...$v6];
    }
}
