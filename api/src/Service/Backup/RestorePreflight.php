<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Backup;

use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Service\PhpCliLocator;
use MyPortfolio\Service\Update\CommandLocator;
use MyPortfolio\Service\Update\MaintenanceMode;
use MyPortfolio\Service\Update\UpdateState;

/**
 * Předběžná kontrola obnovy ze zálohy z webu. Vrací kódy důvodů, proč nejde (prázdný seznam = lze):
 * `no_zip`, `no_client`, `no_php`, `not_writable`, `restore_running`, `update_running`.
 * Běžící obnovu poznává podle `flock` na `restore.lock` — stav v souboru po zabitém procesu zůstane `running`.
 */
final class RestorePreflight
{
    /** Mezera mezi zápisem stavu `running` z API a převzetím zámku runnerem. */
    private const SPAWN_GRACE_SECONDS = 60;

    public function __construct(
        private readonly RestoreState $state,
        private readonly UpdateState $updateState,
        private readonly CommandLocator $locator = new CommandLocator(),
        private readonly ?string $restoreLock = null,
        private readonly ?string $updateLock = null,
        private readonly ?string $storageDir = null,
        private readonly ?bool $zipLoaded = null,
    ) {}

    public function lockFile(): string
    {
        return $this->restoreLock ?? RuntimePaths::storage('restore.lock');
    }

    /** @return list<string> */
    public function check(): array
    {
        $reasons = [];
        if (!($this->zipLoaded ?? extension_loaded('zip'))) {
            $reasons[] = 'no_zip';
        }
        if ($this->locator->resolve('mariadb') === null && $this->locator->resolve('mysql') === null) {
            $reasons[] = 'no_client';
        }
        if (PhpCliLocator::resolve() === null) {
            $reasons[] = 'no_php';
        }
        if (!self::writable($this->storageDir ?? RuntimePaths::storage())) {
            $reasons[] = 'not_writable';
        }
        if ($this->isRunning()) {
            $reasons[] = 'restore_running';
        }
        $update = $this->updateState->read();
        if (self::lockHeld($this->updateLock ?? RuntimePaths::storage('update.lock'))
            || (($update['status'] ?? '') === 'running'
                && (int) strtotime((string) ($update['started_at'] ?? '')) > time() - MaintenanceMode::TTL_SECONDS)) {
            $reasons[] = 'update_running';
        }

        return $reasons;
    }

    public function isRunning(): bool
    {
        if (self::lockHeld($this->lockFile())) {
            return true;
        }
        $state = $this->state->read();

        return ($state['status'] ?? '') === 'running'
            && (int) strtotime((string) ($state['started_at'] ?? '')) > time() - self::SPAWN_GRACE_SECONDS;
    }

    private static function lockHeld(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        $h = @fopen($file, 'c');
        if ($h === false) {
            return false;
        }
        $locked = !flock($h, LOCK_EX | LOCK_NB);
        if (!$locked) {
            flock($h, LOCK_UN);
        }
        fclose($h);

        return $locked;
    }

    private static function writable(string $path): bool
    {
        while (!file_exists($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                return false;
            }
            $path = $parent;
        }

        return is_writable($path);
    }
}
