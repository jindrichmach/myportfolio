<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Reader;

use MyPortfolio\Service\BankNotice\EmailCharsetNormalizer;

/**
 * Načte importovaný soubor jako tabulky: CSV (UTF-8 s/bez BOM, UTF-16, CP1250; oddělovač , ; tab |)
 * nebo XLSX (každý list jedna tabulka; bez externí knihovny — ZipArchive + SimpleXML).
 */
final class TabularReader
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    /** Strop rozbalených dat ZIP / XLSX (ochrana proti „zip bombě"): jedna položka a celkem */
    private const MAX_ENTRY_BYTES = 50 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 150 * 1024 * 1024;
    private const MAX_COLUMNS = 1000;

    private int $unpacked = 0;

    /** @return list<Table> */
    public function read(string $bytes, string $filename): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Soubor je větší než 20 MB.');
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return $this->zip($bytes);
        }
        return [new Table(pathinfo($filename, PATHINFO_FILENAME), $this->csv($bytes))];
    }

    /** @return list<list<string>> */
    public function csv(string $bytes): array
    {
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        $bytes = EmailCharsetNormalizer::toUtf8($bytes);
        $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);

        $delimiter = $this->delimiter($bytes);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        $rows = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                $rows[] = [];
                continue;
            }
            $rows[] = array_map(static fn ($c) => (string) $c, $cells);
        }
        fclose($stream);
        return $rows;
    }

    /** Oddělovač, který dává nejvíc stejně širokých řádků mezi prvními 30 neprázdnými. */
    private function delimiter(string $bytes): string
    {
        $lines = array_slice(array_values(array_filter(explode("\n", $bytes), static fn ($l) => trim($l) !== '')), 0, 30);
        $best = ',';
        $bestScore = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $widths = array_map(static fn ($l) => count(str_getcsv($l, $d, '"', '')), $lines);
            $counts = array_count_values(array_filter($widths, static fn ($w) => $w > 1));
            $score = $counts === [] ? 0 : max($counts) * max(array_keys($counts, max($counts)));
            if ($score > $bestScore) {
                $best = $d;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /** ZIP = XLSX, nebo archiv s CSV soubory (Kraken, Binance). @return list<Table> */
    private function zip(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mp-zip-');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        $this->unpacked = 0;
        try {
            if ($zip->open($tmp) !== true) {
                throw new \InvalidArgumentException('Soubor ZIP / XLSX nejde otevřít.');
            }
            if ($zip->locateName('xl/workbook.xml') === false) {
                $tables = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (preg_match('/\.(csv|tsv|txt)$/i', $name) === 1 && $zip->statIndex($i)['size'] <= self::MAX_BYTES) {
                        $tables[] = new Table(pathinfo($name, PATHINFO_FILENAME), $this->csv((string) $this->entry($zip, $name)));
                    }
                }
                if ($tables === []) {
                    throw new \InvalidArgumentException('V archivu ZIP není žádný soubor CSV.');
                }
                return $tables;
            }
            $shared = [];
            if (($xml = $this->entry($zip, 'xl/sharedStrings.xml')) !== null) {
                foreach ($this->xml($xml)->si as $si) {
                    $shared[] = $this->text($si);
                }
            }
            $rels = [];
            foreach ($this->xml((string) $this->entry($zip, 'xl/_rels/workbook.xml.rels'))->Relationship as $rel) {
                $rels[(string) $rel['Id']] = ltrim(preg_replace('#^/?xl/#', '', (string) $rel['Target']) ?? '', '/');
            }
            $workbook = $this->xml((string) $this->entry($zip, 'xl/workbook.xml'));
            $tables = [];
            foreach ($workbook->sheets->sheet as $sheet) {
                $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $path = 'xl/' . ($rels[$rid] ?? '');
                $sheetXml = $this->entry($zip, $path);
                if ($sheetXml !== null) {
                    $tables[] = new Table((string) $sheet['name'], $this->sheetRows($this->xml($sheetXml), $shared));
                }
            }
            return $tables;
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    /** Položka archivu; nekomprimovaná velikost se zkontroluje dřív, než se rozbalí. */
    private function entry(\ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        $this->unpacked += (int) $stat['size'];
        if ($stat['size'] > self::MAX_ENTRY_BYTES || $this->unpacked > self::MAX_TOTAL_BYTES) {
            throw new \InvalidArgumentException('Soubor je po rozbalení příliš velký.');
        }
        $data = $zip->getFromName($name);
        return $data === false ? null : $data;
    }

    /**
     * Řádky listu v pořadí; mezera v číslování řádků = jeden prázdný řádek (díry se nealokují).
     *
     * @param list<string> $shared
     * @return list<list<string>>
     */
    private function sheetRows(\SimpleXMLElement $sheet, array $shared): array
    {
        $rows = [];
        $last = 0;
        foreach ($sheet->sheetData->row as $row) {
            $number = (int) $row['r'] ?: $last + 1;
            if ($number > $last + 1 && $rows !== []) {
                $rows[] = [];
            }
            $last = $number;
            $cells = [];
            foreach ($row->c as $c) {
                $col = $this->columnIndex((string) $c['r']) ?? count($cells);
                if ($col >= self::MAX_COLUMNS) {
                    throw new \InvalidArgumentException('List XLSX má příliš mnoho sloupců.');
                }
                $value = match ((string) $c['t']) {
                    's'         => $shared[(int) $c->v] ?? '',
                    'inlineStr' => $this->text($c->is),
                    'b'         => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default     => (string) $c->v,
                };
                $cells = array_pad($cells, $col, '');
                $cells[$col] = $value;
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    private function columnIndex(string $ref): ?int
    {
        if (preg_match('/^([A-Z]+)\d+$/', $ref, $m) !== 1) {
            return null;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    private function text(\SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $out = '';
        foreach ($node->r as $run) {
            $out .= (string) $run->t;
        }
        return $out;
    }

    private function xml(string $xml): \SimpleXMLElement
    {
        $el = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($el === false) {
            throw new \InvalidArgumentException('Poškozený soubor XLSX.');
        }
        return $el;
    }
}
