<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Backup;

use MyPortfolio\Service\Update\MaintenanceMode;
use Throwable;

/**
 * Přechody stavů obnovy ze zálohy: každý krok zapíše stav a prodlouží značku údržby (`reason: restore`),
 * údržba se na konci vypne vždy — i při chybě. Samotné kroky skládá `api/bin/restore-backup.php`.
 */
final class RestoreRunner
{
    /** @param \Closure(string): void $log */
    public function __construct(
        private readonly RestoreState $state,
        private readonly string $stateDir,
        private readonly \Closure $log,
    ) {}

    /**
     * @param array<string, callable(): void> $steps krok => akce (při chybě vyhazuje výjimku)
     * @param array<string,mixed> $meta doplněk stavu (`file`, `actor_email`, …)
     */
    public function run(array $steps, array $meta = []): bool
    {
        $this->state->write($meta + [
            'status' => 'running',
            'step' => null,
            'started_at' => date(\DateTimeInterface::ATOM),
            'finished_at' => null,
            'error_step' => null,
        ]);
        $current = null;
        try {
            foreach ($steps as $name => $action) {
                $current = $name;
                $this->state->merge(['step' => $name]);
                MaintenanceMode::begin($this->stateDir, 'MyPortfolio', '', $name, 'restore');
                ($this->log)("Krok: $name");
                $action();
            }
            $this->state->merge(['status' => 'succeeded', 'step' => 'done', 'finished_at' => date(\DateTimeInterface::ATOM)]);

            return true;
        } catch (Throwable $e) {
            ($this->log)('CHYBA: ' . $e->getMessage());
            $this->state->merge([
                'status' => 'failed',
                'error_step' => $current,
                'finished_at' => date(\DateTimeInterface::ATOM),
            ]);

            return false;
        } finally {
            MaintenanceMode::end($this->stateDir);
        }
    }
}
