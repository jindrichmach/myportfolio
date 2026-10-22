<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Catalog;

use MyPortfolio\Service\Catalog\IconStore;
use MyPortfolio\Service\Portfolio\DomainError;
use PHPUnit\Framework\TestCase;

final class IconStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/myportfolio-icons-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    /** Syntetický obrázek: průhledné pozadí, plný čtverec uprostřed. */
    public static function image(int $w, int $h, string $format = 'png'): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, (int) ($w / 4), (int) ($h / 4), (int) ($w * 3 / 4), (int) ($h * 3 / 4), imagecolorallocate($img, 200, 30, 30));
        ob_start();
        $format === 'jpeg' ? imagejpeg($img) : imagepng($img);
        return (string) ob_get_clean();
    }

    public function testStoresDownscaledPngWithTransparency(): void
    {
        $store = new IconStore($this->dir);
        $file = $store->store(self::image(400, 200));

        self::assertMatchesRegularExpression(IconStore::FILE_PATTERN, $file);
        [$w, $h, $type] = getimagesize($store->path($file)) ?: [0, 0, 0];
        self::assertSame([128, 64, IMAGETYPE_PNG], [$w, $h, $type]);
        $img = imagecreatefrompng($store->path($file));
        self::assertSame(127, (imagecolorat($img, 0, 0) >> 24) & 0x7F); // roh zůstal průhledný
    }

    public function testSmallImageIsNotUpscaledAndSameImageSharesFile(): void
    {
        $store = new IconStore($this->dir);
        $a = $store->store(self::image(40, 40, 'jpeg'));
        $b = $store->store(self::image(40, 40, 'jpeg'));

        self::assertSame($a, $b);
        self::assertSame(40, getimagesize($store->path($a))[0] ?? 0);
        self::assertCount(1, glob($this->dir . '/*') ?: []);
    }

    public function testRejectsSvgGarbageAndHugeImages(): void
    {
        $store = new IconStore($this->dir);
        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
            'not an image',
            substr(self::image(50, 50), 0, 40), // useknutý PNG
            self::image(5000, 10),
        ] as $bytes) {
            try {
                $store->store($bytes);
                self::fail('Očekávána chyba validace.');
            } catch (DomainError $e) {
                self::assertSame('validation_failed', $e->errorCode);
            }
        }
        self::assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testRejectsInvalidFileName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new IconStore($this->dir))->path('../cfg.php');
    }
}
