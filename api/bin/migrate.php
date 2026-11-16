<?php

declare(strict_types=1);

/**
 * Jednoduchý migrator: spustí SQL soubory z db/migrations/ v abecedním pořadí
 * a sleduje, co už proběhlo, v tabulce `migrations`. Idempotentní — opakovaný běh = no-op.
 *
 * Použití:
 *   php api/bin/migrate.php            # aplikuje pending migrace
 *   php api/bin/migrate.php --status   # jen stav, žádná akce
 */
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$db      = (new Connection($config))->pdo();

$migrationsDir = $rootDir . '/db/migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(1);
}

// Zajisti tabulku migrations
$db->exec(
    'CREATE TABLE IF NOT EXISTS migrations ('
    . ' filename VARCHAR(190) PRIMARY KEY,'
    . ' applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
    . ' duration_ms INT UNSIGNED NOT NULL DEFAULT 0'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $db->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob($migrationsDir . '/*.sql');
sort($files, SORT_STRING);

$statusOnly = in_array('--status', $_SERVER['argv'], true);

if ($statusOnly) {
    echo "Migration status:\n";
    foreach ($files as $file) {
        $name   = basename($file);
        $marker = isset($applied[$name]) ? '[x]' : '[ ]';
        echo "  {$marker} {$name}\n";
    }
    exit(0);
}

$pending = array_filter($files, fn (string $f) => !isset($applied[basename($f)]));

if (empty($pending)) {
    echo "Žádné nové migrace k aplikaci.\n";
} else {
    echo "Pending migrations: " . count($pending) . "\n";
}

foreach ($pending as $file) {
    $name = basename($file);
    echo "  → {$name} ... ";

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "READ FAILED\n");
        exit(1);
    }

    $start = microtime(true);
    try {
        foreach (splitSqlStatements($sql) as $stmt) {
            // Odstraň leading řádkové komentáře a prázdné řádky, abychom poznali prázdný statement
            $cleaned = preg_replace('/^(\s*--[^\n]*\n)+/', '', $stmt) ?? $stmt;
            $cleaned = trim($cleaned);
            if ($cleaned === '') {
                continue;
            }
            $db->exec($stmt);
        }
    } catch (\Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, '  Error: ' . $e->getMessage() . "\n");
        exit(1);
    }

    $durationMs = (int) ((microtime(true) - $start) * 1000);

    // INSERT IGNORE: pokud dva migrate procesy běží paralelně (např. docker-entrypoint.sh
    // + explicitní `docker compose exec ... migrate.php`), oba mohou považovat stejný
    // soubor za pending. Migrace samotné jsou idempotentní (CREATE TABLE IF NOT EXISTS,
    // ALTER ... IF EXISTS, atd.), takže druhý běh nezmění schema. IGNORE pak zamezí
    // crash na duplicate PK v této bookkeeping tabulce.
    $stmt = $db->prepare('INSERT IGNORE INTO migrations (filename, duration_ms) VALUES (?, ?)');
    $stmt->execute([$name, $durationMs]);
    $alreadyRecorded = $stmt->rowCount() === 0;

    if ($alreadyRecorded) {
        echo "OK ({$durationMs} ms, already recorded — race with another migrator)\n";
    } else {
        echo "OK ({$durationMs} ms)\n";
    }
}

echo "Hotovo.\n";

// Tajemství ve starším formátu (enc:v1, nešifrovaný text) převeď na aktuální klíč a formát s AAD
$rotation = (new \MyPortfolio\Service\Auth\SecretRotator(
    new Connection($config),
    new \MyPortfolio\Service\Auth\SecretEncryption($config),
))->rotate();
foreach ($rotation as $column => $r) {
    if ($r['rotated'] > 0) {
        echo "Tajemství {$column}: převedeno {$r['rotated']}\n";
    }
    if ($r['failed'] !== []) {
        fwrite(STDERR, "VAROVÁNÍ: {$column} — nejde převést (id " . implode(', ', $r['failed']) . '); '
            . "zkontroluj app.secret_encryption_key / previous_keys a spusť api/bin/rotate-secret-key.php\n");
    }
}

// Čerstvá instalace: instalační kód pro průvodce /setup (bez něj správce nejde založit)
if ((int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn() > 0
    && (int) $db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn() === 0
) {
    $setupToken = new \MyPortfolio\Service\Auth\SetupToken();
    try {
        $code = $setupToken->ensure();
        $url = rtrim((string) $config->get('app.url', ''), '/');
        echo "\nInstalační kód pro /setup: {$code}\n"
            . ($url !== '' ? "Otevři: {$url}/setup?token={$code}\n" : '')
            . "(uložený v {$setupToken->path()})\n";
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
    }
}

/**
 * Rozdělí SQL na jednotlivé statementy podle aktuálního delimiteru.
 * Default delimiter `;` lze přepnout direktivou `DELIMITER xxx` (klient-side, na vlastním řádku),
 * což je nutné pro CREATE PROCEDURE / TRIGGER s `;` uvnitř těla.
 *
 * Respektuje single-quoted stringy a komentáře `-- ...` a `/* ... *\/`.
 */
function splitSqlStatements(string $sql): array
{
    $stmts = [];
    $current = '';
    $delim = ';';
    $len = strlen($sql);
    $inSingle = false;
    $inLineComment = false;
    $inBlockComment = false;
    $atLineStart = true;

    for ($i = 0; $i < $len; $i++) {
        // DELIMITER directive — pouze na začátku řádku, mimo string/komentář
        if ($atLineStart && !$inSingle && !$inLineComment && !$inBlockComment) {
            $j = $i;
            while ($j < $len && ($sql[$j] === ' ' || $sql[$j] === "\t")) $j++;
            if ($j + 10 <= $len && strcasecmp(substr($sql, $j, 10), 'DELIMITER ') === 0) {
                $eol = strpos($sql, "\n", $j + 10);
                if ($eol === false) $eol = $len;
                $newDelim = trim(substr($sql, $j + 10, $eol - ($j + 10)));
                if ($newDelim !== '') {
                    if (trim($current) !== '') {
                        $stmts[] = $current;
                        $current = '';
                    }
                    $delim = $newDelim;
                }
                $i = $eol; // hlavní cyklus posune na další řádek
                $atLineStart = true;
                continue;
            }
        }
        $atLineStart = false;

        $ch  = $sql[$i];
        $nxt = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($inLineComment) {
            $current .= $ch;
            if ($ch === "\n") { $inLineComment = false; $atLineStart = true; }
            continue;
        }
        if ($inBlockComment) {
            $current .= $ch;
            if ($ch === '*' && $nxt === '/') {
                $current .= '/';
                $i++;
                $inBlockComment = false;
            }
            continue;
        }
        if ($inSingle) {
            $current .= $ch;
            if ($ch === '\\' && $nxt !== '') {
                $current .= $nxt;
                $i++;
                continue;
            }
            if ($ch === "'") $inSingle = false;
            continue;
        }

        if ($ch === '-' && $nxt === '-') {
            $current .= '--';
            $i++;
            $inLineComment = true;
            continue;
        }
        if ($ch === '/' && $nxt === '*') {
            $current .= '/*';
            $i++;
            $inBlockComment = true;
            continue;
        }
        if ($ch === "'") {
            $inSingle = true;
            $current .= $ch;
            continue;
        }
        if ($ch === "\n") {
            $current .= $ch;
            $atLineStart = true;
            continue;
        }

        // Match aktuální delimiter (může být multi-char, např. `//`)
        $dlen = strlen($delim);
        if (substr_compare($sql, $delim, $i, $dlen) === 0) {
            if (trim($current) !== '') $stmts[] = $current;
            $current = '';
            $i += $dlen - 1;
            continue;
        }
        $current .= $ch;
    }

    if (trim($current) !== '') $stmts[] = $current;

    return $stmts;
}
