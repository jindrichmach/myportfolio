<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Update;

use RuntimeException;

/**
 * Streamovaná extrakce `.tar.gz` s tvrdou validací cest.
 *
 * Proč vlastní implementace a ne `PharData`: bundle vydání staví GNU tar
 * (výchozí formát) a cesty delší než 100 znaků ukládá do záznamů `L`
 * (`././@LongLink`); podporované jsou i ustar `prefix` + `name` a PAX `x`
 * s atributem `path`. Phar si s reálným bundlem neporadil
 * (`PharException: Cannot extract "."`) a nerozbalil nic. Navíc Phar část
 * záznamů skrývá před iterací (traversal cesty), takže se nad ním nedá
 * postavit důvěryhodná kontrola obsahu.
 *
 * Tenhle extraktor čte hlavičky sám, takže o každém záznamu ví a rozhoduje:
 *   - jméno musí ležet pod povoleným prefixem, bez `..`, bez absolutní cesty
 *   - symlinky, hardlinky, zařízení a FIFO se odmítají (mohly by mířit ven)
 *   - kontroluje se hlavičkový checksum, počet záznamů i celková velikost
 *
 * Nezachovává vlastníka ani časy; práva se zjednoduší na 0755 (měl spustitelný
 * bit) / 0644, aby zůstala spustitelnost `cmd/*.sh` a `docker-entrypoint.sh`.
 */
final class TarGzExtractor
{
    private const BLOCK = 512;

    /** Strop proti dekompresní bombě — bundle má ~200 MB rozbalených. */
    private const MAX_TOTAL_BYTES = 2 * 1024 * 1024 * 1024;
    private const MAX_ENTRIES     = 100_000;
    /** Strop pro data metadata záznamů (L/K/x/g), která se drží celá v paměti. */
    private const MAX_META_BYTES  = 1024 * 1024;

    /** @var list<string> relativní cesty souborů zapsaných při poslední extrakci */
    private array $files = [];

    /**
     * Rozbalí archiv do `$destDir`. Všechny cesty v archivu musí začínat
     * `$requiredPrefix` (např. `myportfolio-1.4.0/`).
     *
     * @return list<string> cesty rozbalených souborů relativně k `$destDir`
     */
    public function extract(string $archive, string $destDir, string $requiredPrefix): array
    {
        if (!is_file($archive)) {
            throw new RuntimeException('Archiv ' . $archive . ' neexistuje.');
        }
        $this->files = [];
        $requiredPrefix = rtrim($requiredPrefix, '/') . '/';
        $this->ensureDir($destDir);
        $destReal = realpath($destDir);
        if ($destReal === false) {
            throw new RuntimeException('Cílový adresář ' . $destDir . ' není dostupný.');
        }

        $fh = @gzopen($archive, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Archiv ' . $archive . ' nelze otevřít (gzopen).');
        }

        try {
            $this->readEntries($fh, $destReal, $requiredPrefix);
        } finally {
            gzclose($fh);
        }

        if ($this->files === []) {
            throw new RuntimeException('Archiv neobsahuje žádné soubory.');
        }

        return $this->files;
    }

    /** @param resource $fh */
    private function readEntries($fh, string $destDir, string $requiredPrefix): void
    {
        $total    = 0;
        $entries  = 0;
        $emptyRun = 0;
        $longName = null;

        while (true) {
            $header = $this->readBlock($fh);
            if ($header === null) {
                // Řádný konec archivu (dva nulové bloky) končí smyčku níže.
                throw new RuntimeException('Archiv je neúplný (chybí koncové nulové bloky).');
            }

            // Dva prázdné bloky = konec archivu; za nimi bývá jen padding.
            if (trim($header, "\0") === '') {
                if (++$emptyRun >= 2) {
                    break;
                }
                continue;
            }
            $emptyRun = 0;

            if (++$entries > self::MAX_ENTRIES) {
                throw new RuntimeException('Archiv má víc než ' . self::MAX_ENTRIES . ' záznamů.');
            }

            $this->assertChecksum($header);

            $name     = $this->field($header, 0, 100);
            $prefix   = $this->field($header, 345, 155);
            $size     = $this->octal($this->field($header, 124, 12));
            $mode     = $this->octal($this->field($header, 100, 8));
            $typeflag = substr($header, 156, 1);

            // ustar dělí dlouhé cesty na prefix + name; GNU navíc umí 'L' záznam.
            $path = $longName ?? ($prefix !== '' ? $prefix . '/' . $name : $name);
            $longName = null;

            if ($size < 0 || $size > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('Záznam ' . $path . ' hlásí nesmyslnou velikost.');
            }

            if (in_array($typeflag, ['L', 'K', 'x', 'g'], true) && $size > self::MAX_META_BYTES) {
                throw new RuntimeException('Metadata záznam ' . $path . ' je příliš velký.');
            }

            // GNU LongName — jméno pro NÁSLEDUJÍCÍ záznam je v datech tohoto.
            if ($typeflag === 'L') {
                $longName = rtrim($this->readData($fh, $size), "\0");
                continue;
            }
            // GNU LongLink a globální pax hlavičku nepotřebujeme; pax 'x' 'path' respektuj.
            if ($typeflag === 'K' || $typeflag === 'g') {
                $this->readData($fh, $size);
                continue;
            }
            if ($typeflag === 'x') {
                $paxPath = $this->paxPath($this->readData($fh, $size));
                if ($paxPath !== null) {
                    $longName = $paxPath;
                }
                continue;
            }

            // Odkazy a speciální soubory v bundlu nemají co dělat a mohly by
            // ukazovat mimo strom — archiv rovnou odmítneme.
            if ($typeflag === '1' || $typeflag === '2') {
                throw new RuntimeException('Archiv obsahuje odkaz (' . $path . ') — odmítnuto.');
            }
            if (in_array($typeflag, ['3', '4', '6'], true)) {
                throw new RuntimeException('Archiv obsahuje speciální soubor (' . $path . ') — odmítnuto.');
            }

            // GNU tar zabalený z `.` vloží jako první záznam kořen archivu (`./`).
            // Je to no-op adresář, ne obsah — přeskoč ho ještě před prefix kontrolou.
            $bare = rtrim(str_replace('\\', '/', $path), '/');
            if ($bare === '' || $bare === '.') {
                if ($size > 0) {
                    $this->readData($fh, $size);
                }
                continue;
            }

            $rel = $this->assertSafePath($typeflag === '5' ? rtrim($path, '/') . '/' : $path, $requiredPrefix);

            if ($typeflag === '5') {
                $this->ensureDir($destDir . '/' . rtrim($rel, '/'));
                continue;
            }
            // '7' (contiguous file) je běžný soubor; cokoliv jiného neznámého odmítni.
            if (!in_array($typeflag, ['0', '7', "\0", ''], true)) {
                throw new RuntimeException('Archiv obsahuje nepodporovaný typ záznamu (' . $path . ').');
            }

            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('Rozbalený obsah přesáhl ' . self::MAX_TOTAL_BYTES . ' B.');
            }

            $this->writeFile($fh, $destDir . '/' . $rel, $size, $mode);
            $this->files[] = $rel;
        }
    }

    /**
     * Validace jména ze archivu. Vrací cestu relativní k cíli extrakce.
     * Cokoliv podezřelého končí výjimkou — nic se netiše nepřeskakuje.
     */
    private function assertSafePath(string $path, string $requiredPrefix): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Archiv obsahuje prázdné nebo poškozené jméno záznamu.');
        }
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, '/') || preg_match('#^[a-z]:#i', $normalized)) {
            throw new RuntimeException('Archiv obsahuje absolutní cestu: ' . $path);
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException('Archiv obsahuje traversal cestu: ' . $path);
            }
        }
        if (!str_starts_with($normalized, $requiredPrefix)) {
            throw new RuntimeException('Archiv obsahuje položku mimo očekávaný adresář: ' . $path);
        }

        return $normalized;
    }

    /** @param resource $fh */
    private function writeFile($fh, string $dest, int $size, int $mode): void
    {
        $this->ensureDir(dirname($dest));
        $out = @fopen($dest, 'wb');
        if ($out === false) {
            throw new RuntimeException('Nelze zapsat ' . $dest . '.');
        }

        try {
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = gzread($fh, min(262144, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Archiv skončil dřív, než měl (' . $dest . ').');
                }
                if (fwrite($out, $chunk) === false) {
                    throw new RuntimeException('Zápis do ' . $dest . ' selhal.');
                }
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($out);
        }

        // Doskoč na hranici 512B bloku.
        $padding = (self::BLOCK - ($size % self::BLOCK)) % self::BLOCK;
        if ($padding > 0) {
            gzread($fh, $padding);
        }

        @chmod($dest, ($mode & 0111) !== 0 ? 0755 : 0644);
    }

    /**
     * ustar checksum: součet bajtů hlavičky, kde je pole checksumu nahrazené
     * mezerami. Historicky se počítal i se znaménkem, takže bereme obě varianty.
     */
    private function assertChecksum(string $header): void
    {
        $stored = $this->octal($this->field($header, 148, 8));
        $blanked = substr_replace($header, '        ', 148, 8);

        $unsigned = 0;
        $signed   = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $byte = ord($blanked[$i]);
            $unsigned += $byte;
            $signed   += $byte > 127 ? $byte - 256 : $byte;
        }

        if ($stored !== $unsigned && $stored !== $signed) {
            throw new RuntimeException('Poškozená hlavička v archivu (checksum nesouhlasí).');
        }
    }

    /** Z pax hlavičky vytáhne `path=` atribut, pokud tam je. */
    private function paxPath(string $data): ?string
    {
        if (preg_match('/^\d+ path=(.*)$/m', $data, $m) === 1) {
            return rtrim($m[1], "\n\0");
        }

        return null;
    }

    /** @param resource $fh @return string|null null na konci streamu */
    private function readBlock($fh): ?string
    {
        $block = gzread($fh, self::BLOCK);
        if ($block === false || $block === '') {
            return null;
        }
        if (strlen($block) < self::BLOCK) {
            // Poslední blok bývá zkrácený padding — doplň, ať parsing nespadne.
            $block = str_pad($block, self::BLOCK, "\0");
        }

        return $block;
    }

    /** @param resource $fh Přečte data záznamu včetně paddingu. */
    private function readData($fh, int $size): string
    {
        $data = '';
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = gzread($fh, min(262144, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Archiv skončil dřív, než měl.');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        $padding = (self::BLOCK - ($size % self::BLOCK)) % self::BLOCK;
        if ($padding > 0) {
            gzread($fh, $padding);
        }

        return $data;
    }

    private function field(string $header, int $offset, int $length): string
    {
        $raw = substr($header, $offset, $length);
        $nul = strpos($raw, "\0");
        if ($nul !== false) {
            $raw = substr($raw, 0, $nul);
        }

        return trim($raw);
    }

    private function octal(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^[0-7]+$/', $value) !== 1) {
            return -1;
        }

        return (int) octdec($value);
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nelze vytvořit adresář ' . $dir . '.');
        }
    }
}
