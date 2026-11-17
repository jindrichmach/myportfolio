<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Update;

use MyPortfolio\Service\Update\MaintenanceMode;
use MyPortfolio\Service\Update\UpdateState;
use PHPUnit\Framework\TestCase;

final class SelfUpdateRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/selfupd-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/root/storage', 0775, true);
        mkdir($this->dir . '/bin', 0775, true);
        file_put_contents($this->dir . '/root/VERSION', "1.0.0\n");
        foreach (['php', 'git', 'composer', 'pnpm'] as $name) {
            $failOn = $name === 'php' ? 'composer' : '__never__';
            file_put_contents(
                $this->dir . "/bin/{$name}",
                "#!/bin/sh\necho \"{$name} \$*\" >> " . $this->dir . "/calls.txt\n"
                . "case \"\$*\" in *{$failOn}*) [ -n \"\$STUB_FAIL\" ] && exit 1;; esac\n"
                . ($name === 'git' ? "case \"\$*\" in *fetch*) cp " . $this->dir . "/root/storage/maintenance.json " . $this->dir . "/maint-at-fetch.json;; esac\ncase \"\$*\" in *checkout*) echo 1.1.0 > " . $this->dir . "/root/VERSION;; esac\n" : '')
                . "exit 0\n"
            );
            chmod($this->dir . "/bin/{$name}", 0755);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array{0:int,1:string} */
    private function runner(array $extra = [], array $env = []): array
    {
        $args = [
            '--target=1.1.0', '--root=' . $this->dir . '/root', '--state=' . $this->dir . '/root/storage/update-state.json',
            '--log=' . $this->dir . '/root/log/update.log', '--maintenance=' . $this->dir . '/root/storage/maintenance.json',
            '--php=' . $this->dir . '/bin/php', '--git=' . $this->dir . '/bin/git',
            '--composer=' . $this->dir . '/bin/composer', '--pnpm=' . $this->dir . '/bin/pnpm',
            ...$extra,
        ];
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../../../bin/self-update.php');
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $out = [];
        $code = 0;
        $prefix = '';
        foreach ($env as $k => $v) {
            $prefix .= $k . '=' . escapeshellarg($v) . ' ';
        }
        exec($prefix . $cmd . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    }

    /** @return list<string> */
    private function expectedPlan(bool $dev): array
    {
        $r = $this->dir . '/root';
        $php = escapeshellarg($this->dir . '/bin/php');
        $git = escapeshellarg($this->dir . '/bin/git');
        $web = escapeshellarg($r . '/web');
        $pnpm = escapeshellarg($this->dir . '/bin/pnpm');

        return [
            'backup: ' . $php . ' ' . escapeshellarg($r . '/api/bin/cron-backup.php'),
            'maintenance: (interní krok)',
            'fetch: ' . $git . ' fetch --tags',
            'checkout: ' . $git . " checkout 'v1.1.0'",
            'composer: ' . $php . ' ' . escapeshellarg($this->dir . '/bin/composer') . ' install --working-dir=' . escapeshellarg($r . '/api')
                . ' --no-interaction' . ($dev ? '' : ' --no-dev --optimize-autoloader'),
            'migrate: ' . $php . ' ' . escapeshellarg($r . '/api/bin/migrate.php'),
            'pnpm_install: ' . $pnpm . ' --dir ' . $web . ' install --frozen-lockfile',
            'pnpm_build: ' . $pnpm . ' --dir ' . $web . ' build',
            'verify: (interní krok)',
        ];
    }

    public function testDryRunListsExactCommandsInOrder(): void
    {
        [$code, $out] = $this->runner(['--dry-run']);
        self::assertSame(0, $code);
        self::assertSame($this->expectedPlan(false), explode("\n", trim($out)));
        self::assertFileDoesNotExist($this->dir . '/calls.txt');
        self::assertFileDoesNotExist($this->dir . '/root/storage/update-state.json');
    }

    public function testDevelopmentEnvKeepsDevDependencies(): void
    {
        [$code, $out] = $this->runner(['--dry-run', '--env=development']);
        self::assertSame(0, $code);
        self::assertSame($this->expectedPlan(true), explode("\n", trim($out)));
    }

    public function testSuccessfulRun(): void
    {
        [$code, $out] = $this->runner();
        self::assertSame(0, $code, $out);
        $state = (new UpdateState($this->dir . '/root/storage/update-state.json'))->read();
        self::assertSame('succeeded', $state['status']);
        self::assertSame('1.0.0', $state['from']);
        self::assertFileDoesNotExist(MaintenanceMode::path($this->dir . '/root'));
        self::assertStringContainsString('checkout', (string) file_get_contents($this->dir . '/calls.txt'));
        self::assertFileExists($this->dir . '/maint-at-fetch.json');
        $m = json_decode((string) file_get_contents($this->dir . '/maint-at-fetch.json'), true);
        self::assertSame('fetch', $m['step']);
        self::assertGreaterThan(time(), strtotime($m['expires_at']));
    }

    public function testFailedStepStopsAndTurnsMaintenanceOff(): void
    {
        [$code] = $this->runner([], ['STUB_FAIL' => '1']);
        self::assertSame(1, $code);
        $state = (new UpdateState($this->dir . '/root/storage/update-state.json'))->read();
        self::assertSame('failed', $state['status']);
        self::assertSame('composer', $state['error_step']);
        self::assertFileDoesNotExist(MaintenanceMode::path($this->dir . '/root'));
        $calls = (string) file_get_contents($this->dir . '/calls.txt');
        self::assertLessThan(strpos($calls, 'git fetch'), strpos($calls, 'cron-backup.php'));
        self::assertStringNotContainsString('migrate', $calls);
        self::assertStringNotContainsString('pnpm', $calls);
    }

    public function testSecondRunnerIsRejectedWhileLockIsHeld(): void
    {
        $h = fopen($this->dir . '/root/storage/update.lock', 'c');
        flock($h, LOCK_EX);
        [$code] = $this->runner();
        self::assertSame(2, $code);
        fclose($h);
    }
}
