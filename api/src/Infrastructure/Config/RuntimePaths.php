<?php

declare(strict_types=1);

namespace MyPortfolio\Infrastructure\Config;

use MyPortfolio\Bootstrap;

/**
 * Centrální resolver runtime cest (issue #53).
 *
 * Jediné místo, které určuje kořen stateful dat:
 *   - pokud je nastavená ENV `MYPORTFOLIO_DATA_DIR`, žije vše pod ní (Docker
 *     single-volume / read-only root filesystem),
 *   - jinak fallback na kořen repa (Bootstrap::rootDir()).
 *
 * Runtime kód NESMÍ skládat cesty přes `Bootstrap::rootDir() . '/storage/...'`
 * ani `__DIR__ . '/../../../storage/...'` — místo toho volá tenhle resolver,
 * aby se `MYPORTFOLIO_DATA_DIR` respektoval konzistentně (PDF cache, archivy,
 * loga, přílohy, importy, zálohy, logy …).
 *
 * Statický záměrně: použitelný i ze static metod (repository, SafeLogoPath)
 * a z CLI bin/cron skriptů, které si Config neumí snadno vstříknout.
 *
 * Layout odpovídá `Config::applyDataDirOverrides()` — pro default (bez
 * MYPORTFOLIO_DATA_DIR) i pro data-dir variantu dává stejné cesty jako cfg
 * klíče `storage.*` / `logging.path`, takže nedochází k rozejití.
 */
final class RuntimePaths
{
    /** Kořen stateful dat — MYPORTFOLIO_DATA_DIR (pokud nastaveno), jinak root repa. */
    public static function base(): string
    {
        return Config::resolveDataDir() ?? Bootstrap::rootDir();
    }

    /** `${base}/storage[/$sub]`. $sub může obsahovat víc segmentů (např. 'cache/mpdf'). */
    public static function storage(string $sub = ''): string
    {
        $dir = self::base() . '/storage';
        return $sub === '' ? $dir : $dir . '/' . ltrim($sub, '/\\');
    }

    /** `${base}/log[/$sub]`. */
    public static function log(string $sub = ''): string
    {
        $dir = self::base() . '/log';
        return $sub === '' ? $dir : $dir . '/' . ltrim($sub, '/\\');
    }
}
