<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Update;

use MyPortfolio\Service\Update\BundleUpdater;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BundleSwapTest extends TestCase
{
    private string $tmp;
    private string $root;
    private string $stage;
    private string $backup;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/myportfolio-swap-' . bin2hex(random_bytes(6));
        $this->root = $this->tmp . '/root';
        $this->stage = $this->tmp . '/stage';
        $this->backup = $this->tmp . '/backup';
        foreach ([$this->root, $this->stage, $this->backup] as $d) {
            mkdir($d, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    public function testSwapReplacesAddsAndSkipsIdentical(): void
    {
        $this->put($this->root, 'api/src/A.php', 'stara');
        $this->put($this->stage, 'api/src/A.php', 'nova');
        $this->put($this->root, 'api/vendor/same.php', 'totozne');
        $this->put($this->stage, 'api/vendor/same.php', 'totozne');
        $this->put($this->stage, 'api/src/deep/New.php', 'novy');

        $before = self::countFiles($this->tmp);
        $deployed = (new BundleUpdater($this->root))->swap($this->stage, $this->backup);

        self::assertSame(2, $deployed);
        self::assertSame('nova', file_get_contents($this->root . '/api/src/A.php'));
        self::assertSame('stara', file_get_contents($this->backup . '/api/src/A.php'));
        self::assertSame('novy', file_get_contents($this->root . '/api/src/deep/New.php'));
        self::assertFileDoesNotExist($this->backup . '/api/vendor/same.php');
        self::assertFileDoesNotExist($this->stage . '/api/src/A.php');
        self::assertLessThanOrEqual($before, self::countFiles($this->tmp));
    }

    public function testProtectedPathsAndVersionUntouched(): void
    {
        foreach (['cfg.php', 'cfg.local.php', 'cfg.docker.php', '.env', 'storage/data.txt', 'log/x.log', 'private/p.md', '.git/HEAD'] as $p) {
            $this->put($this->root, $p, 'MOJE');
            $this->put($this->stage, $p, 'BALICEK');
        }
        $this->put($this->stage, 'storage/jen-ve-stage.txt', 'x');
        $this->put($this->root, 'VERSION', '1.0.0');
        $this->put($this->stage, 'VERSION', '1.1.0');

        (new BundleUpdater($this->root))->swap($this->stage, $this->backup);

        foreach (['cfg.php', 'cfg.local.php', 'cfg.docker.php', '.env', 'storage/data.txt', 'log/x.log', 'private/p.md', '.git/HEAD'] as $p) {
            self::assertSame('MOJE', file_get_contents($this->root . '/' . $p), $p);
        }
        self::assertFileDoesNotExist($this->root . '/storage/jen-ve-stage.txt');
        self::assertSame('1.0.0', file_get_contents($this->root . '/VERSION'));
    }

    public function testPrunesWebDistFilesMissingInStage(): void
    {
        $this->put($this->root, 'web/dist/index.html', 'old');
        $this->put($this->root, 'web/dist/assets/old-abc.js', 'old-asset');
        $this->put($this->root, 'web/dist/assets/keep.js', 'keep');
        $this->put($this->stage, 'web/dist/index.html', 'new');
        $this->put($this->stage, 'web/dist/assets/keep.js', 'keep');

        (new BundleUpdater($this->root))->swap($this->stage, $this->backup);

        self::assertSame('new', file_get_contents($this->root . '/web/dist/index.html'));
        self::assertFileExists($this->root . '/web/dist/assets/keep.js');
        self::assertFileDoesNotExist($this->root . '/web/dist/assets/old-abc.js');
        self::assertSame('old-asset', file_get_contents($this->backup . '/web/dist/assets/old-abc.js'));
    }

    public function testNoPruneWhenStageHasNoDist(): void
    {
        $this->put($this->root, 'web/dist/index.html', 'old');
        $this->put($this->stage, 'api/x.php', 'x');

        (new BundleUpdater($this->root))->swap($this->stage, $this->backup);

        self::assertFileExists($this->root . '/web/dist/index.html');
    }

    public function testFailureMidSwapRollsBackToOriginalState(): void
    {
        $this->put($this->root, 'api/src/A.php', 'a-stara');
        $this->put($this->root, 'api/src/B.php', 'b-stara');
        $this->put($this->root, 'web/dist/old.js', 'old');
        $this->put($this->root, 'cfg.local.php', 'CFG');
        $this->put($this->stage, 'api/src/A.php', 'a-nova');
        $this->put($this->stage, 'api/src/B.php', 'b-nova');
        $this->put($this->stage, 'api/src/C.php', 'c-novy');
        $this->put($this->stage, 'web/dist/index.html', 'idx');
        $snapshot = self::snapshot($this->root);

        // spadne až při pruningu web/dist (po nasazení všeho ostatního)
        $updater = new BundleUpdater($this->root, static function (string $rel): void {
            if ($rel === 'web/dist/old.js') {
                throw new RuntimeException('simulovaná chyba');
            }
        });
        try {
            $updater->swap($this->stage, $this->backup);
            self::fail('swap měl selhat');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('simulovaná chyba', $e->getMessage());
        }

        self::assertSame($snapshot, self::snapshot($this->root));
    }

    public function testFailureAfterPartialDeployRestoresReplacedAndRemovesAdded(): void
    {
        $this->put($this->root, 'a.php', 'a-stara');
        $this->put($this->root, 'z.php', 'z-stara');
        $this->put($this->stage, 'a.php', 'a-nova');
        $this->put($this->stage, 'b.php', 'b-novy');
        $this->put($this->stage, 'z.php', 'z-nova');
        $snapshot = self::snapshot($this->root);

        // Pořadí souborů závisí na souborovém systému; selže vždy až druhé nasazení.
        $calls = 0;
        $updater = new BundleUpdater($this->root, static function (string $rel) use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('boom');
            }
        });
        try {
            $updater->swap($this->stage, $this->backup);
            self::fail('swap měl selhat');
        } catch (RuntimeException) {
        }

        self::assertSame(2, $calls);
        self::assertSame($snapshot, self::snapshot($this->root));
    }

    public function testFailureAfterBackupBeforeMoveRestoresOriginal(): void
    {
        $this->put($this->root, 'a.php', 'a-stara');
        $this->put($this->stage, 'a.php', 'a-nova');
        $snapshot = self::snapshot($this->root);

        // hák běží po záloze: původní soubor je už v záloze a cíl chybí
        $updater = new BundleUpdater($this->root, function (string $rel): void {
            self::assertFileDoesNotExist($this->root . '/' . $rel);
            self::assertSame('a-stara', file_get_contents($this->backup . '/' . $rel));
            throw new RuntimeException('boom');
        });
        try {
            $updater->swap($this->stage, $this->backup);
            self::fail('swap měl selhat');
        } catch (RuntimeException) {
        }

        self::assertSame($snapshot, self::snapshot($this->root));
    }

    public function testIncompleteRollbackReportsUnrestoredFiles(): void
    {
        $this->put($this->root, 'a.php', 'a-stara');
        $this->put($this->stage, 'a.php', 'a-nova');

        $updater = new BundleUpdater($this->root, function (string $rel): void {
            unlink($this->backup . '/' . $rel);
            throw new RuntimeException('boom');
        });
        try {
            $updater->swap($this->stage, $this->backup);
            self::fail('swap měl selhat');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('NEPODAŘILO SE vrátit', $e->getMessage());
            self::assertStringContainsString('a.php', $e->getMessage());
        }
    }

    public function testExecutableBitIsKeptOnReplaceAndSyncedOnIdenticalContent(): void
    {
        $this->put($this->root, 'run.sh', 'stary');
        $this->put($this->root, 'same.sh', 'totozne');
        $this->put($this->stage, 'run.sh', 'novy');
        $this->put($this->stage, 'same.sh', 'totozne');
        chmod($this->stage . '/run.sh', 0755);
        chmod($this->stage . '/same.sh', 0755);
        chmod($this->root . '/same.sh', 0644);

        (new BundleUpdater($this->root))->swap($this->stage, $this->backup);

        self::assertSame(0755, fileperms($this->root . '/run.sh') & 0777);
        self::assertSame('novy', file_get_contents($this->root . '/run.sh'));
        self::assertSame(0755, fileperms($this->root . '/same.sh') & 0777);
    }

    /** @return array<string,string> */
    private static function snapshot(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[substr($f->getPathname(), strlen($dir))] = (string) file_get_contents($f->getPathname());
            }
        }
        ksort($out);

        return $out;
    }

    private function put(string $base, string $rel, string $content): void
    {
        $path = $base . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private static function countFiles(string $dir): int
    {
        return count(self::snapshot($dir));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
