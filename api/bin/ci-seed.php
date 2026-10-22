<?php

declare(strict_types=1);

/**
 * CI / test fixture — minimální deterministická data pro `--testsuite Integration`.
 *
 *   php api/bin/ci-seed.php
 *
 * Bez uživatele drží FirstRunLockMiddleware celé API na 423 (setup_required)
 * a HTTP integrační testy by neviděly skutečné chování autentizace. Skript proto
 * vytvoří syntetického admina a běžného uživatele (druhý kvůli testům izolace dat). Není to náhrada `setup.php` — nic se
 * neptá a data jsou syntetická. Určeno pro čerstvě zmigrovanou prázdnou databázi.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\PasswordHasher;

$container = Bootstrap::buildApp()->getContainer();
$pdo = $container->get(Connection::class)->pdo();

if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    fwrite(STDERR, "Databáze už obsahuje uživatele — fixture se nespouští.\n");
    exit(1);
}

$hasher = $container->get(PasswordHasher::class);
$insert = $pdo->prepare(
    'INSERT INTO users (email, password_hash, name, role, locale) VALUES (?, ?, ?, ?, ?)'
);
$insert->execute(['fixture@example.invalid', $hasher->hash('Fixture-password-42'), 'Fixture Admin', 'admin', 'cs']);
$insert->execute(['fixture-user@example.invalid', $hasher->hash('Fixture-password-43'), 'Fixture User', 'user', 'cs']);

echo "✓ Fixture: 1 admin + 1 user.\n";
