<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Update;

use Closure;
use MyPortfolio\Service\Update\BundleUpdater;
use MyPortfolio\Service\Update\MaintenanceMode;
use MyPortfolio\Service\Update\UpdateState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Celá pipeline nad lokálním fixture balíčkem — bez sítě a bez skutečných podprocesů. */
final class BundleUpdaterTest extends TestCase
{
    private const API = 'https://api.github.com/repos/o/r/releases/tags/v1.1.0';
    private const ASSET = 'https://github.com/o/r/releases/download/v1.1.0/myportfolio-1.1.0.tar.gz';
    private const SHA_ASSET = 'https://github.com/o/r/releases/download/v1.1.0/myportfolio-1.1.0.tar.gz.sha256';
    private const BLOB = 'https://objects.githubusercontent.com/blob/1';

    private string $tmp;
    private string $root;
    private string $bundle;
    private UpdateState $state;
    /** @var list<string> */
    private array $commands = [];
    /** @var list<string> */
    private array $requested = [];
    /** @var array<string,string> */
    private array $observed = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/mp-bundle-' . bin2hex(random_bytes(5));
        $this->root = $this->tmp . '/root';
        $this->put($this->root . '/VERSION', "1.0.0\n");
        $this->put($this->root . '/cfg.local.php', 'MOJE');
        $this->put($this->root . '/api/src/Old.php', 'stary');
        $this->put($this->root . '/web/dist/old.js', 'stary-asset');

        $pkg = $this->tmp . '/pkg/myportfolio-1.1.0';
        foreach ([
            'VERSION' => "1.1.0\n",
            'api/vendor/autoload.php' => '<?php',
            'api/public/index.php' => '<?php',
            'api/bin/migrate.php' => '<?php',
            'api/src/Old.php' => 'novy',
            'api/src/New.php' => 'novy',
            'web/dist/index.html' => '<html>',
            'db/migrations/0001_x.sql' => 'SELECT 1;',
        ] as $rel => $content) {
            $this->put($pkg . '/' . $rel, $content);
        }
        $this->bundle = $this->buildArchive($this->tmp . '/pkg', 'myportfolio-1.1.0', $this->tmp . '/myportfolio-1.1.0.tar.gz');
        $this->state = new UpdateState($this->tmp . '/state.json');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    public function testFullRunDeploysBundleAndWritesVersionAfterMigrate(): void
    {
        $result = $this->updater(runner: function (string $cmd): int {
            if (str_contains($cmd, 'migrate.php')) {
                $this->observed['version_at_migrate'] = trim((string) file_get_contents($this->root . '/VERSION'));
                $this->observed['new_at_migrate'] = (string) file_get_contents($this->root . '/api/src/New.php');
                $this->observed['maintenance'] = is_file(MaintenanceMode::path($this->root)) ? 'on' : 'off';
            }

            return 0;
        })->run('1.1.0');

        self::assertSame('succeeded', $result);
        self::assertSame('1.0.0', $this->observed['version_at_migrate']);
        self::assertSame('novy', $this->observed['new_at_migrate']);
        self::assertSame('on', $this->observed['maintenance']);
        self::assertCount(2, $this->commands);
        self::assertStringContainsString('cron-backup.php', $this->commands[0]);
        self::assertStringContainsString('migrate.php', $this->commands[1]);

        self::assertSame("1.1.0\n", file_get_contents($this->root . '/VERSION'));
        self::assertSame('novy', file_get_contents($this->root . '/api/src/Old.php'));
        self::assertFileDoesNotExist($this->root . '/web/dist/old.js');
        self::assertSame('MOJE', file_get_contents($this->root . '/cfg.local.php'));
        self::assertSame('stary', file_get_contents($this->root . '/storage/update/backup/api/src/Old.php'));
        self::assertDirectoryDoesNotExist($this->root . '/storage/update/stage');
        self::assertFileDoesNotExist($this->root . '/storage/update/myportfolio-1.1.0.tar.gz');
        self::assertFileDoesNotExist(MaintenanceMode::path($this->root));

        $state = $this->state->read();
        self::assertSame('succeeded', $state['status']);
        self::assertSame('1.0.0', $state['from']);
        self::assertSame('1.1.0', $state['target']);
        self::assertNull($state['error_step']);
    }

    public function testBadChecksumFailsAtVerifyAndDeletesBundle(): void
    {
        $result = $this->updater(sha: str_repeat('0', 64))->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('verify', $this->state->read()['error_step']);
        self::assertFileDoesNotExist($this->root . '/storage/update/myportfolio-1.1.0.tar.gz');
        self::assertSame("1.0.0\n", file_get_contents($this->root . '/VERSION'));
        self::assertSame('stary', file_get_contents($this->root . '/api/src/Old.php'));
        self::assertFileDoesNotExist(MaintenanceMode::path($this->root));
    }

    public function testAssetHostOutsideAllowlistIsRejected(): void
    {
        $result = $this->updater(assetUrl: 'https://evil.example/myportfolio-1.1.0.tar.gz')->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('download', $this->state->read()['error_step']);
        self::assertNotContains('https://evil.example/myportfolio-1.1.0.tar.gz', $this->requested);
    }

    public function testRedirectToHostOutsideAllowlistIsRejected(): void
    {
        $result = $this->updater(redirectTo: 'https://evil.example/blob')->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('download', $this->state->read()['error_step']);
        self::assertNotContains('https://evil.example/blob', $this->requested);
    }

    public function testPlainHttpIsRejected(): void
    {
        $result = $this->updater(assetUrl: 'http://github.com/o/r/releases/download/v1.1.0/myportfolio-1.1.0.tar.gz')->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('download', $this->state->read()['error_step']);
        self::assertSame([self::API], $this->requested);
    }

    public function testRedirectToHttpIsRejected(): void
    {
        $result = $this->updater(redirectTo: 'http://objects.githubusercontent.com/blob/1')->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('download', $this->state->read()['error_step']);
    }

    public function testMissingAssetFails(): void
    {
        $result = $this->updater(withAsset: false)->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('download', $this->state->read()['error_step']);
    }

    public function testSizeMismatchFails(): void
    {
        $result = $this->updater(size: 12345)->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('download', $this->state->read()['error_step']);
        self::assertSame("1.0.0\n", file_get_contents($this->root . '/VERSION'));
    }

    public function testUnknownReleaseFailsAtDownload(): void
    {
        $result = $this->updater()->run('1.2.0');

        self::assertSame('failed', $result);
        self::assertSame('download', $this->state->read()['error_step']);
    }

    public function testStagedVersionMismatchFailsAtExtract(): void
    {
        $this->put($this->tmp . '/pkg/myportfolio-1.1.0/VERSION', "9.9.9\n");
        $this->bundle = $this->buildArchive($this->tmp . '/pkg', 'myportfolio-1.1.0', $this->tmp . '/myportfolio-1.1.0.tar.gz');

        $result = $this->updater()->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('extract', $this->state->read()['error_step']);
        self::assertSame('stary', file_get_contents($this->root . '/api/src/Old.php'));
    }

    public function testMigrationFailureKeepsNewFilesAndVersionOld(): void
    {
        $result = $this->updater(runner: fn (string $cmd): int => str_contains($cmd, 'migrate.php') ? 1 : 0)->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('migrate', $this->state->read()['error_step']);
        self::assertSame('novy', file_get_contents($this->root . '/api/src/Old.php'));
        self::assertSame("1.0.0\n", file_get_contents($this->root . '/VERSION'));
        self::assertFileDoesNotExist(MaintenanceMode::path($this->root));
    }

    public function testSwapFailureRollsBackFiles(): void
    {
        $calls = 0;
        $result = $this->updater(beforeMove: function () use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('boom');
            }
        })->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('swap', $this->state->read()['error_step']);
        self::assertSame('stary', file_get_contents($this->root . '/api/src/Old.php'));
        self::assertFileDoesNotExist($this->root . '/api/src/New.php');
        self::assertSame('stary-asset', file_get_contents($this->root . '/web/dist/old.js'));
        self::assertSame("1.0.0\n", file_get_contents($this->root . '/VERSION'));
        self::assertCount(1, $this->commands, 'migrace se po selhání swapu nespouští');
        self::assertFileDoesNotExist(MaintenanceMode::path($this->root));
    }

    public function testBackupFailureStopsBeforeAnyChange(): void
    {
        $result = $this->updater(runner: fn (string $cmd): int => str_contains($cmd, 'cron-backup.php') ? 1 : 0)->run('1.1.0');

        self::assertSame('failed', $result);
        self::assertSame('backup', $this->state->read()['error_step']);
        self::assertSame([], $this->requested);
    }

    public function testRunningUpdateHoldsLock(): void
    {
        mkdir($this->root . '/storage', 0777, true);
        $h = fopen($this->root . '/storage/update.lock', 'c');
        flock($h, LOCK_EX);

        self::assertSame('locked', $this->updater()->run('1.1.0'));
        self::assertSame([], $this->requested);
        self::assertSame('idle', $this->state->read()['status']);

        fclose($h);
    }

    public function testInvalidVersionThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->updater()->run('../1.1.0');
    }

    /**
     * @param Closure(string):int|null $runner
     * @param Closure(string):void|null $beforeMove
     */
    private function updater(
        ?string $sha = null,
        ?string $assetUrl = null,
        ?string $redirectTo = null,
        bool $withAsset = true,
        ?int $size = null,
        ?Closure $runner = null,
        ?Closure $beforeMove = null,
    ): BundleUpdater {
        $sha ??= hash_file('sha256', $this->bundle);
        $assetUrl ??= self::ASSET;
        $redirectTo ??= self::BLOB;
        $size ??= (int) filesize($this->bundle);
        $assets = $withAsset ? [
            ['name' => 'myportfolio-1.1.0.tar.gz', 'browser_download_url' => $assetUrl, 'size' => $size],
            ['name' => 'myportfolio-1.1.0.tar.gz.sha256', 'browser_download_url' => self::SHA_ASSET, 'size' => 80],
        ] : [];

        $transport = function (string $url, ?string $dest) use ($assets, $assetUrl, $redirectTo, $sha): array {
            $this->requested[] = $url;
            if ($url === self::API) {
                return [200, null, json_encode(['assets' => $assets])];
            }
            if ($url === self::SHA_ASSET) {
                return [200, null, $sha . '  myportfolio-1.1.0.tar.gz'];
            }
            if ($url === $assetUrl) {
                return [302, $redirectTo, ''];
            }
            if ($url === self::BLOB || $url === $redirectTo) {
                copy($this->bundle, (string) $dest);

                return [200, null, ''];
            }

            return [404, null, ''];
        };
        $run = function (string $cmd, string $cwd, string $log) use ($runner): int {
            $this->commands[] = $cmd;

            return $runner !== null ? $runner($cmd) : 0;
        };

        return new BundleUpdater(
            $this->root,
            $beforeMove,
            $this->root,
            $this->state,
            'o/r',
            $transport,
            $run,
            'php',
        );
    }

    private function put(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function buildArchive(string $parent, string $dir, string $out): string
    {
        exec('tar -czf ' . escapeshellarg($out) . ' -C ' . escapeshellarg($parent) . ' ' . escapeshellarg($dir), $o, $rc);
        self::assertSame(0, $rc);

        return $out;
    }
}
