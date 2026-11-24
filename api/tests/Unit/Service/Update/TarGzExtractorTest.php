<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Update;

use MyPortfolio\Service\Update\TarGzExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TarGzExtractorTest extends TestCase
{
    private const PREFIX = 'myportfolio-1.0.0/';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/mp-tar-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    public function testExtractsValidArchiveAndKeepsExecutableBit(): void
    {
        $archive = $this->archive([
            $this->entry(self::PREFIX, '', '5', 0755),
            $this->entry(self::PREFIX . 'VERSION', "1.0.0\n"),
            $this->entry(self::PREFIX . 'cmd/run.sh', "#!/bin/sh\n", '0', 0755),
        ]);

        $files = (new TarGzExtractor())->extract($archive, $this->tmp . '/out', self::PREFIX);

        self::assertSame([self::PREFIX . 'VERSION', self::PREFIX . 'cmd/run.sh'], $files);
        self::assertSame("1.0.0\n", file_get_contents($this->tmp . '/out/' . self::PREFIX . 'VERSION'));
        self::assertSame(0644, fileperms($this->tmp . '/out/' . self::PREFIX . 'VERSION') & 0777);
        self::assertSame(0755, fileperms($this->tmp . '/out/' . self::PREFIX . 'cmd/run.sh') & 0777);
    }

    public function testExtractsUstarPrefixSplitAndPaxLongPath(): void
    {
        $long = self::PREFIX . 'api/' . str_repeat('d', 60) . '/' . str_repeat('e', 60) . '/file.php';
        $pax = strlen(' path=' . $long . "\n");
        $pax = ($pax + strlen((string) $pax)) . ' path=' . $long . "\n";
        $archive = $this->archive([
            $this->entry('PaxHeader', $pax, 'x'),
            $this->entry('short', 'pax'),
            $this->entry(self::PREFIX . 'a.txt', 'a'),
        ]);

        $files = (new TarGzExtractor())->extract($archive, $this->tmp . '/out', self::PREFIX);

        self::assertContains($long, $files);
        self::assertSame('pax', file_get_contents($this->tmp . '/out/' . $long));
    }

    public function testExtractsUstarSplitName(): void
    {
        $dir = self::PREFIX . str_repeat('x', 80);
        $archive = $this->archive([$this->entry('f.txt', 'u', '0', 0644, $dir)]);

        $files = (new TarGzExtractor())->extract($archive, $this->tmp . '/out', self::PREFIX);

        self::assertSame([$dir . '/f.txt'], $files);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rejectedProvider(): iterable
    {
        yield 'traversal' => [self::PREFIX . '../evil.txt', '0', 'traversal'];
        yield 'absolute' => ['/etc/evil.txt', '0', 'absolutní'];
        yield 'bad prefix' => ['other-1.0.0/a.txt', '0', 'mimo očekávaný'];
        yield 'symlink' => [self::PREFIX . 'link', '2', 'odkaz'];
        yield 'hardlink' => [self::PREFIX . 'link', '1', 'odkaz'];
        yield 'chardev' => [self::PREFIX . 'dev', '3', 'speciální'];
        yield 'fifo' => [self::PREFIX . 'fifo', '6', 'speciální'];
        yield 'sparse' => [self::PREFIX . 'sp', 'S', 'nepodporovaný'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedProvider')]
    public function testRejectsUnsafeEntries(string $name, string $type, string $message): void
    {
        $archive = $this->archive([
            $this->entry(self::PREFIX . 'ok.txt', 'ok'),
            $this->entry($name, '', $type),
        ]);
        $dest = $this->tmp . '/out';

        $caught = null;
        try {
            (new TarGzExtractor())->extract($archive, $dest, self::PREFIX);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Měla vzniknout výjimka extractoru.');
        self::assertStringContainsString($message, $caught->getMessage());
        self::assertFileDoesNotExist($dest . '/evil.txt');
        self::assertFileDoesNotExist($dest . '/other-1.0.0/a.txt');
    }

    public function testRejectsNulInGnuLongName(): void
    {
        $archive = $this->archive([
            $this->entry('././@LongLink', self::PREFIX . "a\0b\0", 'L'),
            $this->entry('short', 'x'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/poškozené jméno/');
        (new TarGzExtractor())->extract($archive, $this->tmp . '/out', self::PREFIX);
    }

    public function testRejectsTraversalInGnuLongName(): void
    {
        $archive = $this->archive([
            $this->entry('././@LongLink', self::PREFIX . "../evil.txt\0", 'L'),
            $this->entry('short', 'x'),
        ]);
        $dest = $this->tmp . '/out';

        try {
            (new TarGzExtractor())->extract($archive, $dest, self::PREFIX);
            $caught = null;
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertStringContainsString('traversal', $caught->getMessage());
        self::assertFileDoesNotExist($dest . '/evil.txt');
    }

    public function testExtractsGnuLongNameAndSkipsRootDirectory(): void
    {
        $long = self::PREFIX . 'api/' . str_repeat('d', 60) . '/' . str_repeat('e', 60) . '/file.php';
        $archive = $this->archive([
            $this->entry('./', '', '5', 0755),
            $this->entry(self::PREFIX, '', '5', 0755),
            $this->entry('././@LongLink', $long . "\0", 'L'),
            $this->entry('short', 'gnu'),
        ]);

        $files = (new TarGzExtractor())->extract($archive, $this->tmp . '/out', self::PREFIX);

        self::assertSame([$long], $files);
        self::assertSame('gnu', file_get_contents($this->tmp . '/out/' . $long));
    }

    public function testRejectsArchiveWithoutEndMarker(): void
    {
        $path = $this->tmp . '/trunc.tar.gz';
        file_put_contents($path, gzencode($this->entry(self::PREFIX . 'a.txt', 'a')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/neúplný/');
        (new TarGzExtractor())->extract($path, $this->tmp . '/out', self::PREFIX);
    }

    public function testRejectsTruncatedMetadataData(): void
    {
        $entry = $this->entry('././@LongLink', str_repeat('a', 300), 'L');
        $path = $this->tmp . '/trunc.tar.gz';
        file_put_contents($path, gzencode(substr($entry, 0, 600)));

        $this->expectException(RuntimeException::class);
        (new TarGzExtractor())->extract($path, $this->tmp . '/out', self::PREFIX);
    }

    public function testRejectsCorruptChecksum(): void
    {
        $tar = $this->entry(self::PREFIX . 'a.txt', 'a');
        $tar = substr_replace($tar, '0000001', 148, 7);
        $archive = $this->tmp . '/bad.tar.gz';
        file_put_contents($archive, gzencode($tar . str_repeat("\0", 1024)));

        $this->expectException(RuntimeException::class);
        (new TarGzExtractor())->extract($archive, $this->tmp . '/out', self::PREFIX);
    }

    /** @param list<string> $entries */
    private function archive(array $entries): string
    {
        $path = $this->tmp . '/a.tar.gz';
        file_put_contents($path, gzencode(implode('', $entries) . str_repeat("\0", 1024)));

        return $path;
    }

    private function entry(string $name, string $data, string $type = '0', int $mode = 0644, string $prefix = ''): string
    {
        $h = str_pad($name, 100, "\0")
            . sprintf('%07o', $mode) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', strlen($data)) . "\0"
            . sprintf('%011o', 0) . "\0"
            . '        '
            . $type
            . str_repeat("\0", 100)
            . "ustar\0" . '00'
            . str_repeat("\0", 32 + 32 + 8 + 8)
            . str_pad($prefix, 155, "\0");
        $h = str_pad($h, 512, "\0");
        $sum = array_sum(array_map('ord', str_split($h)));
        $h = substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);

        return $h . str_pad($data, (int) (ceil(strlen($data) / 512) * 512), "\0");
    }
}
