<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Update;

use Closure;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Update\UpdateChecker;
use MyPortfolio\Service\Update\VersionService;
use MyPortfolio\Service\Update\WebUpdatePreflight;
use MyPortfolio\Tests\Support\FakeHttpFetcher;
use PHPUnit\Framework\TestCase;

final class WebUpdatePreflightTest extends TestCase
{
    private string $base;
    private string $root;
    private bool $bundle = true;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mp-preflight-' . bin2hex(random_bytes(4));
        $this->root = $this->base . '/app';
        foreach (['api', 'web', 'db', 'manual', 'storage/update'] as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }
        $this->bundle = true;
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwx ' . escapeshellarg($this->base) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->base));
    }

    /** @param array<string,mixed> $update */
    private function preflight(
        array $update = ['web_update' => true],
        ?string $dataDir = null,
        ?string $lock = null,
        ?string $php = '/usr/bin/php',
        ?Closure $ini = null,
        ?Closure $diskFree = null,
    ): WebUpdatePreflight {
        $current = (new VersionService())->getCurrentVersion();
        $newer = $current === '99.0.0' ? '99.0.1' : '99.0.0';
        file_put_contents($this->base . '/check.json', json_encode([
            'repo' => 'example/repo', 'checked_at' => date(\DateTimeInterface::ATOM),
            'releases' => [['version' => $newer, 'name' => $newer, 'published_at' => null, 'url' => '', 'notes' => '',
                'prerelease' => false, 'bundle' => $this->bundle]],
        ]));
        $config = new Config(['update' => $update + ['github_repo' => 'example/repo']], $dataDir);
        $checker = new UpdateChecker(new FakeHttpFetcher([]), new VersionService(), $config, $this->base . '/check.json');

        return new WebUpdatePreflight(
            $config, $this->root, $checker, $lock ?? $this->base . '/update.lock', $this->root, $php, $ini,
            $diskFree ?? static fn (string $dir): int => PHP_INT_MAX,
        );
    }

    public function testCleanStateIsEmpty(): void
    {
        self::assertSame([], $this->preflight()->check());
    }

    public function testDocker(): void
    {
        self::assertSame(['docker'], $this->preflight(dataDir: '/data')->check());
    }

    public function testDisabled(): void
    {
        self::assertSame(['disabled'], $this->preflight(['web_update' => false])->check());
        self::assertSame(['disabled'], $this->preflight([])->check());
    }

    public function testNotWritable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root obchází oprávnění');
        }
        chmod($this->root . '/manual', 0555);
        self::assertSame(['not_writable'], $this->preflight()->check());
    }

    public function testDiskSpace(): void
    {
        $low = static fn (string $dir): int => 100 * 1024 * 1024;
        self::assertSame(['disk_space'], $this->preflight(diskFree: $low)->check());
    }

    public function testNoBundle(): void
    {
        $this->bundle = false;
        self::assertSame(['no_bundle'], $this->preflight()->check());
    }

    public function testRunningWhenLockHeld(): void
    {
        $lock = $this->base . '/update.lock';
        $h = fopen($lock, 'c');
        self::assertTrue(flock($h, LOCK_EX));
        self::assertSame(['running'], $this->preflight(lock: $lock)->check());
        flock($h, LOCK_UN);
        fclose($h);
        self::assertSame([], $this->preflight(lock: $lock)->check());
    }

    public function testWarnings(): void
    {
        self::assertSame([], $this->preflight(ini: static fn (string $k) => '1')->warnings());

        mkdir($this->root . '/.git');
        $noValidate = static fn (string $k): string => $k === 'opcache.enable' ? '1' : '0';
        self::assertSame(['git_checkout', 'opcache_no_validate'], $this->preflight(ini: $noValidate)->warnings());
        $off = static fn (string $k): string => '0';
        self::assertSame(['git_checkout'], $this->preflight(ini: $off)->warnings());
    }
}
