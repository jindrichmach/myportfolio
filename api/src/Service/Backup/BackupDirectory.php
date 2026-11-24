<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Backup;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Config\RuntimePaths;

/**
 * Složka záloh v pořadí:
 *   1) cfg.cron.backup.output_dir (explicitní override)
 *   2) cfg.storage.backup_dir (sdílená dokumentovaná cesta)
 *   3) RuntimePaths::storage('backup') (MYPORTFOLIO_DATA_DIR, jinak root)
 */
final class BackupDirectory
{
    public static function resolve(Config $config): string
    {
        $dir = (string) $config->get('cron.backup.output_dir', '');
        if ($dir === '') {
            $dir = (string) $config->get('storage.backup_dir', '');
        }
        if ($dir === '') {
            $dir = RuntimePaths::storage('backup');
        }
        return $dir;
    }
}
