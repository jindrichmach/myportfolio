<?php

declare(strict_types=1);

namespace MyPortfolio\Service;

use MyPortfolio\Infrastructure\Config\Config;

/**
 * Matchování IP adres proti seznamu pravidel — IPv4, IPv6, CIDR notace.
 *
 *  - "127.0.0.1"            = exact IPv4
 *  - "192.168.1.0/24"       = IPv4 podsíť
 *  - "::1"                  = exact IPv6
 *  - "2001:db8::/56"        = IPv6 prefix
 *  - "2001:db8:1234::/64"  = IPv6 /64
 *
 * IPv4-mapped IPv6 (::ffff:1.2.3.4) je automaticky normalizováno na IPv4.
 */
final class IpMatcher
{
    public function __construct(
        private readonly ?Config $config = null,
    ) {}

    /**
     * @param string[] $rules
     */
    public function matches(string $ip, array $rules): bool
    {
        $ip = $this->normalize($ip);
        if ($ip === null) {
            return false;
        }

        foreach ($rules as $rule) {
            if ($this->matchRule($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    private function matchRule(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }

        if (str_contains($rule, '/')) {
            [$subnet, $prefixStr] = explode('/', $rule, 2);
            $prefix = (int) $prefixStr;
            $subnet = $this->normalize(trim($subnet));
            if ($subnet === null) {
                return false;
            }
            return $this->cidrMatch($ip, $subnet, $prefix);
        }

        $exact = $this->normalize($rule);
        return $exact !== null && $exact === $ip;
    }

    private function cidrMatch(string $ip, string $subnet, int $prefix): bool
    {
        $ipBin     = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Musí být stejná rodina (4 vs 16 bytes)
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $bits) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        // Plné byty
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        // Zbývající bity v dalším bytu
        if ($remainingBits === 0) {
            return true;
        }

        $mask        = chr((0xFF << (8 - $remainingBits)) & 0xFF);
        $ipByte      = $ipBin[$bytes] ?? "\x00";
        $subnetByte  = $subnetBin[$bytes] ?? "\x00";

        return ($ipByte & $mask) === ($subnetByte & $mask);
    }

    /**
     * Normalizuje IP do kanonické textové podoby (pro IPv6 lowercase, expanded).
     * IPv4-mapped IPv6 (::ffff:1.2.3.4) → 1.2.3.4
     */
    private function normalize(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        // IPv4-mapped IPv6 → IPv4
        if (strlen($packed) === 16) {
            $prefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
            if (substr($packed, 0, 12) === $prefix) {
                $packed = substr($packed, 12);
            }
        }

        $back = inet_ntop($packed);
        return $back === false ? null : $back;
    }

    /**
     * Získá skutečnou client IP s ohledem na cfg.ip_allowlist.trusted_proxies + header.
     * Použití: `$this->ipMatcher->clientIpFromRequest($request->getServerParams())`.
     *
     * @param array<string,mixed> $serverParams
     */
    public function clientIpFromRequest(array $serverParams): string
    {
        $trusted = (array) ($this->config?->get('ip_allowlist.trusted_proxies', []) ?? []);
        $header  = (string) ($this->config?->get('ip_allowlist.header', 'X-Forwarded-For') ?? 'X-Forwarded-For');
        return $this->clientIp($serverParams, $trusted, $header);
    }

    /**
     * Získá skutečnou client IP s ohledem na trusted proxies.
     */
    public function clientIp(array $serverParams, array $trustedProxies, string $header = 'X-Forwarded-For'): string
    {
        $remote = (string) ($serverParams['REMOTE_ADDR'] ?? '');

        if ($remote === '' || empty($trustedProxies)) {
            return $this->normalize($remote) ?? $remote;
        }

        if (!$this->matches($remote, $trustedProxies)) {
            return $this->normalize($remote) ?? $remote;
        }

        $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($header));
        $forwarded = (string) ($serverParams[$headerKey] ?? '');
        if ($forwarded === '') {
            return $this->normalize($remote) ?? $remote;
        }

        // X-Forwarded-For může mít více IP oddělených čárkou; první je původní client
        $parts = array_map('trim', explode(',', $forwarded));
        $first = $parts[0] ?? '';
        if ($first === '') {
            return $this->normalize($remote) ?? $remote;
        }

        return $this->normalize($first) ?? $first;
    }
}
