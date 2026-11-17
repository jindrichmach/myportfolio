<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Update;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Update\CommandLocator;
use MyPortfolio\Service\Update\WebUpdatePreflight;
use PHPUnit\Framework\TestCase;

final class WebUpdatePreflightTest extends TestCase
{
    private string $root;
    private string $bin;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/mp-preflight-' . bin2hex(random_bytes(4));
        $this->root = $base . '/app';
        $this->bin = $base . '/bin';
        foreach (['/app/.git', '/app/api/vendor', '/app/web/node_modules', '/app/web/dist', '/bin'] as $d) {
            mkdir($base . $d, 0777, true);
        }
        foreach (['git', 'composer', 'pnpm'] as $b) {
            file_put_contents($this->bin . '/' . $b, "#!/bin/sh\n");
            chmod($this->bin . '/' . $b, 0755);
        }
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwx ' . escapeshellarg(dirname($this->root)) . ' 2>/dev/null; rm -rf ' . escapeshellarg(dirname($this->root)));
    }

    /** @param array<string,mixed> $update */
    private function preflight(array $update = ['web_update' => true], ?string $dataDir = null, string $gitOut = '', ?string $path = null, ?string $lock = null): WebUpdatePreflight
    {
        return new WebUpdatePreflight(
            new Config(['update' => $update], $dataDir),
            $this->root,
            new CommandLocator($path ?? $this->bin),
            fn(string $cmd, string $cwd): array => [0, $gitOut],
            $lock ?? dirname($this->root) . '/update.lock',
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

    public function testNotGit(): void
    {
        rmdir($this->root . '/.git');
        self::assertSame(['not_git'], $this->preflight()->check());
    }

    public function testDirtyTree(): void
    {
        self::assertSame(['dirty_tree'], $this->preflight(gitOut: " M foo.php\n")->check());
    }

    public function testNotWritable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root obchází oprávnění');
        }
        chmod($this->root . '/web/dist', 0555);
        self::assertSame(['not_writable'], $this->preflight()->check());
    }

    public function testMissingBinaries(): void
    {
        unlink($this->bin . '/git');
        self::assertSame(['no_git'], $this->preflight()->check());
        unlink($this->bin . '/composer');
        self::assertSame(['no_git', 'no_composer'], $this->preflight()->check());
        unlink($this->bin . '/pnpm');
        self::assertSame(['no_git', 'no_composer', 'no_pnpm'], $this->preflight()->check());
    }

    public function testConfiguredBinaryOverridesPath(): void
    {
        $custom = dirname($this->root) . '/custom-pnpm';
        file_put_contents($custom, "#!/bin/sh\n");
        chmod($custom, 0755);
        unlink($this->bin . '/pnpm');
        self::assertSame([], $this->preflight(['web_update' => true, 'pnpm_bin' => $custom])->check());
        self::assertSame(['no_pnpm'], $this->preflight(['web_update' => true, 'pnpm_bin' => '/nonexistent/pnpm'])->check());
    }

    public function testRunningWhenLockHeld(): void
    {
        $lock = dirname($this->root) . '/update.lock';
        $h = fopen($lock, 'c');
        self::assertTrue(flock($h, LOCK_EX));
        self::assertSame(['running'], $this->preflight(lock: $lock)->check());
        flock($h, LOCK_UN);
        fclose($h);
        self::assertSame([], $this->preflight(lock: $lock)->check());
    }
}
