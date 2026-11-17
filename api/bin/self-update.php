<?php

declare(strict_types=1);

/**
 * Runner aktualizace nativní instalace z webu (spouští ho RunUpdateAction na pozadí).
 *
 * SAMOSTATNÝ skript — žádný autoloader ani třída aplikace: během `git checkout` a `composer install`
 * jsou soubory aplikace nekonzistentní. Akce ho proto před spuštěním kopíruje mimo strom (storage/update/).
 * Formát state souboru a maintenance.json je schválně duplikovaný (viz UpdateState, MaintenanceMode).
 *
 * Použití:
 *   php self-update.php --target=X.Y.Z --root=<app> --state=<update-state.json> --log=<update.log>
 *       --maintenance=<maintenance.json> [--lock=<update.lock>] [--env=production|development]
 *       [--php=… --git=… --composer=… --pnpm=…] [--dry-run]
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

const MAINTENANCE_TTL = 1800;

$opts = getopt('', ['target:', 'root:', 'state:', 'log:', 'maintenance:', 'lock:', 'env:', 'php:', 'git:', 'composer:', 'pnpm:', 'dry-run']);
foreach (['target', 'root', 'state', 'log', 'maintenance'] as $required) {
    if (!isset($opts[$required]) || !is_string($opts[$required]) || $opts[$required] === '') {
        fwrite(STDERR, "Chybí --{$required}.\n");
        exit(64);
    }
}

$target = $opts['target'];
if (preg_match('/^\d+\.\d+\.\d+$/', $target) !== 1) {
    fwrite(STDERR, "Neplatná verze: {$target}\n");
    exit(64);
}
$root        = rtrim((string) $opts['root'], '/\\');
$stateFile   = (string) $opts['state'];
$logFile     = (string) $opts['log'];
$maintFile   = (string) $opts['maintenance'];
$lockFile    = isset($opts['lock']) ? (string) $opts['lock'] : dirname($stateFile) . '/update.lock';
$dev         = ($opts['env'] ?? 'production') === 'development';
$dryRun      = array_key_exists('dry-run', $opts);
$php         = (string) ($opts['php'] ?? PHP_BINARY);
$git         = (string) ($opts['git'] ?? 'git');
$composer    = (string) ($opts['composer'] ?? 'composer');
$pnpm        = (string) ($opts['pnpm'] ?? 'pnpm');
$storageDir  = dirname($stateFile);

/** @return list<array{0:string,1:string}> [krok, příkaz] */
$plan = [
    ['backup', escapeshellarg($php) . ' ' . escapeshellarg($root . '/api/bin/cron-backup.php')],
    ['maintenance', ''],
    ['fetch', escapeshellarg($git) . ' fetch --tags'],
    ['checkout', escapeshellarg($git) . ' checkout ' . escapeshellarg('v' . $target)],
    ['composer', escapeshellarg($php) . ' ' . escapeshellarg($composer)
        . ' install --working-dir=' . escapeshellarg($root . '/api') . ' --no-interaction'
        . ($dev ? '' : ' --no-dev --optimize-autoloader')],
    ['migrate', escapeshellarg($php) . ' ' . escapeshellarg($root . '/api/bin/migrate.php')],
    ['pnpm_install', escapeshellarg($pnpm) . ' --dir ' . escapeshellarg($root . '/web') . ' install --frozen-lockfile'],
    ['pnpm_build', escapeshellarg($pnpm) . ' --dir ' . escapeshellarg($root . '/web') . ' build'],
    ['verify', ''],
];

if ($dryRun) {
    foreach ($plan as [$step, $cmd]) {
        echo $step . ': ' . ($cmd !== '' ? $cmd : '(interní krok)') . "\n";
    }
    exit(0);
}

function writeJson(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $file . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($tmp, $file);
}

function logLine(string $logFile, string $line): void
{
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

/** Najde binárku na PATH (pro sestavení PATH potomků). */
function findOnPath(string $name): ?string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_file($dir . '/' . $name) && is_executable($dir . '/' . $name)) {
            return $dir;
        }
    }
    return null;
}

// PATH potomků: adresáře použitých binárek (+ node, který potřebuje pnpm přes `env node`) a běžné systémové.
$dirs = [];
foreach ([$php, $git, $composer, $pnpm] as $bin) {
    if (str_contains($bin, '/')) {
        $dirs[] = dirname($bin);
    }
}
$nodeDir = findOnPath('node');
if ($nodeDir !== null) {
    $dirs[] = $nodeDir;
}
$dirs = array_merge($dirs, explode(PATH_SEPARATOR, (string) getenv('PATH')), ['/usr/local/bin', '/usr/bin', '/bin']);
$childEnv = getenv();
$childEnv['PATH']                = implode(PATH_SEPARATOR, array_values(array_unique(array_filter($dirs, static fn(string $d): bool => $d !== ''))));
$origHome = (string) getenv('HOME');
$useOwnHome = $origHome === '' || !is_dir($origHome) || !is_writable($origHome);
if ($useOwnHome) {
    $childEnv['HOME'] = $storageDir . '/update-home';
}
$childEnv['COMPOSER_HOME']       = $storageDir . '/composer';
$childEnv['COMPOSER_NO_INTERACTION'] = '1';
$childEnv['npm_config_store_dir'] = $storageDir . '/pnpm-store';
$childEnv['XDG_CACHE_HOME']      = $storageDir . '/update-home/.cache';
$childEnv['XDG_DATA_HOME']       = $storageDir . '/update-home/.local/share';
$childEnv['XDG_STATE_HOME']      = $storageDir . '/update-home/.local/state';
$childEnv['CI']                  = 'true';
foreach (['update-home', 'composer', 'pnpm-store'] as $d) {
    @mkdir($storageDir . '/' . $d, 0775, true);
}

// Zámek — stejný soubor, který kontroluje WebUpdatePreflight.
@mkdir(dirname($lockFile), 0775, true);
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Aktualizace už běží.\n");
    exit(2);
}

$state = [
    'status'      => 'running',
    'from'        => trim((string) @file_get_contents($root . '/VERSION')),
    'target'      => $target,
    'step'        => null,
    'started_at'  => date(DATE_ATOM),
    'finished_at' => null,
    'error_step'  => null,
    'pid'         => getmypid(),
];
$setStep = static function (string $step) use (&$state, $stateFile, $maintFile, $target): void {
    $state['step'] = $step;
    writeJson($stateFile, $state);
    if (is_file($maintFile)) {
        writeMaintenance($maintFile, $target, $step);
    }
};

function writeMaintenance(string $file, string $target, string $step): void
{
    $existing = json_decode((string) @file_get_contents($file), true);
    writeJson($file, [
        'reason'     => 'update',
        'product'    => 'MyPortfolio',
        'target'     => $target,
        'step'       => $step,
        'started_at' => is_array($existing) && isset($existing['started_at']) ? $existing['started_at'] : date(DATE_ATOM),
        'expires_at' => date(DATE_ATOM, time() + MAINTENANCE_TTL),
    ]);
}

$failedStep = null;
try {
    logLine($logFile, "=== Aktualizace {$state['from']} -> {$target} ===");
    logLine($logFile, 'HOME=' . $childEnv['HOME'] . ' PATH=' . $childEnv['PATH']);
    foreach ($plan as [$step, $cmd]) {
        $setStep($step);
        logLine($logFile, "--- krok: {$step}");

        if ($step === 'maintenance') {
            writeMaintenance($maintFile, $target, $step);
            continue;
        }
        if ($step === 'verify') {
            $actual = trim((string) @file_get_contents($root . '/VERSION'));
            if ($actual !== $target) {
                logLine($logFile, "VERSION = '{$actual}', očekáváno '{$target}'");
                $failedStep = $step;
                break;
            }
            continue;
        }

        logLine($logFile, '$ ' . $cmd);
        $log = @fopen($logFile, 'a');
        if ($log === false) {
            fwrite(STDERR, "Nelze zapisovat do logu {$logFile}.\n");
            $failedStep = $step;
            break;
        }
        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log],
            $pipes,
            $root,
            $childEnv
        );
        $code = is_resource($proc) ? proc_close($proc) : 127;
        fclose($log);
        if ($code !== 0) {
            logLine($logFile, "Příkaz skončil s kódem {$code}");
            $failedStep = $step;
            break;
        }
    }
} catch (Throwable $e) {
    logLine($logFile, 'Výjimka: ' . $e->getMessage());
    $failedStep = $state['step'] ?? 'unknown';
} finally {
    @unlink($maintFile);
    $state['finished_at'] = date(DATE_ATOM);
    if ($failedStep === null) {
        $state['status'] = 'succeeded';
        $state['step']   = null;
    } else {
        $state['status']     = 'failed';
        $state['error_step'] = $failedStep;
    }
    unset($state['pid']);
    writeJson($stateFile, $state);
    logLine($logFile, '=== ' . $state['status'] . ' ===');
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($failedStep === null ? 0 : 1);
