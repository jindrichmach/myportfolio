<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Infrastructure\Config\RuntimePaths;

/**
 * Instalační kód pro první spuštění (/setup): bez něj si na čerstvé instanci vystavené do sítě
 * může správce založit kdokoli. Kód vygeneruje migrate.php (vypíše ho do konzole / `docker logs`)
 * do `storage/setup-token`; průvodce ho posílá v hlavičce X-Setup-Token a po založení správce se smaže.
 */
final class SetupToken
{
    public const HEADER = 'X-Setup-Token';

    private readonly string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? RuntimePaths::storage('setup-token');
    }

    /** Vrátí existující kód, nebo vytvoří nový. */
    public function ensure(): string
    {
        $existing = $this->read();
        if ($existing !== null) {
            return $existing;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $token = bin2hex(random_bytes(16));
        if (@file_put_contents($this->file, $token . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("Instalační kód nejde zapsat do {$this->file}.");
        }
        @chmod($this->file, 0640);
        // migrate.php běží v Dockeru jako root — soubor musí číst php-fpm (vlastník adresáře storage)
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($owner = @fileowner($dir)) !== false) {
            @chown($this->file, $owner);
            @chgrp($this->file, (int) @filegroup($dir));
        }
        return $token;
    }

    public function matches(string $given): bool
    {
        $token = $this->read();
        return $token !== null && $given !== '' && hash_equals($token, trim($given));
    }

    public function clear(): void
    {
        @unlink($this->file);
    }

    public function path(): string
    {
        return $this->file;
    }

    private function read(): ?string
    {
        $raw = @file_get_contents($this->file);
        $token = is_string($raw) ? trim($raw) : '';
        return preg_match('/^[0-9a-f]{32}$/', $token) === 1 ? $token : null;
    }
}
