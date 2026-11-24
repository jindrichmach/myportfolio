<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

use MyPortfolio\Infrastructure\Config\RuntimePaths;

/**
 * Stav běhu aktualizace z webu v `storage/update-state.json`
 * (`status` idle|running|succeeded|failed, `from`, `target`, `step`, `started_at`, `finished_at`, `error_step`, `pid`).
 * Zapisuje ho `BundleUpdater` (spouští ho `api/bin/native-update.php`), čte ho stránka Systém → Aktualizace.
 */
final class UpdateState
{
    public function __construct(private readonly ?string $file = null) {}

    public function path(): string
    {
        return $this->file ?? RuntimePaths::storage('update-state.json');
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
}
