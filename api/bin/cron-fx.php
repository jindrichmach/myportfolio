<?php

declare(strict_types=1);

/**
 * Kurzy ČNB: stáhne chybějící pracovní dny od posledního uloženého (nebo od první
 * transakce) po dnešek a doplní `transactions.fx_rate_czk`.
 *
 *   php api/bin/cron-fx.php                    # od posledního uloženého dne
 *   php api/bin/cron-fx.php --from=2021-01-01  # backfill od data
 *
 * Doporučeno denně 14:45 (ČNB vyhlašuje kurzy ve 14:30).
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Cron\CronRun;
use MyPortfolio\Service\Fx\FxService;

$c = Bootstrap::buildApp()->getContainer();
$run = CronRun::start($c->get(Connection::class)->pdo(), 'cron-fx');
try {
    $fx = $c->get(FxService::class);
    $opts = getopt('', ['from:']);
    $from = isset($opts['from']) ? new DateTimeImmutable((string) $opts['from']) : $fx->syncStart();
    $sync = $fx->sync($from, $fx->today());
    $fill = $fx->fillTransactionRates();
    $report = ['from' => $from->format('Y-m-d'), ...$sync, 'transactions_filled' => $fill['filled'], 'transactions_missing' => $fill['missing']];
    $report['errors'] = array_slice($report['errors'], 0, 20);
    echo '[' . date('Y-m-d H:i:s') . '] cron-fx: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
    $run->finish($sync['errors'] === [] ? 'ok' : 'error', $report, $sync['errors'][0] ?? null, $sync['errors'] === [] ? 0 : 1);
} catch (Throwable $e) {
    $run->finish('error', null, $e->getMessage(), 1);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
