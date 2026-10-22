<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Catalog;

use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Service\Portfolio\DomainError;

/**
 * Ikony katalogu jako PNG soubory v storage/uploads/icons. Každý upload se přes GD dekóduje
 * a znovu zakóduje (max. 128×128, průhlednost zůstane) — do úložiště nikdy nejde původní soubor.
 * Název = hash obsahu, takže stejný obrázek sdílí jeden soubor a URL lze cachovat napořád.
 */
final class IconStore
{
    public const MAX_BYTES = 1_048_576;
    public const SIZE = 128;
    private const MAX_SOURCE_PX = 4096;
    private const ALLOWED = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    public const FILE_PATTERN = '/^[0-9a-f]{32}\.png$/';

    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? RuntimePaths::storage('uploads/icons');
    }

    /** @return string název uloženého souboru */
    public function store(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw DomainError::validation(['icon' => ['Obrázek musí mít nejvýše 1 MB.']]);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], self::ALLOWED, true)) {
            throw DomainError::validation(['icon' => ['Povolené formáty: PNG, JPEG, WebP, GIF.']]);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_SOURCE_PX || $info[1] > self::MAX_SOURCE_PX) {
            throw DomainError::validation(['icon' => ['Obrázek může mít nejvýše 4096 × 4096 px.']]);
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw DomainError::validation(['icon' => ['Obrázek se nepodařilo načíst.']]);
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::SIZE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        ob_start();
        imagepng($dst, null, 9);
        $png = (string) ob_get_clean();

        $file = substr(hash('sha256', $png), 0, 32) . '.png';
        $path = $this->path($file);
        if (!is_file($path)) {
            if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
                throw new \RuntimeException("Nelze vytvořit adresář ikon: {$this->dir}");
            }
            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $png) === false || !rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException("Nelze uložit ikonu: $path");
            }
        }
        return $file;
    }

    /** Smaže soubor ikony (volající ověří, že ho už nic neodkazuje). */
    public function delete(string $file): void
    {
        if (preg_match(self::FILE_PATTERN, $file) === 1) {
            @unlink($this->path($file));
        }
    }

    public function path(string $file): string
    {
        if (preg_match(self::FILE_PATTERN, $file) !== 1) {
            throw new \InvalidArgumentException('Neplatný název ikony.');
        }
        return $this->dir . '/' . $file;
    }

    public function dir(): string
    {
        return $this->dir;
    }
}
