<?php

declare(strict_types=1);

/**
 * Přešifruje uložená tajemství (TOTP secrety, klíče burz, hesla IMAP a SMTP) aktuálním
 * app.secret_encryption_key. Rotace klíče:
 *   1. přesuň dosavadní klíč do app.secret_encryption_previous_keys (seznam base64 klíčů),
 *   2. do app.secret_encryption_key dej nový (openssl rand -base64 32),
 *   3. spusť tento skript, 4. po úspěchu starý klíč z previous_keys odeber.
 *
 *   php8.5 api/bin/rotate-secret-key.php            # přešifruje
 *   php8.5 api/bin/rotate-secret-key.php --dry-run  # jen vypíše, co by přešifroval
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Auth\SecretRotator;

$dryRun = in_array('--dry-run', $_SERVER['argv'], true);
$c = Bootstrap::buildApp()->getContainer();
if (($problem = $c->get(SecretEncryption::class)->validateKey()) !== null) {
    fwrite(STDERR, "Konfigurace klíče: {$problem}\n");
}

$failed = false;
foreach ($c->get(SecretRotator::class)->rotate($dryRun) as $column => $r) {
    printf("%-40s celkem %d, %s %d%s\n", $column, $r['total'], $dryRun ? 'k přešifrování' : 'přešifrováno', $r['rotated'],
        $r['failed'] !== [] ? ', NEJDE dešifrovat id ' . implode(', ', $r['failed']) : '');
    $failed = $failed || $r['failed'] !== [];
}
if ($failed) {
    fwrite(STDERR, "Některé hodnoty nejdou dešifrovat žádným klíčem — chybí starý klíč v app.secret_encryption_previous_keys?\n");
    exit(1);
}
echo $dryRun ? "Hotovo (nic nezměněno).\n" : "Hotovo.\n";
