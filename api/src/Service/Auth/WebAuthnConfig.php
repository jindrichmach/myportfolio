<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;

final class WebAuthnConfig
{
    private readonly string $rpId;
    private readonly string $origin;

    public function __construct(Config $config)
    {
        [$this->rpId, $this->origin] = self::normalize((string) $config->get('app.url', ''));
    }

    public function rpId(): string
    {
        return $this->rpId;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function normalize(string $appUrl): array
    {
        $appUrl = trim($appUrl);
        if ($appUrl === '') {
            throw new \InvalidArgumentException('Pro WebAuthn musí být nastavené canonical app.url.');
        }

        try {
            $parts = parse_url($appUrl);
        } catch (\ValueError) {
            $parts = false;
        }

        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Canonical app.url není platná URL.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Canonical app.url nesmí obsahovat userinfo, query ani fragment.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        $path   = (string) ($parts['path'] ?? '');
        $port   = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($scheme !== 'https' && !($scheme === 'http' && $host === 'localhost')) {
            throw new \InvalidArgumentException('WebAuthn vyžaduje HTTPS; HTTP je povolené pouze pro localhost.');
        }
        if ($path !== '' && $path !== '/') {
            throw new \InvalidArgumentException('Canonical app.url nesmí obsahovat cestu.');
        }
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !self::isValidAsciiHostname($host)) {
            throw new \InvalidArgumentException('WebAuthn vyžaduje platný ASCII DNS hostname, nikoli IP adresu.');
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Canonical app.url obsahuje neplatný port.');
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $origin = $scheme . '://' . $host;
        if ($port !== null && $port !== $defaultPort) {
            $origin .= ':' . $port;
        }

        return [$host, $origin];
    }

    private static function isValidAsciiHostname(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }
        if (strlen($host) > 253 || str_ends_with($host, '.')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) !== 1
            ) {
                return false;
            }
        }

        return true;
    }
}
