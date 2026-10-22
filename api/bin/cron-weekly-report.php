<?php

declare(strict_types=1);

/**
 * Týdenní e-mailový report: pošle přehled posledních 7 dní všem
 * uživatelům se zapnutým reportem. Posílá při každém spuštění — rozvrh (pondělí 8:00) řeší cron.
 *
 *   php8.5 api/bin/cron-weekly-report.php
 *   php8.5 api/bin/cron-weekly-report.php --user=<id>    # jen jeden uživatel (i když report nemá zapnutý)
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Cron\CronRun;
use MyPortfolio\Service\Report\WeeklyReportService;

$c = Bootstrap::buildApp()->getContainer();
$run = CronRun::start($c->get(Connection::class)->pdo(), 'cron-weekly-report');
try {
    $opts = getopt('', ['user:']);
    $users = isset($opts['user']) ? [(int) $opts['user']] : $c->get(UserPreferenceRepository::class)->weeklyReportUsers();
    $service = $c->get(WeeklyReportService::class);
    $report = ['users' => count($users), 'sent' => 0, 'errors' => []];
    foreach ($users as $userId) {
        try {
            $to = $service->send($userId);
            $report['sent']++;
            echo "  user $userId → " . implode(', ', $to) . "\n";
        } catch (Throwable $e) {
            $report['errors'][] = "user $userId: {$e->getMessage()}";
        }
    }
    echo '[' . date('Y-m-d H:i:s') . '] cron-weekly-report: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
    $report['errors'] === []
        ? $run->finish('ok', $report)
        : $run->finish('error', $report, implode('; ', $report['errors']), 1);
} catch (Throwable $e) {
    $run->finish('error', null, $e->getMessage(), 1);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
