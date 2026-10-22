<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Cron;

use MyPortfolio\Infrastructure\Config\Config;
use ZipArchive;

/**
 * Volitelné šifrování ZIP záloh (cron-backup, cron-backup-pdf,
 * cron-backup-documents) heslem z cfg `cron.backup.password`.
 *
 * AES-256 (WinZip AE-2) přes nativní ZipArchive — vyžaduje ext-zip
 * s libzip >= 1.2. Záměrně NEnabízíme legacy ZipCrypto (prolomené).
 * Šifruje se obsah souborů; názvy entries v central directory zůstávají
 * čitelné (vlastnost formátu ZIP).
 *
 * Pozn. pro obnovu: AES-256 archiv neotevře vestavěný Průzkumník Windows —
 * použij 7-Zip / WinRAR / `unzip -P`.
 */
final class BackupEncryption
{
    public static function passwordFromConfig(Config $config): string
    {
        return (string) $config->get('cron.backup.password', '');
    }

    /**
     * Chybová hláška, pokud je heslo nastavené a runtime AES-256 neumí;
     * jinak null. Volající má při hlášce skončit chybou — tiché vytvoření
     * NEšifrované zálohy by bylo bezpečnostní překvapení.
     */
    public static function unsupportedReason(string $password): ?string
    {
        if ($password === '' || defined(ZipArchive::class . '::EM_AES_256')) {
            return null;
        }
        return 'cron.backup.password je nastavené, ale PHP ext-zip nepodporuje AES-256 šifrování '
            . '(vyžaduje libzip >= 1.2). Nešifrovaná záloha se záměrně nevytvoří — '
            . 'odstraň heslo z cfg.php, nebo aktualizuj PHP/libzip.';
    }

    /**
     * Označí entry k AES-256 zašifrování (volat po addFile/addFromString,
     * před close). S prázdným heslem no-op (true).
     */
    public static function encryptEntry(ZipArchive $zip, string $entryName, string $password): bool
    {
        if ($password === '') {
            return true;
        }
        return $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256, $password);
    }
}
