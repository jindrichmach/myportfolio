<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

/**
 * Najde externí binárku (git, composer, pnpm) — cesta z cfg má přednost, jinak se hledá na PATH
 * (PHP-FPM má často minimální PATH, proto jde cestu přepsat v `update.*_bin`).
 */
final class CommandLocator
{
    public function __construct(private readonly ?string $path = null) {}

    public function resolve(string $name, string $configured = ''): ?string
    {
        if ($configured !== '') {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }
        $path = $this->path ?? (string) getenv('PATH');
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
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
