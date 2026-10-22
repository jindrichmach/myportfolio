<?php

declare(strict_types=1);

/**
 * Doplní kurzy ČNB, inflaci ČR a historii cen všech instrumentů v pozicích (a benchmarků) od data (po instalaci, po ukázkových datech).
 * Snapshoty dotčených pozic se označí k přepočtu (dopočítají se při dalším zobrazení historie nebo cronem).
 *
 *   php8.5 api/bin/backfill-market.php --from=2021-01-01
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\Inflation\CpiService;
use MyPortfolio\Service\Price\PriceService;

$opts = getopt('', ['from:']);
if (!isset($opts['from']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $opts['from']) !== 1) {
    fwrite(STDERR, "Použití: php api/bin/backfill-market.php --from=YYYY-MM-DD\n");
    exit(1);
}
$from = new DateTimeImmutable((string) $opts['from']);
$c = Bootstrap::buildApp()->getContainer();

$fx = $c->get(FxService::class);
$sync = $fx->sync($from, $fx->today());
$fill = $fx->fillTransactionRates();
printf("[%s] kurzy ČNB: %s; transakcím doplněno %d kurzů\n", date('Y-m-d H:i:s'),
    json_encode(array_diff_key($sync, ['errors' => 1]), JSON_UNESCAPED_UNICODE), $fill['filled']);
foreach (array_slice($sync['errors'], 0, 5) as $e) {
    echo "  ! $e\n";
}
try {
    $cpi = $c->get(CpiService::class)->sync();
    printf("  inflace ČR: %d měsíců, poslední %s\n", $cpi['fetched'], $cpi['latest'] ?? '—');
} catch (Throwable $e) {
    echo "  ! inflace ČR: {$e->getMessage()}\n";
}
foreach ($c->get(PriceService::class)->backfillUsed($from) as $r) {
    printf("  %-10s +%d%s\n", $r['symbol'], $r['inserted'], $r['errors'] ? '  (' . implode(' | ', $r['errors']) . ')' : '');
}
printf("[%s] hotovo\n", date('Y-m-d H:i:s'));
