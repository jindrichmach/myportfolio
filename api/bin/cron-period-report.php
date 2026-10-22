<?php

declare(strict_types=1);

/**
 * Měsíční a roční souhrn e-mailem. Bez parametrů pošle souhrn za minulý
 * měsíc uživatelům se zapnutým měsíčním souhrnem a v lednu i souhrn za minulý rok uživatelům s ročním.
 *
 *   php8.5 api/bin/cron-period-report.php
 *   php8.5 api/bin/cron-period-report.php --period=2026-09 [--user=<id>]   # konkrétní měsíc (YYYY-MM) nebo rok (YYYY)
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Cron\CronRun;
use MyPortfolio\Service\Summary\PeriodReportService;

$c = Bootstrap::buildApp()->getContainer();
$run = CronRun::start($c->get(Connection::class)->pdo(), 'cron-period-report');
try {
    $opts = getopt('', ['user:', 'period:']);
    $prefs = $c->get(UserPreferenceRepository::class);
    $today = new DateTimeImmutable('today');
    $jobs = [];  // [období, uživatelé]
    if (isset($opts['period'])) {
        $kind = strlen((string) $opts['period']) === 4 ? 'yearly' : 'monthly';
        $jobs[] = [(string) $opts['period'], isset($opts['user']) ? [(int) $opts['user']] : $prefs->periodReportUsers($kind)];
    } else {
        $jobs[] = [PeriodReportService::previous('monthly', $today), isset($opts['user']) ? [(int) $opts['user']] : $prefs->periodReportUsers('monthly')];
        if ($today->format('n') === '1') {
            $jobs[] = [PeriodReportService::previous('yearly', $today), isset($opts['user']) ? [(int) $opts['user']] : $prefs->periodReportUsers('yearly')];
        }
    }
    $service = $c->get(PeriodReportService::class);
    $report = ['sent' => 0, 'errors' => []];
    foreach ($jobs as [$period, $users]) {
        foreach ($users as $userId) {
            try {
                $to = $service->send($userId, $period);
                $report['sent']++;
                echo "  $period user $userId → " . implode(', ', $to) . "\n";
            } catch (Throwable $e) {
                $report['errors'][] = "$period user $userId: {$e->getMessage()}";
            }
        }
    }
    echo '[' . date('Y-m-d H:i:s') . '] cron-period-report: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
    $report['errors'] === []
        ? $run->finish('ok', $report)
        : $run->finish('error', $report, implode('; ', $report['errors']), 1);
} catch (Throwable $e) {
    $run->finish('error', null, $e->getMessage(), 1);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
