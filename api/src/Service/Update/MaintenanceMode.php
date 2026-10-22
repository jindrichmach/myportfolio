<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

/**
 * Značka „aplikace je v údržbě" pro okno nasazení a migrací.
 *
 * Přítomnost souboru `storage/maintenance.json` znamená 503 „probíhá údržba".
 * Čtenář je schválně inline v `api/public/index.php` a nesmí sahat na žádnou
 * třídu — kdyby brána sama potřebovala autoloader, selhala by přesně tam, kde
 * má chránit (nekonzistentní stav souborů během nasazení).
 *
 * Značka MÁ EXPIRACI, a to je funkční požadavek: kdyby proces spadl uprostřed
 * nasazení, zůstal by soubor ležet a instalace by hlásila údržbu navěky. Po
 * expiraci brána mlčí a případný rozbitý stav se projeví normální chybou v logu.
 */
final class MaintenanceMode
{
    /** Jméno značky ve `storage/`. */
    public const FILE = 'maintenance.json';

    /** Po jak dlouhém tichu se značka ignoruje (běžící nasazení je nejspíš mrtvé). */
    public const TTL_SECONDS = 1800;

    private function __construct() {}

    public static function path(string $stateDir): string
    {
        return rtrim($stateDir, '/\\') . '/storage/' . self::FILE;
    }

    /**
     * Zapíše (nebo prodlouží) značku. Volá se před nasazením a znovu při každém
     * hlášení průběhu, aby dlouhý přechod nepropadl expirací zrovna uprostřed.
     */
    public static function begin(string $stateDir, string $product, string $target): void
    {
        $path = self::path($stateDir);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $now      = time();
        $existing = self::read($path);

        @file_put_contents($path, json_encode([
            'reason'     => 'update',
            'product'    => $product,
            'target'     => $target,
            'started_at' => $existing['started_at'] ?? date(\DateTimeInterface::ATOM, $now),
            'expires_at' => date(\DateTimeInterface::ATOM, $now + self::TTL_SECONDS),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Uklidí značku. Volá se ve `finally` — i nasazení, které skončilo chybou,
     * musí instalaci vrátit do provozu, jinak ji drží dole až do expirace.
     */
    public static function end(string $stateDir): void
    {
        @unlink(self::path($stateDir));
    }

    /** @return array<string,mixed>|null */
    private static function read(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
