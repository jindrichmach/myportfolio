<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Config;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Config\CfgLocalWriter;
use PHPUnit\Framework\TestCase;

/**
 * Pokrývá zápis a merge cfg.local.php (auth.require_totp + obecná dot-notation).
 * Ověřuje že Config::load() po zápisu skutečně override aplikuje.
 */
final class CfgLocalWriterTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/myportfolio-cfglocal-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0700, true);
        // Minimální cfg.php — Config::load to vyžaduje
        file_put_contents($this->tmpRoot . '/cfg.php', "<?php return ['auth' => ['require_totp' => false]];");
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpRoot . '/cfg.local.php');
        @unlink($this->tmpRoot . '/cfg.php');
        @rmdir($this->tmpRoot);
    }

    public function testWritesFreshCfgLocalWithDotNotation(): void
    {
        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);

        $path = $this->tmpRoot . '/cfg.local.php';
        self::assertFileExists($path);

        $loaded = require $path;
        self::assertSame(['auth' => ['require_totp' => true]], $loaded);
    }

    public function testMergesIntoExistingCfgLocal(): void
    {
        file_put_contents(
            $this->tmpRoot . '/cfg.local.php',
            "<?php return ['app' => ['debug' => true], 'auth' => ['require_totp' => false]];",
        );

        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);

        $loaded = require $this->tmpRoot . '/cfg.local.php';
        self::assertTrue($loaded['app']['debug'], 'Existující klíče nesmí být ztraceny');
        self::assertTrue($loaded['auth']['require_totp']);
    }

    public function testSupportsDeepDotPaths(): void
    {
        CfgLocalWriter::setKeys($this->tmpRoot, ['smtp.dkim.enabled' => true]);

        $loaded = require $this->tmpRoot . '/cfg.local.php';
        self::assertTrue($loaded['smtp']['dkim']['enabled']);
    }

    public function testConfigLoadAppliesCfgLocalOverride(): void
    {
        // cfg.php má require_totp = false; cfg.local.php přepíše na true
        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);

        $config = Config::load($this->tmpRoot);
        self::assertTrue($config->get('auth.require_totp'));
    }

    public function testThrowsWhenExistingCfgLocalDoesNotReturnArray(): void
    {
        file_put_contents($this->tmpRoot . '/cfg.local.php', "<?php return 'not-an-array';");

        $this->expectException(\RuntimeException::class);
        CfgLocalWriter::setKeys($this->tmpRoot, ['auth.require_totp' => true]);
    }

    public function testResolveTargetDirFallsBackToRootWhenDataDirUnset(): void
    {
        putenv('MYPORTFOLIO_DATA_DIR');  // ensure unset
        self::assertSame($this->tmpRoot, CfgLocalWriter::resolveTargetDir($this->tmpRoot));
    }

    public function testResolveTargetDirPrefersDataDirWhenSet(): void
    {
        $dataDir = sys_get_temp_dir() . '/myportfolio-cfglocal-datadir-' . bin2hex(random_bytes(4));
        mkdir($dataDir, 0700, true);
        putenv('MYPORTFOLIO_DATA_DIR=' . $dataDir);
        try {
            self::assertSame($dataDir, CfgLocalWriter::resolveTargetDir($this->tmpRoot));
        } finally {
            putenv('MYPORTFOLIO_DATA_DIR');
            @rmdir($dataDir);
        }
    }
}
