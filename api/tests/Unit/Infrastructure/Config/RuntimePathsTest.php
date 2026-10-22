<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Infrastructure\Config;

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Config\RuntimePaths;
use PHPUnit\Framework\TestCase;

/**
 * RuntimePaths (issue #53) — kořen runtime cest respektuje MYPORTFOLIO_DATA_DIR,
 * jinak fallback na root repa. Layout musí odpovídat Config::applyDataDirOverrides.
 */
final class RuntimePathsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MYPORTFOLIO_DATA_DIR'); // reset
    }

    public function testFallsBackToRootDirWhenDataDirUnset(): void
    {
        putenv('MYPORTFOLIO_DATA_DIR');
        $root = Bootstrap::rootDir();

        self::assertSame($root, RuntimePaths::base());
        self::assertSame($root . '/storage', RuntimePaths::storage());
        self::assertSame($root . '/storage/uploads', RuntimePaths::storage('uploads'));
        self::assertSame($root . '/storage/supplier-logos', RuntimePaths::storage('supplier-logos'));
        self::assertSame($root . '/storage/cache/mpdf', RuntimePaths::storage('cache/mpdf'));
        self::assertSame($root . '/log', RuntimePaths::log());
        self::assertSame($root . '/log/cron', RuntimePaths::log('cron'));
    }

    public function testHonorsDataDir(): void
    {
        putenv('MYPORTFOLIO_DATA_DIR=/srv/myportfolio-data');

        self::assertSame('/srv/myportfolio-data', RuntimePaths::base());
        self::assertSame('/srv/myportfolio-data/storage', RuntimePaths::storage());
        self::assertSame('/srv/myportfolio-data/storage/uploads', RuntimePaths::storage('uploads'));
        self::assertSame('/srv/myportfolio-data/storage/backup', RuntimePaths::storage('backup'));
        self::assertSame('/srv/myportfolio-data/log/cron', RuntimePaths::log('cron'));
    }

    public function testNormalizesTrailingAndLeadingSeparators(): void
    {
        putenv('MYPORTFOLIO_DATA_DIR=/data/');
        self::assertSame('/data', RuntimePaths::base());
        self::assertSame('/data/storage/x', RuntimePaths::storage('/x'));
        self::assertSame('/data/log/y', RuntimePaths::log('/y'));
    }
}
