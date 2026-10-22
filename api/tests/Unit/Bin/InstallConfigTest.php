<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Bin;

use PHPUnit\Framework\TestCase;

/** api/bin/install-config.php — generování cfg.php z cfg.sample.php pro instalační skript. */
final class InstallConfigTest extends TestCase
{
    private string $out = '';

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/mp-install-cfg-' . bin2hex(random_bytes(4)) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
    }

    /** @return array{0:int,1:string} */
    private function generate(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/install-config.php')
            . ' --out=' . escapeshellarg($this->out) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $output, $code);
        return [$code, implode("\n", $output)];
    }

    public function testReplacesValuesAndKeepsTypes(): void
    {
        [$code] = $this->generate('--set', 'app.url=s:http://localhost:8080', '--set', 'db.port=3307', '--set', "db.pass=s:12'34",
            '--set', 'redis.enabled=true', '--set', 'auth.email_otp.trusted_cookie_name=myportfolio_td', '--set=app.env=development');
        self::assertSame(0, $code);
        $cfg = require $this->out;
        self::assertSame(['http://localhost:8080', 3307, "12'34", true, 'myportfolio_td', 'development'],
            [$cfg['app']['url'], $cfg['db']['port'], $cfg['db']['pass'], $cfg['redis']['enabled'], $cfg['auth']['email_otp']['trusted_cookie_name'], $cfg['app']['env']]);
        self::assertStringContainsString('// veřejná URL aplikace', (string) file_get_contents($this->out));
    }

    public function testUnknownKeyFails(): void
    {
        [$code, $output] = $this->generate('--set', 'app.nope=1');
        self::assertSame(1, $code);
        self::assertStringContainsString('app.nope', $output);
        self::assertFileDoesNotExist($this->out);
    }
}
