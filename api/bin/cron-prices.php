<?php

declare(strict_types=1);

/**
 * Ceny instrumentů v pozicích: aktuální cena (přepíše dnešní) + doplnění chybějící
 * historie od první transakce. Doporučeno každou hodinu.
 *
 *   php api/bin/cron-prices.php
 *   php api/bin/cron-prices.php --no-backfill
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Cron\CronRun;
use MyPortfolio\Service\Price\PriceService;

$c = Bootstrap::buildApp()->getContainer();
$run = CronRun::start($c->get(Connection::class)->pdo(), 'cron-prices');
try {
    $prices = $c->get(PriceService::class);
    $latest = $prices->updateLatest();
    $backfill = in_array('--no-backfill', $argv, true) ? [] : $prices->backfillUsed();

    $failed = array_values(array_filter($latest, static fn (array $r) => $r['status'] === 'failed'));
    $report = [
        'updated'  => count(array_filter($latest, static fn (array $r) => $r['status'] === 'ok')),
        'failed'   => array_map(static fn (array $r) => $r['symbol'] . ': ' . implode(' | ', $r['errors']), $failed),
        'fallback' => array_values(array_map(
            static fn (array $r) => $r['symbol'] . ' ← ' . $r['source'],
            array_filter($latest, static fn (array $r) => $r['status'] === 'ok' && $r['errors'] !== []),
        )),
        'backfill' => array_map(
            static fn (array $r) => $r['symbol'] . ': +' . $r['inserted'] . ($r['errors'] !== [] ? ' (' . implode(' | ', array_map(static fn (string $e) => mb_substr($e, 0, 160), $r['errors'])) . ')' : ''),
            $backfill,
        ),
    ];
    echo '[' . date('Y-m-d H:i:s') . '] cron-prices: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
    $run->finish($failed === [] ? 'ok' : 'error', $report, $report['failed'][0] ?? null, $failed === [] ? 0 : 1);
} catch (Throwable $e) {
    $run->finish('error', null, $e->getMessage(), 1);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
