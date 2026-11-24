<?php

declare(strict_types=1);

/**
 * Aktualizace instalace z balíčku vydání (GitHub Release asset myportfolio-X.Y.Z.tar.gz).
 *
 * Stáhne balíček, ověří SHA-256, rozbalí ho a nasadí přes instalaci, spustí migrace
 * a nakonec přepíše VERSION. Detaily pipeline popisuje {@see \MyPortfolio\Service\Update\BundleUpdater}.
 *
 *   php api/bin/native-update.php --target=X.Y.Z
 *   php api/bin/native-update.php --target=X.Y.Z --preflight   # jen kontrola prostředí, nic nemění
 *
 * Průběh je ve storage/update-state.json, plný log v log/update.log.
 * Návratové kódy: 0 = nasazeno, 1 = selhalo, 2 = chyba použití (včetně „už běží“).
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Service\Update\BundleUpdater;

$opts = getopt('', ['target:', 'preflight']);
$target = isset($opts['target']) && is_string($opts['target']) ? trim($opts['target']) : '';
if ($target === '' || preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/D', $target) !== 1) {
    fwrite(STDERR, "Použití: php api/bin/native-update.php --target=X.Y.Z [--preflight]\n");
    exit(2);
}

@set_time_limit(0);
@ignore_user_abort(true);

$rootDir = Bootstrap::rootDir();
$config = Config::load($rootDir);
$updater = new BundleUpdater(
    $rootDir,
    stateDir: RuntimePaths::base(),
    repo: (string) $config->get('update.github_repo', ''),
);

if (array_key_exists('preflight', $opts)) {
    $problems = $updater->preflight();
    fwrite(STDOUT, $problems === [] ? "PREFLIGHT OK\n" : "PREFLIGHT BLOKOVÁN\n");
    foreach ($problems as $p) {
        fwrite(STDOUT, '  [blocker] ' . $p . "\n");
    }
    exit($problems === [] ? 0 : 1);
}

$result = $updater->run($target);
if ($result === 'locked') {
    fwrite(STDERR, "Aktualizace už běží.\n");
    exit(2);
}
fwrite(STDOUT, strtoupper($result) . ': ' . $target . "\n");
exit($result === 'succeeded' ? 0 : 1);
