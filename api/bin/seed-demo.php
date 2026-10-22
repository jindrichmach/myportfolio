<?php

declare(strict_types=1);

/**
 * Ukázková data pro proklikání UI (DemoDataSeeder, ~5 let historie relativně k dnešku) — jen do prázdného účtu.
 *
 *   php8.5 api/bin/seed-demo.php --user=admin@example.com [--backfill]   # založí data (+ stáhne kurzy a ceny)
 *   php8.5 api/bin/seed-demo.php --user=admin@example.com --reset        # jen app.env = development: smaže doménová data a založí znovu
 *   php8.5 api/bin/seed-demo.php --user=admin@example.com --no-partner   # bez demo partnera a sdílení
 *
 * Vedle dat uživatele založí demo partnera `partner.<jméno>@example.invalid` s náhodným heslem (vypíše ho)
 * a vzájemné sdílení — ukázka sdílení a Domácnosti.
 *
 * --reset maže účty, portfolia, pozice, transakce, loty, ruční ocenění, cenové hranice, cíle alokace, čisté jmění,
 * napojení burz a sdílení uživatele, demo partnera a instituce/instrumenty, které sám založil a nikdo je nepoužívá.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Demo\DemoDataSeeder;

$opts = getopt('', ['user:', 'reset', 'backfill', 'no-partner']);
$email = (string) ($opts['user'] ?? '');
if ($email === '') {
    fwrite(STDERR, "Použití: php api/bin/seed-demo.php --user=<email> [--backfill] [--reset] [--no-partner]\n");
    exit(1);
}

$c = Bootstrap::buildApp()->getContainer();
if (isset($opts['reset']) && $c->get(Config::class)->get('app.env') !== 'development') {
    fwrite(STDERR, "--reset jen pro app.env = development.\n");
    exit(1);
}
$pdo = $c->get(Connection::class)->pdo();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$userId = (int) $stmt->fetchColumn();
if ($userId === 0) {
    fwrite(STDERR, "Uživatel {$email} neexistuje.\n");
    exit(1);
}

$partnerEmail = 'partner.' . preg_replace('/[^a-z0-9._-]/', '', strtolower(strstr($email, '@', true) ?: 'demo')) . '@example.invalid';
$stmt->execute([$partnerEmail]);
$partnerId = (int) $stmt->fetchColumn();

if (isset($opts['reset'])) {
    $wipe = static function (int $id) use ($pdo): void {
        $pdo->prepare('DELETE FROM shares WHERE owner_user_id = ? OR grantee_user_id = ?')->execute([$id, $id]);
        foreach (['price_alerts', 'alert_log', 'allocation_targets', 'net_worth_entries', 'net_worth_items', 'exchange_connections', 'import_previews',
            'daily_snapshots', 'snapshot_dirty', 'tax_lots', 'manual_valuations', 'transactions', 'import_batches', 'positions', 'portfolios', 'accounts'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE user_id = ?")->execute([$id]);
        }
        $pdo->prepare('UPDATE users SET benchmark_instrument_id = NULL WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM instruments WHERE created_by_user_id = ? AND id NOT IN (SELECT instrument_id FROM positions WHERE instrument_id IS NOT NULL)')->execute([$id]);
        $pdo->prepare('DELETE FROM institutions WHERE created_by_user_id = ? AND id NOT IN (SELECT institution_id FROM accounts)')->execute([$id]);
    };
    $wipe($userId);
    $hadPartner = $partnerId !== 0;
    if ($hadPartner) {
        $wipe($partnerId);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$partnerId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$partnerId]);
        $partnerId = 0;
    }
    echo "Smazána doménová data uživatele {$email}" . ($hadPartner ? ' a demo partnera' : '') . ".\n";
}

try {
    $r = $c->get(DemoDataSeeder::class)->seed($userId);
} catch (DomainError $e) {
    fwrite(STDERR, "Chyba: {$e->getMessage()} " . json_encode($e->extra, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
printf("✓ Demo data pro %s: %d institucí, %d instrumentů, %d účtů, %d portfolia, %d pozic, %d transakcí.\n",
    $email, $r['institutions'], $r['instruments'], $r['accounts'], $r['portfolios'], $r['positions'], $r['transactions']);
if (!isset($opts['no-partner'])) {
    if ($partnerId !== 0) {
        echo "Demo partner {$partnerEmail} už existuje — sdílení nezakládám (pro nové spusť s --reset).\n";
    } else {
        $p = $c->get(DemoDataSeeder::class)->seedPartner($userId, $partnerEmail);
        echo "✓ Demo partner: {$p['email']} / heslo {$p['password']} — sdílí s tebou portfolio (zápis), ty jemu vše (čtení).\n";
    }
}
if (isset($opts['backfill'])) {
    echo "Stahuji kurzy ČNB a ceny od {$r['from']} (může trvat několik minut)…\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/backfill-market.php') . ' --from=' . escapeshellarg($r['from']));
} else {
    echo "Kurzy a ceny doplníš: php8.5 api/bin/backfill-market.php --from={$r['from']}\n";
}
