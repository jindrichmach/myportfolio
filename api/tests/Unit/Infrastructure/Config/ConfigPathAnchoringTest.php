<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Infrastructure\Config;

use MyPortfolio\Infrastructure\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Pokrývá ukotvení relativních cest z cfg.php k rootu aplikace.
 *
 * Motivace: cron-backup.php bral `cron.backup.output_dir` doslovně; relativní
 * hodnota 'storage/backup' (bývalý cfg.sample.php default) se resolvovala
 * proti CWD procesu — pod Task Scheduler/cron je jinde než root repa, takže
 * zálohy končily mimo aplikaci. Platí pro všechny path klíče (PATH_KEYS).
 */
final class ConfigPathAnchoringTest extends TestCase
{
    private string $tmpRoot;

    /** @var array<string,string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/myportfolio-cfg-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0700, true);

        foreach (['MYPORTFOLIO_DATA_DIR'] as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        @unlink($this->tmpRoot . '/cfg.php');
        @rmdir($this->tmpRoot);
    }

    public function testRelativePathsAreAnchoredToRootDir(): void
    {
        $this->writeBaseCfg([
            'cron'    => ['backup' => ['output_dir' => 'storage/backup']],
            'storage' => ['backup_dir' => 'storage/backup'],
            'logging' => ['path' => 'log/app.log'],
        ]);

        $config = Config::load($this->tmpRoot);
        $sep    = DIRECTORY_SEPARATOR;

        self::assertSame($this->tmpRoot . $sep . 'storage/backup', $config->get('cron.backup.output_dir'));
        self::assertSame($this->tmpRoot . $sep . 'storage/backup', $config->get('storage.backup_dir'));
        self::assertSame($this->tmpRoot . $sep . 'log/app.log',    $config->get('logging.path'));
    }

    public function testAbsolutePathsAreUntouched(): void
    {
        $this->writeBaseCfg([
            'cron'    => ['backup' => ['output_dir' => '/var/backups/myportfolio']],
            'storage' => ['backup_dir' => 'C:\\backups\\myportfolio'],
            'logging' => ['path' => 'D:/logs/app.log'],
        ]);

        $config = Config::load($this->tmpRoot);

        self::assertSame('/var/backups/myportfolio',   $config->get('cron.backup.output_dir'));
        self::assertSame('C:\\backups\\myportfolio',   $config->get('storage.backup_dir'));
        self::assertSame('D:/logs/app.log',          $config->get('logging.path'));
    }

    public function testEmptyAndMissingValuesStayUntouched(): void
    {
        // Prázdný string = fallback chain (cron-backup output dir) — nesmí se
        // z něj stát cesta na root repa.
        $this->writeBaseCfg([
            'cron' => ['backup' => ['output_dir' => '']],
        ]);

        $config = Config::load($this->tmpRoot);

        self::assertSame('', $config->get('cron.backup.output_dir'));
        self::assertNull($config->get('storage.backup_dir'));
    }

    public function testDataDirOverrideWinsOverRelativeCfgValue(): void
    {
        $dataDir = sys_get_temp_dir() . '/myportfolio-data-' . bin2hex(random_bytes(6));
        mkdir($dataDir, 0700, true);
        putenv('MYPORTFOLIO_DATA_DIR=' . $dataDir);

        try {
            $this->writeBaseCfg([
                'cron' => ['backup' => ['output_dir' => 'storage/backup']],
            ]);

            $config = Config::load($this->tmpRoot);
            $sep    = DIRECTORY_SEPARATOR;

            self::assertSame($dataDir . $sep . 'storage' . $sep . 'backup', $config->get('cron.backup.output_dir'));
        } finally {
            @rmdir($dataDir);
        }
    }

    /** @param array<string,mixed> $arr */
    private function writeBaseCfg(array $arr): void
    {
        $exported = var_export($arr, true);
        file_put_contents($this->tmpRoot . '/cfg.php', "<?php return {$exported};\n");
    }
}
