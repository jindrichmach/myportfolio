<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Backup;

use ZipArchive;

/** Seznam DB záloh z cron-backup (`<db>-YYYY-MM-DD[_HH-MM].zip`) ve složce záloh. */
final class BackupCatalog
{
    private const NAME_PATTERN = '/^[A-Za-z0-9_]+-(\d{4}-\d{2}-\d{2})(?:_(\d{2})-(\d{2}))?\.zip$/';

    public function __construct(private readonly string $dir)
    {
    }

    /** @return list<array{name: string, created_at: string, size: int, encrypted: bool}> od nejnovější */
    public function list(): array
    {
        $out = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            if (preg_match(self::NAME_PATTERN, $name, $m) !== 1 || !is_file($this->dir . '/' . $name)) {
                continue;
            }
            $path = $this->dir . '/' . $name;
            $out[] = [
                'name' => $name,
                'created_at' => sprintf('%s %s:%s:00', $m[1], $m[2] ?? '00', $m[3] ?? '00'),
                'size' => (int) filesize($path),
                'encrypted' => $this->isEncrypted($path),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['created_at'], $b['name']] <=> [$a['created_at'], $a['name']]);
        return $out;
    }

    public function find(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        $path = $this->dir . '/' . $name;
        return is_file($path) ? $path : null;
    }

    private function isEncrypted(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $encrypted = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false && str_ends_with($stat['name'], '.sql') && !str_contains($stat['name'], '/')) {
                $encrypted = $stat['encryption_method'] !== 0;
                break;
            }
        }
        $zip->close();
        return $encrypted;
    }
}
