<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Backup;

use MyPortfolio\Infrastructure\Config\RuntimePaths;

/**
 * Stav běhu obnovy ze zálohy v `storage/restore-state.json`
 * (`status` idle|running|succeeded|failed, `file`, `safety_backup`, `step`, `started_at`, `finished_at`,
 * `error_step`, `actor_email`). Zapisuje ho `api/bin/restore-backup.php` přes {@see RestoreRunner}.
 */
final class RestoreState
{
    public function __construct(private readonly ?string $file = null) {}

    public function path(): string
    {
        return $this->file ?? RuntimePaths::storage('restore-state.json');
    }

    /** @return array<string,mixed> */
    public function read(): array
    {
        $raw = @file_get_contents($this->path());
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($data) ? $data : ['status' => 'idle'];
    }

    /** @param array<string,mixed> $state */
    public function write(array $state): void
    {
        $path = $this->path();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);
    }

    /** @param array<string,mixed> $patch */
    public function merge(array $patch): void
    {
        $this->write($patch + $this->read());
    }
}
