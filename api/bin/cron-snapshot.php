<?php

declare(strict_types=1);

/**
 * Denní snapshoty pozic: zpracuje frontu změn (transakce, ocenění, ceny) a přepočítá
 * posledních 7 dní všech uživatelů (dohnané ceny a kurzy).
 *
 *   php8.5 api/bin/cron-snapshot.php
 *   php8.5 api/bin/cron-snapshot.php --days=30
 *
 * Doporučeno denně 00:30.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\SnapshotMaintenanceRepository;
use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Service\Cron\CronRun;
use MyPortfolio\Service\History\SnapshotService;

$c = Bootstrap::buildApp()->getContainer();
$run = CronRun::start($c->get(Connection::class)->pdo(), 'cron-snapshot');
try {
    $opts = getopt('', ['days:']);
    $days = max(0, (int) ($opts['days'] ?? 7));
    $service = $c->get(SnapshotService::class);
    $snapshots = $c->get(SnapshotRepository::class);
    $report = ['users' => 0, 'new_positions' => 0, 'dirty_days' => 0, 'days' => $days];
    foreach ($c->get(SnapshotMaintenanceRepository::class)->users() as $userId) {
        foreach ($snapshots->positionsWithoutSnapshots($userId) as $positionId) {
            $service->recompute($userId, $positionId);
            $report['new_positions']++;
        }
        $report['dirty_days'] += $service->processDirty($userId);
        $service->refreshRecent($userId, $days);
        $report['users']++;
    }
    echo '[' . date('Y-m-d H:i:s') . '] cron-snapshot: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
    $run->finish('ok', $report);
} catch (Throwable $e) {
    $run->finish('error', null, $e->getMessage(), 1);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
