<?php

declare(strict_types=1);

/**
 * Obnova ze zálohy cron-backup (ZIP: <db>-<datum>.sql + icons/*.png, volitelně AES s cron.backup.password).
 *
 *   php8.5 api/bin/restore-backup.php <zip>                       # jen vypíše obsah zálohy, nic nezmění
 *   php8.5 api/bin/restore-backup.php <zip> --yes                 # obnoví databázi z cfg + ikony
 *   php8.5 api/bin/restore-backup.php <zip> --yes --db=<název>    # obnoví do jiné DB (zkouška zálohy), ikony vynechá
 *
 * Při obnově do databáze z cfg nejdřív udělá pojistnou zálohu aktuálního stavu (cron-backup), pak smaže
 * všechny tabulky, naimportuje dump, obnoví ikony a spustí migrace (záloha může být ze starší verze).
 * Docker: docker compose exec app php api/bin/restore-backup.php /data/storage/backup/<zip> --yes
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Service\Cron\BackupEncryption;

// Ruční parsování — getopt() končí na prvním poziční argumentu, takže `<zip> --yes` by nepřečetl.
$opts = [];
$zipPath = '';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(yes|no-safety-backup)$/', $arg, $m) === 1) {
        $opts[$m[1]] = true;
    } elseif (preg_match('/^--db=(.+)$/', $arg, $m) === 1) {
        $opts['db'] = $m[1];
    } elseif (!str_starts_with($arg, '--')) {
        $zipPath = $arg;
    } else {
        fwrite(STDERR, "Neznámá volba: $arg\n");
        exit(1);
    }
}
if ($zipPath === '' || !is_file($zipPath)) {
    fwrite(STDERR, "Použití: php api/bin/restore-backup.php <zip> [--yes] [--db=<název>] [--no-safety-backup]\n");
    exit(1);
}
$rootDir = Bootstrap::rootDir();
$config = Config::load($rootDir);
$liveDb = (string) $config->get('db.name');
$target = (string) ($opts['db'] ?? $liveDb);
$toLive = $target === $liveDb;
if (preg_match('/^[A-Za-z0-9_]+$/', $target) !== 1) {
    fwrite(STDERR, "Neplatný název databáze: $target\n");
    exit(1);
}

// ── Obsah zálohy ─────────────────────────────────────────────────────
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "ZIP nejde otevřít: $zipPath\n");
    exit(1);
}
$password = BackupEncryption::passwordFromConfig($config);
if ($password !== '') {
    $zip->setPassword($password);
}
$sqlEntry = null;
$icons = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    if (str_ends_with($name, '.sql') && !str_contains($name, '/')) {
        $sqlEntry = $name;
    } elseif (preg_match('#^icons/[0-9a-f]{32}\.png$#', $name) === 1) {
        $icons[] = $name;
    }
}
if ($sqlEntry === null) {
    fwrite(STDERR, "V záloze není SQL dump.\n");
    exit(1);
}
$sqlSize = (int) ($zip->statName($sqlEntry)['size'] ?? 0);
printf("Záloha:  %s\nDump:    %s (%.1f MB)\nIkony:   %d\nCíl:     databáze %s%s\n",
    basename($zipPath), $sqlEntry, $sqlSize / 1048576, count($icons), $target, $toLive ? ' (z cfg — ostrá data)' : '');
if (!isset($opts['yes'])) {
    echo "\nNic se nezměnilo. Obnovu spustíš přidáním --yes.\n";
    exit(0);
}

// ── Pojistná záloha aktuálního stavu ─────────────────────────────────
$php = PHP_BINARY;
if ($toLive && !isset($opts['no-safety-backup'])) {
    echo "\nPojistná záloha aktuálního stavu…\n";
    passthru(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/cron-backup.php'), $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "Pojistná záloha selhala — obnova přerušena (přeskočíš --no-safety-backup).\n");
        exit(1);
    }
}

// ── Rozbalení dumpu ──────────────────────────────────────────────────
$work = RuntimePaths::storage('backup/.restore-' . bin2hex(random_bytes(4)));
@mkdir($work, 0700, true);
$cleanup = static function () use ($work): void {
    foreach (glob($work . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($work);
};
register_shutdown_function($cleanup);
$sqlFile = "$work/dump.sql";
$in = $zip->getStream($sqlEntry);
if ($in === false || ($out = fopen($sqlFile, 'wb')) === false || stream_copy_to_stream($in, $out) === false) {
    fwrite(STDERR, "Dump nejde rozbalit" . ($password === '' ? ' (záloha je možná šifrovaná — nastav cron.backup.password).' : ' (špatné heslo zálohy?).') . "\n");
    exit(1);
}
fclose($out);
if (filesize($sqlFile) !== $sqlSize) {
    fwrite(STDERR, "Rozbalený dump má jinou velikost než v ZIPu — špatné heslo nebo poškozená záloha.\n");
    exit(1);
}

// ── Import ───────────────────────────────────────────────────────────
$host = (string) $config->get('db.host', '127.0.0.1');
$cnf = "$work/client.cnf";
file_put_contents($cnf, sprintf("[client]\nhost=%s\nport=%d\nuser=%s\npassword=\"%s\"\n",
    $host === 'localhost' ? '127.0.0.1' : $host, (int) $config->get('db.port', 3306), (string) $config->get('db.user'),
    str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $config->get('db.pass', ''))));
chmod($cnf, 0600);
$client = '';
foreach (['mariadb', 'mysql'] as $bin) {
    exec(sprintf('%s --version 2>/dev/null', escapeshellarg($bin)), $o, $rcv);
    if ($rcv === 0) { $client = $bin; break; }
}
if ($client === '') {
    fwrite(STDERR, "Chybí klient mariadb / mysql v PATH.\n");
    exit(1);
}
$sql = static function (string $statement) use ($client, $cnf): int {
    passthru(sprintf('%s --defaults-extra-file=%s -e %s', escapeshellarg($client), escapeshellarg($cnf), escapeshellarg($statement)), $rc);
    return $rc;
};

echo "Mažu tabulky v {$target}…\n";
// Dump obsahuje DROP TABLE IF EXISTS jen pro své tabulky — novější tabulky by zůstaly, proto čistá DB.
if ($sql("DROP DATABASE IF EXISTS `$target`; CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") !== 0) {
    fwrite(STDERR, "Databázi $target nejde vytvořit (oprávnění uživatele DB?).\n");
    exit(1);
}
echo "Importuji dump…\n";
passthru(sprintf('%s --defaults-extra-file=%s %s < %s', escapeshellarg($client), escapeshellarg($cnf), escapeshellarg($target), escapeshellarg($sqlFile)), $rc);
if ($rc !== 0) {
    fwrite(STDERR, "Import selhal (kód $rc).\n");
    exit(1);
}

// ── Ikony (jen při obnově ostré DB — složka ikon je společná) ────────
if ($toLive && $icons !== []) {
    $dir = RuntimePaths::storage('uploads/icons');
    @mkdir($dir, 0775, true);
    foreach ($icons as $entry) {
        $data = $zip->getFromName($entry);
        if ($data !== false) {
            file_put_contents($dir . '/' . basename($entry), $data);
        }
    }
    printf("Obnoveno ikon: %d\n", count($icons));
}
$zip->close();

// ── Migrace (záloha může být ze starší verze) ────────────────────────
echo "Migrace…\n";
passthru(sprintf('MYPORTFOLIO_DB_NAME=%s %s %s', escapeshellarg($target), escapeshellarg($php), escapeshellarg(__DIR__ . '/migrate.php')), $rc);
if ($rc !== 0) {
    fwrite(STDERR, "Migrace selhaly — zkontroluj výstup výše.\n");
    exit(1);
}
echo "✓ Obnova dokončena do databáze $target." . ($toLive ? ' Historie grafů se dopočítá sama (nebo backfill-snapshots).' : '') . "\n";
