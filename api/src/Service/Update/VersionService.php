<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

use MyPortfolio\Bootstrap;

/**
 * Aktuální verze aplikace ze souboru `VERSION` v rootu repa.
 */
final class VersionService
{
    public function getCurrentVersion(): string
    {
        $path = Bootstrap::rootDir() . '/VERSION';
        if (!is_file($path)) {
            return 'unknown';
        }
        $v = trim((string) @file_get_contents($path));
        return $v !== '' ? $v : 'unknown';
    }
}
