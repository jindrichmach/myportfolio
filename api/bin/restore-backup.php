<?php

declare(strict_types=1);

/**
 * Obnova ze zálohy cron-backup (ZIP: <db>-<datum>.sql + icons/*.png, volitelně AES s cron.backup.password).
 *
 *   php8.5 api/bin/restore-backup.php <zip>                       # jen vypíše obsah zálohy, nic nezmění
 *   php8.5 api/bin/restore-backup.php <zip> --yes                 # obnoví databázi z cfg + ikony (ostrá DB: odhlásí všechny uživatele, zapíše activity log)
 *   php8.5 api/bin/restore-backup.php <zip> --yes --db=<název>    # obnoví do jiné DB (zkouška zálohy), ikony vynechá
 *   php8.5 api/bin/restore-backup.php <název.zip> --yes --web --actor=<user_id>   # web režim (spouští ho API na pozadí)
 *
 * Při obnově do databáze z cfg nejdřív udělá pojistnou zálohu aktuálního stavu (cron-backup), pak smaže
 * všechny tabulky, naimportuje dump, obnoví ikony a spustí migrace (záloha může být ze starší verze).
 * Obnova ostré DB drží storage/restore.lock, zapisuje storage/restore-state.json + log/restore.log a po celou
 * dobu zapíná údržbu (503). Web režim bere zálohu jen podle názvu ze složky záloh, pojistná záloha je povinná
 * a po obnově ostré DB (web i CLI) se zneplatní všechny sessions a zapíše activity log.
 * Docker: docker compose exec app php api/bin/restore-backup.php /data/storage/backup/<zip> --yes
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Backup\BackupCatalog;
use MyPortfolio\Service\Backup\BackupDirectory;
use MyPortfolio\Service\Backup\RestoreRunner;
use MyPortfolio\Service\Backup\RestoreState;
use MyPortfolio\Service\Cron\BackupEncryption;

// Ruční parsování — getopt() končí na prvním poziční argumentu, takže `<zip> --yes` by nepřečetl.
$opts = [];
$zipArg = '';
foreach (array_slice($_SERVER['argv'], 1) as $arg) {
    if (preg_match('/^--(yes|no-safety-backup|web)$/', $arg, $m) === 1) {
        $opts[$m[1]] = true;
    } elseif (preg_match('/^--(db|actor)=(.+)$/', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2];
    } elseif (!str_starts_with($arg, '--')) {
        $zipArg = $arg;
    } else {
        fwrite(STDERR, "Neznámá volba: $arg\n");
        exit(1);
    }
}
$web = isset($opts['web']);
$usage = "Použití: php api/bin/restore-backup.php <zip> [--yes] [--db=<název>] [--no-safety-backup]\n";
if ($zipArg === '') {
    fwrite(STDERR, $usage);
    exit(1);
}
$rootDir = Bootstrap::rootDir();
$config = Config::load($rootDir);
$catalog = new BackupCatalog(BackupDirectory::resolve($config));
if ($web) {
    $zipPath = $catalog->find($zipArg);
    if (isset($opts['db']) || isset($opts['no-safety-backup']) || !isset($opts['yes']) || !isset($opts['actor']) || ctype_digit((string) $opts['actor']) === false) {
        fwrite(STDERR, "Web režim vyžaduje --yes --actor=<user_id> a nepovoluje --db ani --no-safety-backup.\n");
        exit(1);
    }
} else {
    $zipPath = is_file($zipArg) ? $zipArg : null;
}
if ($zipPath === null) {
    fwrite(STDERR, $web ? "Záloha $zipArg není v seznamu záloh.\n" : $usage);
    exit(1);
}
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

// ── Zámek, log, příkazy ──────────────────────────────────────────────
$lock = null;
$restoreState = new RestoreState();
$logFile = RuntimePaths::log('restore.log');
$say = static function (string $line) use ($toLive, $logFile): void {
    echo $line . "\n";
    if ($toLive) {
        @mkdir(dirname($logFile), 0775, true);
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
    }
};
if ($toLive) {
    @mkdir(RuntimePaths::storage(), 0775, true);
    $lock = fopen(RuntimePaths::storage('restore.lock'), 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Obnova už běží.\n");
        exit(2);
    }
    @mkdir(dirname($logFile), 0775, true);
    @file_put_contents($logFile, '');
}
// Potomci (pojistná záloha) nesmí být přeskočeni vlastní značkou údržby.
putenv('MYPORTFOLIO_RESTORE_RUNNER=1');

/** Spustí příkaz, výstup projde do logu; nenulový kód = výjimka. */
$exec = static function (string $cmd, string $failure) use ($say): void {
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    foreach ($out as $line) {
        $say((string) preg_replace('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] /', '', $line));
    }
    if ($rc !== 0) {
        throw new RuntimeException("$failure (kód $rc)");
    }
};

$php = PHP_BINARY;
$work = RuntimePaths::storage('backup/.restore-' . bin2hex(random_bytes(4)));
$sqlFile = "$work/dump.sql";
$cnf = "$work/client.cnf";
$client = '';
$cleanup = static function () use ($work): void {
    foreach (glob($work . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($work);
};
register_shutdown_function($cleanup);
$sql = static function (string $statement, ?string $db = null) use (&$client, $cnf, $exec): void {
    $exec(sprintf('%s --defaults-extra-file=%s %s-e %s', escapeshellarg($client), escapeshellarg($cnf),
        $db !== null ? escapeshellarg($db) . ' ' : '', escapeshellarg($statement)), 'SQL příkaz selhal');
};

$steps = [];
if ($toLive && ($web || !isset($opts['no-safety-backup']))) {
    $steps['safety_backup'] = static function () use ($catalog, $exec, $php, $restoreState, $say, $zipPath, $liveDb): void {
        // Záloha ve stejné minutě by přepsala existující soubor (obnovovaný nebo dřívější pojistnou); název má jen minutové rozlišení.
        if ($catalog->find($liveDb . date('-Y-m-d_H-i') . '.zip') !== null) {
            $say('Čekám na další minutu, aby pojistná záloha nepřepsala existující zálohu…');
            sleep(61 - (int) date('s'));
        }
        $say('Pojistná záloha aktuálního stavu…');
        $exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/cron-backup.php'), 'Pojistná záloha selhala — obnova přerušena');
        $latest = $catalog->list()[0]['name'] ?? null;
        if ($latest === null || $latest === basename($zipPath)) {
            throw new RuntimeException('Pojistnou zálohu se nepodařilo identifikovat (přepsala obnovovanou?) — obnova přerušena.');
        }
        $restoreState->merge(['safety_backup' => $latest]);
    };
}
$steps['extract'] = static function () use ($work, $sqlFile, $cnf, $zip, $sqlEntry, $sqlSize, $password, $config, &$client): void {
    @mkdir($work, 0700, true);
    $in = $zip->getStream($sqlEntry);
    if ($in === false || ($out = fopen($sqlFile, 'wb')) === false || stream_copy_to_stream($in, $out) === false) {
        throw new RuntimeException('Dump nejde rozbalit' . ($password === '' ? ' (záloha je možná šifrovaná — nastav cron.backup.password).' : ' (špatné heslo zálohy?).'));
    }
    fclose($out);
    if (filesize($sqlFile) !== $sqlSize) {
        throw new RuntimeException('Rozbalený dump má jinou velikost než v ZIPu — špatné heslo nebo poškozená záloha.');
    }
    $host = (string) $config->get('db.host', '127.0.0.1');
    file_put_contents($cnf, sprintf("[client]\nhost=%s\nport=%d\nuser=%s\npassword=\"%s\"\n",
        $host === 'localhost' ? '127.0.0.1' : $host, (int) $config->get('db.port', 3306), (string) $config->get('db.user'),
        str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $config->get('db.pass', ''))));
    chmod($cnf, 0600);
    foreach (['mariadb', 'mysql'] as $bin) {
        exec(sprintf('%s --version 2>/dev/null', escapeshellarg($bin)), $o, $rcv);
        if ($rcv === 0) { $client = $bin; break; }
    }
    if ($client === '') {
        throw new RuntimeException('Chybí klient mariadb / mysql v PATH.');
    }
};
$steps['drop_tables'] = static function () use ($toLive, $target, $config, $sql, $say): void {
    $say("Mažu tabulky v {$target}…");
    // Dump obsahuje DROP TABLE IF EXISTS jen pro své tabulky — novější tabulky by zůstaly, proto čistá DB.
    if (!$toLive) {
        $sql("DROP DATABASE IF EXISTS `$target`; CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        return;
    }
    // Ostrá DB: jen tabulky a views (stačí oprávnění na úrovni DB, ne na CREATE/DROP DATABASE).
    $drops = [];
    foreach ((new Connection($config))->pdo()->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
        $drops[] = sprintf('DROP %s IF EXISTS `%s`;', $type === 'VIEW' ? 'VIEW' : 'TABLE', str_replace('`', '``', (string) $name));
    }
    if ($drops !== []) {
        $sql('SET FOREIGN_KEY_CHECKS=0; ' . implode(' ', $drops) . ' SET FOREIGN_KEY_CHECKS=1;', $target);
    }
};
$steps['import'] = static function () use (&$client, $cnf, $target, $sqlFile, $exec, $say): void {
    $say('Importuji dump…');
    $exec(sprintf('%s --defaults-extra-file=%s %s < %s', escapeshellarg($client), escapeshellarg($cnf), escapeshellarg($target), escapeshellarg($sqlFile)), 'Import selhal');
};
// Ikony jen při obnově ostré DB — složka ikon je společná.
$steps['icons'] = static function () use ($toLive, $icons, $zip, $say): void {
    if ($toLive && $icons !== []) {
        $dir = RuntimePaths::storage('uploads/icons');
        @mkdir($dir, 0775, true);
        foreach ($icons as $entry) {
            $data = $zip->getFromName($entry);
            if ($data !== false) {
                file_put_contents($dir . '/' . basename($entry), $data);
            }
        }
        $say(sprintf('Obnoveno ikon: %d', count($icons)));
    }
};
$steps['migrate'] = static function () use ($target, $php, $exec): void {
    // Záloha může být ze starší verze.
    $exec(sprintf('MYPORTFOLIO_DB_NAME=%s %s %s', escapeshellarg($target), escapeshellarg($php), escapeshellarg(__DIR__ . '/migrate.php')), 'Migrace selhaly');
};
if ($toLive) {
    $steps['sessions'] = static function () use ($config, $opts, $web, $zipPath, $restoreState, $say): void {
        $db = new Connection($config);
        $pdo = $db->pdo();
        $pdo->exec('DELETE FROM sessions');
        $say('Všechny přihlášené relace zneplatněny.');
        $actor = $web ? (int) $opts['actor'] : null;
        $actorKnown = false;
        if ($actor !== null) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
            $exists->execute([$actor]);
            $actorKnown = (int) $exists->fetchColumn() > 0;
        }
        (new ActivityLogger($db))->log('admin.backup.restore', $actorKnown ? $actor : null, null, null, [
            'file' => basename($zipPath),
            'safety_backup' => $restoreState->read()['safety_backup'] ?? null,
            'actor_id' => $actor,
            'source' => $web ? 'web' : 'cli',
        ]);
    };
}

// ── Běh ──────────────────────────────────────────────────────────────
if ($toLive) {
    $actorEmail = null;
    if ($web) {
        $stmt = (new Connection($config))->pdo()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([(int) $opts['actor']]);
        $actorEmail = $stmt->fetchColumn() ?: null;
    }
    $runner = new RestoreRunner($restoreState, RuntimePaths::base(), $say(...));
    $ok = $runner->run($steps, ['file' => basename($zipPath), 'actor_email' => $actorEmail]);
} else {
    $ok = true;
    try {
        foreach ($steps as $action) {
            $action();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        $ok = false;
    }
}
$zip->close();
if (!$ok) {
    fwrite(STDERR, "Obnova selhala — zkontroluj výstup výše" . ($toLive ? ' a log/restore.log' : '') . ".\n");
    exit(1);
}
echo "✓ Obnova dokončena do databáze $target." . ($toLive ? ' Historie grafů se dopočítá sama (nebo backfill-snapshots).' : '') . "\n";
