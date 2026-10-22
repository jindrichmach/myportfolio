<?php

declare(strict_types=1);

/**
 * Přepočítá FIFO loty (tax_lots, lot_disposals) z transakcí. Běžně to dělá každá
 * změna transakce sama; skript slouží po migraci, importu nebo opravě dat.
 *
 *   php8.5 api/bin/rebuild-lots.php                  # všichni uživatelé
 *   php8.5 api/bin/rebuild-lots.php --user=<id>      # jeden uživatel
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Portfolio\LotRebuilder;

$opts = getopt('', ['user:']);
$c = Bootstrap::buildApp()->getContainer();
$pdo = $c->get(Connection::class)->pdo();
$rebuilder = $c->get(LotRebuilder::class);

$sql = "SELECT DISTINCT user_id, instrument_id FROM positions WHERE valuation_mode = 'market' AND instrument_id IS NOT NULL";
$params = [];
if (isset($opts['user'])) {
    $sql .= ' AND user_id = ?';
    $params[] = (int) $opts['user'];
}
$stmt = $pdo->prepare($sql . ' ORDER BY user_id, instrument_id');
$stmt->execute($params);

$pairs = $warnings = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pdo->beginTransaction();
    try {
        foreach ($rebuilder->rebuild((int) $row['user_id'], (int) $row['instrument_id']) as $w) {
            echo "  user {$row['user_id']} instrument {$row['instrument_id']}: $w\n";
            $warnings++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "user {$row['user_id']} instrument {$row['instrument_id']}: {$e->getMessage()}\n");
        exit(1);
    }
    $pairs++;
}
printf("✓ Přepočítáno %d kombinací uživatel × instrument, %d upozornění.\n", $pairs, $warnings);
