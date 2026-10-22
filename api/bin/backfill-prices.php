<?php

declare(strict_types=1);

/**
 * Ruční doplnění historie cen.
 *
 *   php api/bin/backfill-prices.php --all [--from=2021-01-01]
 *   php api/bin/backfill-prices.php --instrument=5 --from=2021-01-01 [--to=2021-12-31]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Service\Price\PriceService;

$opts = getopt('', ['all', 'instrument:', 'from:', 'to:']);
$c = Bootstrap::buildApp()->getContainer();
$prices = $c->get(PriceService::class);
$from = isset($opts['from']) ? new DateTimeImmutable((string) $opts['from']) : null;

if (isset($opts['all'])) {
    foreach ($prices->backfillUsed($from) as $r) {
        printf("%-10s +%d%s\n", $r['symbol'], $r['inserted'], $r['errors'] ? '  (' . implode(' | ', $r['errors']) . ')' : '');
    }
    exit(0);
}

$instrument = isset($opts['instrument']) ? $c->get(InstrumentRepository::class)->find((int) $opts['instrument']) : null;
if ($instrument === null || $from === null) {
    fwrite(STDERR, "Použití: --all [--from=YYYY-MM-DD] | --instrument=ID --from=YYYY-MM-DD [--to=YYYY-MM-DD]\n");
    exit(1);
}
$r = $prices->backfill($instrument, $from, new DateTimeImmutable((string) ($opts['to'] ?? 'today')));
printf("%s: +%d%s\n", $instrument['symbol'], $r['inserted'], $r['errors'] ? '  (' . implode(' | ', $r['errors']) . ')' : '');
