<?php

declare(strict_types=1);

/**
 * Denní souhrn e-mailových upozornění: cenové hranice, velký denní pohyb,
 * blížící se časový test a problémy s daty. Posílá jen tehdy, když je co hlásit; každé upozornění jednou.
 *
 *   php8.5 api/bin/cron-alerts.php
 *   php8.5 api/bin/cron-alerts.php --user=<id>    # jen jeden uživatel (i když upozornění nemá zapnutá)
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Alert\AlertService;
use MyPortfolio\Service\Cron\CronRun;

$c = Bootstrap::buildApp()->getContainer();
$run = CronRun::start($c->get(Connection::class)->pdo(), 'cron-alerts');
try {
    $opts = getopt('', ['user:']);
    $users = isset($opts['user']) ? [(int) $opts['user']] : $c->get(UserPreferenceRepository::class)->alertUsers();
    $service = $c->get(AlertService::class);
    $report = ['users' => count($users), 'sent' => 0, 'errors' => []];
    foreach ($users as $userId) {
        try {
            $r = $service->run($userId);
            if ($r['sent']) {
                $report['sent']++;
            }
            echo "  user $userId → " . ($r['sent'] ? implode(', ', $r['recipients']) : 'nic k ohlášení') . ' ' . json_encode($r['counts']) . "\n";
        } catch (Throwable $e) {
            $report['errors'][] = "user $userId: {$e->getMessage()}";
        }
    }
    echo '[' . date('Y-m-d H:i:s') . '] cron-alerts: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
    $report['errors'] === []
        ? $run->finish('ok', $report)
        : $run->finish('error', $report, implode('; ', $report['errors']), 1);
} catch (Throwable $e) {
    $run->finish('error', null, $e->getMessage(), 1);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
