<?php

declare(strict_types=1);

/**
 * Bankovní e-mailová avíza: projde zapnuté IMAP schránky všech uživatelů a zapíše avíza
 * do napojených ručních pozic.
 *
 *   php8.5 api/bin/cron-bank-notices.php
 *   php8.5 api/bin/cron-bank-notices.php --user=<id>
 *
 * Doporučeno každých 15 minut.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\BankNotice\NoticeScanner;
use MyPortfolio\Service\Cron\CronRun;

$c = Bootstrap::buildApp()->getContainer();
$pdo = $c->get(Connection::class)->pdo();
$run = CronRun::start($pdo, 'cron-bank-notices');
try {
    $opts = getopt('', ['user:']);
    $sql = 'SELECT DISTINCT user_id FROM imap_accounts WHERE enabled = 1' . (isset($opts['user']) ? ' AND user_id = ?' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute(isset($opts['user']) ? [(int) $opts['user']] : []);
    $scanner = $c->get(NoticeScanner::class);
    $report = ['accounts' => 0, 'fetched' => 0, 'imported' => 0, 'failed' => 0, 'errors' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        foreach ($scanner->scanUser((int) $userId) as $summary) {
            $report['accounts']++;
            foreach (['fetched', 'imported', 'failed'] as $k) {
                $report[$k] += (int) ($summary[$k] ?? 0);
            }
            if (isset($summary['error'])) {
                $report['errors'][] = "#{$summary['imap_account_id']}: {$summary['error']}";
            }
        }
    }
    echo '[' . date('Y-m-d H:i:s') . '] cron-bank-notices: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
    $report['errors'] === []
        ? $run->finish('ok', $report)
        : $run->finish('error', $report, implode('; ', $report['errors']), 1);
} catch (Throwable $e) {
    $run->finish('error', null, $e->getMessage(), 1);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
