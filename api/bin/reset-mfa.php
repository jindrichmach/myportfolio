<?php

declare(strict_types=1);

/**
 * CLI: nouzově resetuje všechny MFA faktory uživatele podle e-mailu.
 *
 * Vypne TOTP, odvolá passkeys, zruší trusted devices, login OTP, rozpracované
 * WebAuthn ceremonies a step-up proofy. Invaliduje všechny session uživatele.
 *
 * Použití:
 *   php api/bin/reset-mfa.php admin@example.com
 */

require __DIR__ . '/../vendor/autoload.php';

/**
 * Kdo skript spustil — do auditu. Na Windows je to USERNAME, na unixu USER/LOGNAME;
 * `posix_geteuid()` je spolehlivější (ENV lze podvrhnout), ale rozšíření nemusí být.
 */
function cliOperator(): ?string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && ($pw['name'] ?? '') !== '') {
            return (string) $pw['name'];
        }
    }
    foreach (['USER', 'LOGNAME', 'USERNAME'] as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    return null;
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript musí běžet z CLI.\n");
    exit(1);
}

[$_, $email] = array_pad($argv, 2, null);
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Použití: php api/bin/reset-mfa.php <email>\n");
    exit(2);
}

$app = \MyPortfolio\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyPortfolio\Infrastructure\Database\Connection::class)->pdo();
$sessions = $container->get(\MyPortfolio\Service\Auth\SessionManager::class);

$stmt = $pdo->prepare('SELECT id, email, totp_enabled FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "User '$email' neexistuje.\n");
    exit(3);
}

$pdo->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?')
    ->execute([(int) $user['id']]);

// E-mailové 2FA: zruš důvěryhodná zařízení (vynutí znovuověření) a čekající kódy.
$td = $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?');
$td->execute([(int) $user['id']]);
$otp = $pdo->prepare('DELETE FROM login_otps WHERE user_id = ?');
$otp->execute([(int) $user['id']]);
$passkeys = $pdo->prepare(
    'UPDATE webauthn_credentials
        SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6))
      WHERE user_id = ? AND revoked_at IS NULL'
);
$passkeys->execute([(int) $user['id']]);
$ceremonies = $pdo->prepare('DELETE FROM webauthn_ceremonies WHERE user_id = ?');
$ceremonies->execute([(int) $user['id']]);
$proofs = $pdo->prepare('DELETE FROM mfa_step_up_proofs WHERE user_id = ?');
$proofs->execute([(int) $user['id']]);

$killed = $sessions->destroyAllForUser((int) $user['id']);
$wasEnabled = ((int) ($user['totp_enabled'] ?? 0) === 1) ? 'ano' : 'ne';

// Přes ActivityLogger, NE syrovým INSERTem: jen tudy se článek zapečetí do
// hash-chainu (§ 33a). Syrový zápis nechával `hash = NULL`, takže nejcitlivější
// administrativní operace v systému ležela mimo tamper-evident řetěz a šla
// beze stopy smazat.
//
// `user_id` je tu OBĚŤ resetu (entita, které se to týká), takže kdo reset spustil
// musí být v payloadu — CLI nemá session ani přihlášeného uživatele.
$container->get(\MyPortfolio\Service\ActivityLogger::class)->log(
    'auth.mfa_reset',
    (int) $user['id'],
    'user',
    (int) $user['id'],
    [
        'passkeys_revoked' => $passkeys->rowCount(),
        'trusted_devices_removed' => $td->rowCount(),
        'sessions_invalidated' => $killed,
        'totp_was_enabled' => ((int) ($user['totp_enabled'] ?? 0) === 1),
        'via' => 'cli:reset-mfa',
        'operator_os_user' => cliOperator(),
        'operator_host' => gethostname() ?: null,
    ],
);

echo "✓ MFA reset pro {$user['email']} (id={$user['id']}, TOTP původně aktivní: {$wasEnabled}).\n";
echo "  Odvoláno {$passkeys->rowCount()} passkeys, {$td->rowCount()} důvěryhodných zařízení, "
    . "{$otp->rowCount()} e-mailových kódů, {$ceremonies->rowCount()} flow, "
    . "{$proofs->rowCount()} proofů a $killed session(í).\n";
