<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Cron;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Cron\BackupEncryption;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Volitelné AES-256 šifrování ZIP záloh (cfg cron.backup.password).
 * Roundtrip testy běží proti reálnému ZipArchive — zároveň ověřují,
 * že platformní libzip AES podporuje (hard requirement, když je heslo set).
 */
final class BackupEncryptionTest extends TestCase
{
    private string $zipFile;

    protected function setUp(): void
    {
        $this->zipFile = tempnam(sys_get_temp_dir(), 'myinv-zip-') . '.zip';
    }

    protected function tearDown(): void
    {
        @unlink($this->zipFile);
    }

    public function testPasswordFromConfig(): void
    {
        $cfg = new Config(['cron' => ['backup' => ['password' => 'tajné heslo']]]);
        self::assertSame('tajné heslo', BackupEncryption::passwordFromConfig($cfg));

        self::assertSame('', BackupEncryption::passwordFromConfig(new Config([])));
    }

    public function testUnsupportedReasonIsNullWithoutPassword(): void
    {
        self::assertNull(BackupEncryption::unsupportedReason(''));
    }

    public function testUnsupportedReasonIsNullOnAesCapablePlatform(): void
    {
        // Na CI/dev s moderním libzip je AES dostupné — guard nesmí blokovat.
        if (!defined(ZipArchive::class . '::EM_AES_256')) {
            self::markTestSkipped('libzip bez AES — guard by tu správně vrátil hlášku.');
        }
        self::assertNull(BackupEncryption::unsupportedReason('heslo'));
    }

    public function testEncryptedEntryIsUnreadableWithoutPasswordAndReadableWithIt(): void
    {
        if (!defined(ZipArchive::class . '::EM_AES_256')) {
            self::markTestSkipped('libzip bez AES.');
        }

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('dump.sql', 'CREATE TABLE secret (id INT);');
        self::assertTrue(BackupEncryption::encryptEntry($zip, 'dump.sql', 'správné-heslo'));
        self::assertTrue($zip->close());

        // Bez hesla: entry je vidět (názvy se nešifrují), obsah nejde přečíst.
        $ro = new ZipArchive();
        self::assertTrue($ro->open($this->zipFile));
        self::assertSame(0, $ro->locateName('dump.sql'));
        self::assertFalse($ro->getFromName('dump.sql'));
        $ro->close();

        // Se správným heslem se obsah přečte beze ztráty.
        $ro = new ZipArchive();
        self::assertTrue($ro->open($this->zipFile));
        $ro->setPassword('správné-heslo');
        self::assertSame('CREATE TABLE secret (id INT);', $ro->getFromName('dump.sql'));
        $ro->close();
    }

    public function testEmptyPasswordIsNoOpAndContentStaysPlain(): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('dump.sql', 'SELECT 1;');
        self::assertTrue(BackupEncryption::encryptEntry($zip, 'dump.sql', ''));
        self::assertTrue($zip->close());

        $ro = new ZipArchive();
        self::assertTrue($ro->open($this->zipFile));
        self::assertSame('SELECT 1;', $ro->getFromName('dump.sql'));
        $ro->close();
    }
}
