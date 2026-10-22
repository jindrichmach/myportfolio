<?php

declare(strict_types=1);

/**
 * Index spotřebitelských cen ČR (HICP, Eurostat) pro reálný výnos: stáhne posledních 24 měsíců
 * (revize), prázdná tabulka = celá řada od 1996. Eurostat zveřejňuje index v polovině následujícího měsíce.
 *
 *   php api/bin/cron-cpi.php
 *
 * Doporučeno denně 6:40.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Cron\CronRun;
use MyPortfolio\Service\Inflation\CpiService;

$c = Bootstrap::buildApp()->getContainer();
$run = CronRun::start($c->get(Connection::class)->pdo(), 'cron-cpi');
try {
    $report = $c->get(CpiService::class)->sync();
    echo '[' . date('Y-m-d H:i:s') . '] cron-cpi: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
    $run->finish('ok', $report, null, 0);
} catch (Throwable $e) {
    $run->finish('error', null, $e->getMessage(), 1);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
