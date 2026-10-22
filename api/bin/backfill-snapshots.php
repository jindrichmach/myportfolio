<?php

declare(strict_types=1);

/**
 * Přepočítá denní snapshoty z transakcí, cen a kurzů (celá historie nebo od data).
 * Běžně to není potřeba — změny se dopočítávají samy; slouží po importu nebo
 * doplnění historických cen / kurzů.
 *
 *   php8.5 api/bin/backfill-snapshots.php                         # všichni uživatelé
 *   php8.5 api/bin/backfill-snapshots.php --user=<id> [--from=YYYY-MM-DD]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Repository\SnapshotMaintenanceRepository;
use MyPortfolio\Service\History\SnapshotService;

$opts = getopt('', ['user:', 'from:']);
$c = Bootstrap::buildApp()->getContainer();
$service = $c->get(SnapshotService::class);
$from = isset($opts['from']) ? (new DateTimeImmutable((string) $opts['from']))->format('Y-m-d') : null;

$total = 0;
$started = microtime(true);
foreach ($c->get(SnapshotMaintenanceRepository::class)->users(isset($opts['user']) ? (int) $opts['user'] : null) as $userId) {
    $days = $service->backfill($userId, $from);
    printf("  user %d: %d dní × pozic\n", $userId, $days);
    $total += $days;
}
printf("✓ Snapshoty přepočítány: %d řádků za %.1f s.\n", $total, microtime(true) - $started);
