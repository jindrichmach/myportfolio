<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

/**
 * Najde externí binárku (např. klient mariadb) — cesta z cfg má přednost, pak PATH a nakonec běžné systémové
 * adresáře: PHP-FPM mívá minimální (i prázdnou) PATH, i když je binárka nainstalovaná.
 */
final class CommandLocator
{
    private const FALLBACK_DIRS = ['/usr/local/bin', '/usr/bin', '/bin'];

    /** @param list<string>|null $fallbackDirs */
    public function __construct(
        private readonly ?string $path = null,
        private readonly ?array $fallbackDirs = null,
    ) {}

    public function resolve(string $name, string $configured = ''): ?string
    {
        if ($configured !== '') {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }
        $path = $this->path ?? (string) getenv('PATH');
        $dirs = array_merge(explode(PATH_SEPARATOR, $path), $this->fallbackDirs ?? self::FALLBACK_DIRS);
        foreach ($dirs as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
