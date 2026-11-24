<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Update;

use MyPortfolio\Service\Update\CommandLocator;
use PHPUnit\Framework\TestCase;

final class CommandLocatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cmdloc-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/path', 0775, true);
        mkdir($this->dir . '/fallback', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function bin(string $path): string
    {
        file_put_contents($path, "#!/bin/sh\n");
        chmod($path, 0755);
        return $path;
    }

    public function testFindsOnPathFirst(): void
    {
        $onPath = $this->bin($this->dir . '/path/tool');
        $this->bin($this->dir . '/fallback/tool');
        $locator = new CommandLocator($this->dir . '/path', [$this->dir . '/fallback']);

        self::assertSame($onPath, $locator->resolve('tool'));
    }

    public function testFallsBackToSystemDirsWhenPathIsEmpty(): void
    {
        $fallback = $this->bin($this->dir . '/fallback/tool');
        $locator = new CommandLocator('', [$this->dir . '/fallback']);

        self::assertSame($fallback, $locator->resolve('tool'));
    }

    public function testMissingOrNotExecutableIsNull(): void
    {
        file_put_contents($this->dir . '/fallback/plain', 'x');
        $locator = new CommandLocator('', [$this->dir . '/fallback']);

        self::assertNull($locator->resolve('tool'));
        self::assertNull($locator->resolve('plain'));
    }

    public function testConfiguredPathWins(): void
    {
        $configured = $this->bin($this->dir . '/path/custom');
        $locator = new CommandLocator('', [$this->dir . '/fallback']);

        self::assertSame($configured, $locator->resolve('tool', $configured));
        self::assertNull($locator->resolve('tool', $this->dir . '/path/missing'));
    }

    public function testUnderOpenBasedirAsksShell(): void
    {
        $api = dirname(__DIR__, 4);
        $code = 'require ' . var_export($api . '/vendor/autoload.php', true) . ';'
            . 'echo (new MyPortfolio\\Service\\Update\\CommandLocator("", []))->resolve("sh") ?? "null";';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d open_basedir=' . escapeshellarg($api)
            . ' -r ' . escapeshellarg($code));

        self::assertIsString($out);
        self::assertStringEndsWith('/sh', trim($out));
    }
}
