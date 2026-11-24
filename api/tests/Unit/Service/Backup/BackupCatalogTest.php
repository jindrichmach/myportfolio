<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Backup;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Backup\BackupCatalog;
use MyPortfolio\Service\Backup\BackupDirectory;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class BackupCatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/myp-backup-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/backup', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir . '/backup', $this->dir] as $d) {
            foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                is_file($f) && unlink($f);
            }
            @rmdir($d);
        }
    }

    private function zip(string $name, bool $encrypted): void
    {
        $zip = new ZipArchive();
        $zip->open($this->dir . '/backup/' . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('db.sql', str_repeat('-- dump ', 20));
        if ($encrypted) {
            $zip->setEncryptionName('db.sql', ZipArchive::EM_AES_256, 'secret');
        }
        $zip->close();
    }

    public function testListFiltersAndSorts(): void
    {
        $this->zip('db-2026-01-05.zip', false);
        $this->zip('db-2026-02-01_03-30.zip', true);
        $this->zip('db-2026-02-01_02-00.zip', false);
        $this->zip('db-pdf-2026-03-01.zip', false);
        $this->zip('.restore-abc.zip', false);
        file_put_contents($this->dir . '/backup/db-2026-01-01.sql.gz', 'x');
        file_put_contents($this->dir . '/backup/notes.txt', 'x');

        $list = (new BackupCatalog($this->dir . '/backup'))->list();

        self::assertSame(
            ['db-2026-02-01_03-30.zip', 'db-2026-02-01_02-00.zip', 'db-2026-01-05.zip'],
            array_column($list, 'name'),
        );
        self::assertSame([true, false, false], array_column($list, 'encrypted'));
        self::assertSame('2026-02-01 03:30:00', $list[0]['created_at']);
        self::assertSame('2026-01-05 00:00:00', $list[2]['created_at']);
        self::assertGreaterThan(0, $list[0]['size']);
    }

    public function testFind(): void
    {
        $this->zip('db-2026-01-05.zip', false);
        file_put_contents($this->dir . '/db-2026-01-06.zip', 'x');
        $catalog = new BackupCatalog($this->dir . '/backup');

        self::assertSame($this->dir . '/backup/db-2026-01-05.zip', $catalog->find('db-2026-01-05.zip'));
        self::assertNull($catalog->find('db-2026-01-09.zip'));
        self::assertNull($catalog->find('../db-2026-01-06.zip'));
        self::assertNull($catalog->find($this->dir . '/backup/db-2026-01-05.zip'));
        self::assertNull($catalog->find('db-2026-01-05.sql.gz'));
        self::assertNull($catalog->find('db-pdf-2026-01-05.zip'));
    }

    public function testDirectoryOrder(): void
    {
        self::assertSame('/a', BackupDirectory::resolve(new Config(['cron' => ['backup' => ['output_dir' => '/a']], 'storage' => ['backup_dir' => '/b']])));
        self::assertSame('/b', BackupDirectory::resolve(new Config(['storage' => ['backup_dir' => '/b']])));

        $orig = getenv('MYPORTFOLIO_DATA_DIR');
        putenv('MYPORTFOLIO_DATA_DIR=/data');
        try {
            self::assertSame('/data/storage/backup', BackupDirectory::resolve(new Config([])));
        } finally {
            $orig === false ? putenv('MYPORTFOLIO_DATA_DIR') : putenv('MYPORTFOLIO_DATA_DIR=' . $orig);
        }
    }
}
