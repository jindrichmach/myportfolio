<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Aktualizace instalace z balíčku vydání — fáze swap a rollback.
 *
 * Swap nasazuje rozbalený `stage` přes instalaci PŘESUNEM (`rename`), ne kopií:
 * původní soubor odchází do zálohy, nový přichází ze stage, takže počet souborů
 * na svazku během swapu neroste (sdílený hosting s kvótou inodů). Obsahově
 * shodné soubory se nepřepisují. Pipeline (stažení, ověření, migrace, CLI)
 * na tuhle třídu navazuje.
 */
final class BundleUpdater
{
    /** Cesty, které swap nikdy nepřepíše ani nesmaže. */
    public const PROTECTED_PATHS = [
        'cfg.php', 'cfg.local.php', 'cfg.docker.php', '.env',
        'storage', 'log', 'private', '.git',
    ];

    /** Adresář, ze kterého se mažou soubory, jež nové vydání už nemá. */
    private const PRUNED_DIR = 'web/dist';

    /** @var list<string> přepsané soubory (původní verze je v záloze) */
    private array $replaced = [];
    /** @var list<string> nově přidané soubory (v záloze nic není) */
    private array $added = [];
    /** @var list<string> soubory přesunuté do zálohy bez náhrady (prune) */
    private array $pruned = [];

    /**
     * @param Closure(string):void|null $beforeMove hák volaný před nasazením každého souboru
     *                                              (relativní cesta); výjimka simuluje selhání přesunu
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly ?Closure $beforeMove = null,
    ) {}

    /**
     * Nasadí `$stage` do instalace, původní soubory uloží do `$backupDir`.
     * Při chybě provede rollback a vyhodí RuntimeException.
     * `VERSION` se přeskakuje (píše ho až závěr pipeline).
     *
     * @return int počet nasazených souborů
     */
    public function swap(string $stage, string $backupDir): int
    {
        $this->replaced = [];
        $this->added = [];
        $this->pruned = [];

        // Cesty se sbírají předem — swap staged soubory přesouvá a iterátor by si podřezával větev.
        $entries = [];
        $stageFiles = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($it as $item) {
            $rel = $this->relativePath($stage, $item->getPathname());
            if ($rel === null) {
                continue;
            }
            $isDir = $item->isDir();
            $entries[] = [$item->getPathname(), $rel, $isDir];
            if (!$isDir) {
                $stageFiles[$rel] = true;
            }
        }

        $files = 0;
        try {
            foreach ($entries as [$path, $rel, $isDir]) {
                if ($rel === 'VERSION' || $this->isProtected($rel)) {
                    continue;
                }
                $dest = $this->rootDir . '/' . $rel;
                if ($isDir) {
                    $this->ensureDir($dest);
                    continue;
                }
                if (!is_file($path)) {
                    continue;
                }
                $exists = is_file($dest);
                if ($exists && $this->sameContent($path, $dest)) {
                    @unlink($path);
                    continue;
                }
                if ($this->beforeMove !== null) {
                    ($this->beforeMove)($rel);
                }
                if ($exists) {
                    $this->backupFile($rel, $dest, $backupDir);
                    $this->replaced[] = $rel;
                } else {
                    $this->added[] = $rel;
                }
                $this->moveIntoPlace($path, $dest);
                $files++;
            }
            $this->prune($stageFiles, $backupDir);
        } catch (Throwable $e) {
            $restored = $this->rollback($backupDir);
            throw new RuntimeException(
                'Nasazení souborů selhalo (' . $e->getMessage() . '). Rollback vrátil ' . $restored . ' souborů ze zálohy.',
                0,
                $e
            );
        }

        return $files;
    }

    /**
     * Vrátí přepsané i smazané soubory ze zálohy a odstraní nově přidané.
     *
     * @return int počet obnovených (vrácených ze zálohy) souborů
     */
    public function rollback(string $backupDir): int
    {
        foreach ($this->added as $rel) {
            @unlink($this->rootDir . '/' . $rel);
        }
        $restored = 0;
        foreach ([...$this->replaced, ...$this->pruned] as $rel) {
            $src = $backupDir . '/' . $rel;
            if (!is_file($src)) {
                continue;
            }
            try {
                $this->restoreFile($src, $this->rootDir . '/' . $rel);
                $restored++;
            } catch (Throwable) {
                // pokračuj s ostatními souborů; instalace má být co nejúplnější
            }
        }
        $this->added = [];
        $this->replaced = [];
        $this->pruned = [];

        return $restored;
    }

    /** Leží relativní cesta v chráněné množině? */
    public function isProtected(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (self::PROTECTED_PATHS as $p) {
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Soubory z `web/dist`, které ve stage nejsou, přesune do zálohy.
     * Bez jakéhokoli `web/dist` souboru ve stage se neprunuje (neúplný balíček).
     *
     * @param array<string, true> $stageFiles
     */
    private function prune(array $stageFiles, string $backupDir): void
    {
        $prefix = self::PRUNED_DIR . '/';
        $hasDist = false;
        foreach ($stageFiles as $rel => $_) {
            if (str_starts_with($rel, $prefix)) {
                $hasDist = true;
                break;
            }
        }
        $dir = $this->rootDir . '/' . self::PRUNED_DIR;
        if (!$hasDist || !is_dir($dir)) {
            return;
        }
        $old = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $item */
        foreach ($it as $item) {
            $rel = $this->relativePath($this->rootDir, $item->getPathname());
            if ($rel !== null && !$item->isDir() && !isset($stageFiles[$rel])) {
                $old[] = $rel;
            }
        }
        foreach ($old as $rel) {
            if ($this->beforeMove !== null) {
                ($this->beforeMove)($rel);
            }
            $this->backupFile($rel, $this->rootDir . '/' . $rel, $backupDir);
            $this->pruned[] = $rel;
        }
    }

    private function sameContent(string $a, string $b): bool
    {
        $sizeA = @filesize($a);
        $sizeB = @filesize($b);
        if ($sizeA === false || $sizeB === false || $sizeA !== $sizeB) {
            return false;
        }
        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $hashA = @hash_file($algo, $a);
        $hashB = @hash_file($algo, $b);

        return $hashA !== false && $hashA === $hashB;
    }

    /** Odloží původní soubor do zálohy přesunem; kopie jen když přesun nejde (jiný svazek). */
    private function backupFile(string $rel, string $dest, string $backupDir): void
    {
        $target = $backupDir . '/' . $rel;
        $this->ensureDir(dirname($target));
        if (@rename($dest, $target)) {
            return;
        }
        if (!@copy($dest, $target)) {
            throw new RuntimeException('Nelze zazálohovat ' . $rel . ' (došlo místo nebo inody?).');
        }
        if (!@unlink($dest)) {
            throw new RuntimeException('Nelze odstranit ' . $rel . ' po zazálohování.');
        }
    }

    private function moveIntoPlace(string $src, string $dest): void
    {
        $this->ensureDir(dirname($dest));
        if (!file_exists($dest) && @rename($src, $dest)) {
            return;
        }
        $this->replaceFile($src, $dest);
    }

    /** Zápis vedle cíle a přejmenování (záloha přes kopii nebo přesun mezi svazky). */
    private function replaceFile(string $src, string $dest): void
    {
        $tmp = $dest . '.myportfolio-upd';
        if (!@copy($src, $tmp)) {
            throw new RuntimeException('Nelze zapsat ' . $tmp . '.');
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new RuntimeException('Nelze přepsat ' . $dest . '.');
        }
        @unlink($src);
    }

    /** Vrací soubor ze zálohy přesunem — rollback nesmí alokovat nové inody. */
    private function restoreFile(string $src, string $dest): void
    {
        $this->ensureDir(dirname($dest));
        if (@rename($src, $dest)) {
            return;
        }
        $this->replaceFile($src, $dest);
    }

    /** @return string|null relativní cesta s `/`, nebo null když leží mimo base */
    private function relativePath(string $base, string $path): ?string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $base)) {
            return null;
        }
        $rel = substr($path, strlen($base));

        return $rel === '' ? null : $rel;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nelze vytvořit adresář ' . $dir . '.');
        }
    }
}
