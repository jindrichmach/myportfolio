<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Import;

use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Service\Import\Parser\AbstractParser;
use MyPortfolio\Service\Import\Reader\TabularReader;
use PHPUnit\Framework\TestCase;

final class TabularReaderTest extends TestCase
{
    /** Minimální XLSX (sdílené i inline řetězce, číslo, chybějící buňka). */
    public static function xlsx(array $sheets): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mp-test-xlsx-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $shared = [];
        $wbSheets = $rels = '';
        $i = 0;
        foreach ($sheets as $name => $rows) {
            $i++;
            $xmlRows = '';
            foreach ($rows as $r => $cells) {
                $xmlCells = '';
                foreach ($cells as $c => $v) {
                    if ($v === null) {
                        continue;
                    }
                    $ref = chr(65 + $c) . ($r + 1);
                    if (is_int($v) || is_float($v)) {
                        $xmlCells .= "<c r=\"$ref\"><v>$v</v></c>";
                    } else {
                        $shared[] = htmlspecialchars((string) $v, ENT_XML1);
                        $xmlCells .= "<c r=\"$ref\" t=\"s\"><v>" . (count($shared) - 1) . '</v></c>';
                    }
                }
                $xmlRows .= '<row r="' . ($r + 1) . "\">$xmlCells</row>";
            }
            $zip->addFromString("xl/worksheets/sheet$i.xml", '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xmlRows . '</sheetData></worksheet>');
            $wbSheets .= "<sheet name=\"$name\" sheetId=\"$i\" r:id=\"rId$i\"/>";
            $rels .= "<Relationship Id=\"rId$i\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$i.xml\"/>";
        }
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . implode('', array_map(fn ($s) => "<si><t>$s</t></si>", $shared)) . '</sst>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    public function testCsvVariants(): void
    {
        $r = new TabularReader();
        // BOM, středník, preambule, uvozovky s oddělovačem uvnitř
        $t = $r->read("\xEF\xBB\xBFReport for demo\n\nDate;Type;Note\n2024-01-02;Buy;\"a;b\"\n", 'x.csv')[0];
        self::assertSame([['__line' => '4', 'Date' => '2024-01-02', 'Type' => 'Buy', 'Note' => 'a;b']], $t->withHeader(['date', 'TYPE']));
        // CP1250 se převede na UTF-8
        $cp = iconv('UTF-8', 'WINDOWS-1250', "Datum,Částka\n1.1.2024,\"1 234,50\"\n");
        self::assertSame('1 234,50', $r->read((string) $cp, 'x.csv')[0]->withHeader(['Částka'])[0]['Částka']);
        // tabulátor
        self::assertSame('B', $r->read("a\tb\n1\tB\n", 'x.tsv')[0]->withHeader(['a', 'b'])[0]['b']);
        self::assertNull($r->read("a,b\n1,2\n", 'x.csv')[0]->withHeader(['c']));
    }

    public function testXlsx(): void
    {
        $bytes = self::xlsx(['Info' => [['Account', 'Demo']], 'Cash' => [['ID', 'Type', 'Time', 'Amount'], [1, 'Deposit', 45292.5, 100.5], [2, 'Dividend', null, 3]]]);
        $tables = (new TabularReader())->read($bytes, 'x.xlsx');
        self::assertSame(['Info', 'Cash'], array_map(fn ($t) => $t->name, $tables));
        $rows = $tables[1]->withHeader(['id', 'type', 'time', 'amount']);
        self::assertSame(['1', 'Deposit', '45292.5', '100.5'], [$rows[0]['ID'], $rows[0]['Type'], $rows[0]['Time'], $rows[0]['Amount']]);
        self::assertSame('', $rows[1]['Time']);
    }

    public function testXlsxDoesNotAllocateGapsOrHugeSheets(): void
    {
        // Řádek 1 048 576: díra se nealokuje — mezera = jeden prázdný řádek
        $rows = (new TabularReader())->read(self::xlsx(['S' => [0 => ['a', 'b'], 1048575 => ['1', '2']]]), 'x.xlsx')[0]->rows;
        self::assertSame([['a', 'b'], [], ['1', '2']], $rows);

        // Sloupec XFD (16 384) → odmítnuto, ne pole 16 384 buněk na řádek
        $tmp = tempnam(sys_get_temp_dir(), 'mp-test-xlsx-');
        file_put_contents($tmp, self::xlsx(['S' => [['a']]]));
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="XFD1"><v>1</v></c></row></sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);
        $this->expectException(\InvalidArgumentException::class);
        (new TabularReader())->read($bytes, 'x.xlsx');
    }

    public function testNumberAndDateHelpers(): void
    {
        $p = new class extends AbstractParser {
            public function key(): string { return 't'; }
            public function label(): string { return 't'; }
            public function detect(array $tables): bool { return false; }
            /** @return list<Draft> */
            public function parse(array $tables): array { return []; }
            public function n(string $v, bool $abs = true, ?string $d = null): ?string { return $this->num($v, $abs, $d); }
            public function d(string $v, string $tz = 'UTC', ?string $f = null): string { return $this->dateTime($v, $tz, $f); }
        };
        self::assertSame('1234.56', $p->n('1,234.56'));
        self::assertSame('1234.56', $p->n('1 234,56 Kč'));
        self::assertSame('1234.56', $p->n('1.234,56'));
        self::assertSame('-12.5', $p->n('-12.50 EUR', false));
        self::assertSame('12.5', $p->n('(12.50)'));
        self::assertSame('0.00001234', $p->n('1.234E-5'));
        self::assertSame('1234', $p->n('1,234', true, '.'));
        self::assertSame('1.5', $p->n('1,5'));
        self::assertNull($p->n(''));

        date_default_timezone_set('Europe/Prague');
        self::assertSame('2024-01-31 13:00:00', $p->d('2024-01-31T12:00:00Z'));
        self::assertSame('2024-07-01 14:00:00', $p->d('2024-07-01 12:00:00 UTC'));
        self::assertSame('2024-01-31 12:00:00', $p->d('31.01.2024 12:00', 'Europe/Prague'));
        self::assertSame('2024-01-01 13:00:00', $p->d('45292.5'));                      // Excel serial, UTC
        self::assertSame('2024-03-05 10:00:00', $p->d('05/03/2024 09:00', 'UTC', 'd/m/Y H:i'));
    }
}
