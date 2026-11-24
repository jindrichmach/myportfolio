<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Backup;

use MyPortfolio\Service\Backup\RestoreRunner;
use MyPortfolio\Service\Backup\RestoreState;
use MyPortfolio\Service\Cron\CronRun;
use MyPortfolio\Service\Update\MaintenanceMode;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RestoreStateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/restore-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        putenv('MYPORTFOLIO_RESTORE_RUNNER');
    }

    private function state(): RestoreState
    {
        return new RestoreState($this->dir . '/storage/restore-state.json');
    }

    public function testReadWriteMerge(): void
    {
        $state = $this->state();
        self::assertSame(['status' => 'idle'], $state->read());
        $state->write(['status' => 'running', 'file' => 'a.zip']);
        $state->merge(['step' => 'import']);
        self::assertEquals(['status' => 'running', 'file' => 'a.zip', 'step' => 'import'], $state->read());
    }

    public function testSuccessRecordsStepsAndEndsMaintenance(): void
    {
        $seen = [];
        $maint = MaintenanceMode::path($this->dir);
        $steps = [];
        foreach (['safety_backup', 'import'] as $name) {
            $steps[$name] = function () use ($name, $maint, &$seen): void {
                $flag = json_decode((string) file_get_contents($maint), true);
                $seen[$name] = [$flag['reason'], $flag['step']];
            };
        }
        $ok = (new RestoreRunner($this->state(), $this->dir, static function (string $l): void {}))->run($steps, ['file' => 'a.zip']);

        self::assertTrue($ok);
        self::assertSame(['safety_backup' => ['restore', 'safety_backup'], 'import' => ['restore', 'import']], $seen);
        self::assertFileDoesNotExist($maint);
        $st = $this->state()->read();
        self::assertSame('succeeded', $st['status']);
        self::assertSame('done', $st['step']);
        self::assertSame('a.zip', $st['file']);
    }

    public function testFailedStepRecordsErrorStepAndEndsMaintenance(): void
    {
        $log = [];
        $ran = [];
        $ok = (new RestoreRunner($this->state(), $this->dir, static function (string $l) use (&$log): void { $log[] = $l; }))->run([
            'extract' => static function () use (&$ran): void { $ran[] = 'extract'; },
            'import' => static function (): void { throw new RuntimeException('Import selhal (kód 1)'); },
            'migrate' => static function () use (&$ran): void { $ran[] = 'migrate'; },
        ]);

        self::assertFalse($ok);
        self::assertSame(['extract'], $ran);
        self::assertFileDoesNotExist(MaintenanceMode::path($this->dir));
        $st = $this->state()->read();
        self::assertSame('failed', $st['status']);
        self::assertSame('import', $st['error_step']);
        self::assertContains('CHYBA: Import selhal (kód 1)', $log);
    }

    public function testCronSkipsOnlyDuringRestoreMaintenance(): void
    {
        $file = MaintenanceMode::path($this->dir);
        self::assertFalse(CronRun::restoreInProgress($file));

        MaintenanceMode::begin($this->dir, 'MyPortfolio', '1.0.0');
        self::assertFalse(CronRun::restoreInProgress($file), 'údržba kvůli aktualizaci cron neskipuje');

        MaintenanceMode::begin($this->dir, 'MyPortfolio', '', 'import', 'restore');
        self::assertTrue(CronRun::restoreInProgress($file));

        putenv('MYPORTFOLIO_RESTORE_RUNNER=1');
        self::assertFalse(CronRun::restoreInProgress($file), 'potomci runneru neskipují');
        putenv('MYPORTFOLIO_RESTORE_RUNNER');

        file_put_contents($file, json_encode(['reason' => 'restore', 'expires_at' => date(DATE_ATOM, time() - 5)]));
        self::assertFalse(CronRun::restoreInProgress($file), 'expirovaná značka');
    }
}
