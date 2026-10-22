<?php

declare(strict_types=1);

/**
 * Synchronizace transakcí přes API burz — všechna zapnutá napojení.
 * Pohyby bez potřeby rozhodnutí se zapíšou rovnou; jinak napojení čeká na potvrzení náhledu v aplikaci.
 *
 *   php8.5 api/bin/cron-exchange-sync.php
 *   php8.5 api/bin/cron-exchange-sync.php --user=<id> [--connection=<id>]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\ExchangeConnectionRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Cron\CronRun;
use MyPortfolio\Service\Exchange\ExchangeSyncService;

$c = Bootstrap::buildApp()->getContainer();
$run = CronRun::start($c->get(Connection::class)->pdo(), 'cron-exchange-sync');
try {
    $opts = getopt('', ['user:', 'connection:']);
    $users = isset($opts['user']) ? [(int) $opts['user']] : $c->get(UserPreferenceRepository::class)->exchangeSyncUsers();
    $repo = $c->get(ExchangeConnectionRepository::class);
    $service = $c->get(ExchangeSyncService::class);
    $report = ['connections' => 0, 'imported' => 0, 'pending' => 0, 'errors' => []];
    foreach ($users as $userId) {
        $ids = isset($opts['connection']) ? [(int) $opts['connection']] : $repo->enabledIds($userId);
        foreach ($ids as $id) {
            try {
                $r = $service->sync($userId, $id);
                $report['connections']++;
                $report['imported'] += $r['imported'];
                $report['pending'] += $r['status'] === 'pending' ? 1 : 0;
                if ($r['status'] === 'error') {
                    $report['errors'][] = "user $userId / #$id: {$r['message']}";
                }
                echo "  user $userId #$id → {$r['status']}: {$r['message']}\n";
            } catch (Throwable $e) {
                $report['errors'][] = "user $userId / #$id: {$e->getMessage()}";
            }
        }
    }
    echo '[' . date('Y-m-d H:i:s') . '] cron-exchange-sync: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
    $report['errors'] === []
        ? $run->finish('ok', $report)
        : $run->finish('error', $report, implode('; ', array_slice($report['errors'], 0, 5)), 1);
} catch (Throwable $e) {
    $run->finish('error', null, $e->getMessage(), 1);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
