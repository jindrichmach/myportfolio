<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Backup;

use ZipArchive;

/**
 * Sjednocení práv u položek zálohovacího ZIPu.
 *
 * PHP (libzip) razí každou položku jako `opsys = UNIX` a doplní k ní unixový
 * mód. Na Linuxu ho bere ze zdrojového souboru, takže se do zálohy propíše, jak
 * má instalace nastavené umask a práva — typicky 0600 vlastněné uživatelem
 * php-fpm. `unzip` pak ta práva při obnově aplikuje a rozbalené doklady jsou
 * nečitelné pro kohokoliv jiného; na Windows se navíc razí pevné 0666, takže
 * záloha z Windows a z Linuxu není ani zaměnitelná. Obojí dělá z obnovy ruční
 * práci s chmod/chown, ačkoliv záloha má být přenositelná.
 *
 * Práva zdrojového systému do zálohy nepatří — pro archiv dokladů nenesou
 * žádnou informaci a při obnově jen škodí. Každá položka proto dostane pevné
 * 0644 (adresář 0755) bez ohledu na to, kde a pod kým záloha vznikla.
 */
final class BackupZipPermissions
{
    /** rw-r--r-- — čitelné pro webserver i pro toho, kdo zálohu rozbalí. */
    public const FILE_MODE = 0100644;

    /** rwxr-xr-x — dtto pro adresářové položky, pokud je někdo přidá. */
    public const DIR_MODE = 040755;

    /**
     * Přerazí práva položky na neutrální hodnotu. Vrací false, když to ZIP
     * knihovna odmítne — volající to bere jako chybu zápisu zálohy.
     */
    public static function neutralize(ZipArchive $zip, string $entry, bool $isDir = false): bool
    {
        $mode = $isDir ? self::DIR_MODE : self::FILE_MODE;

        return $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, $mode << 16);
    }
}
