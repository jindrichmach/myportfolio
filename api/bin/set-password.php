<?php

declare(strict_types=1);

/**
 * CLI: nastav heslo libovolnému uživateli (např. po rotaci pepperu).
 *
 * Použití:
 *   php api/bin/set-password.php email@example.com           — heslo načte ze STDIN (interactive prompt, bez echo)
 *   php api/bin/set-password.php email@example.com NoveHeslo — heslo z argv (pozor na escape v shellu!)
 *
 * Heslo musí mít >=12 znaků (PasswordHasher::validate). Hashe se s aktuálním
 * pepperem z cfg.php. Idempotentní — vždy vytvoří nový hash.
 */

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript musí běžet z CLI.\n");
    exit(1);
}

[$_, $email, $password] = array_pad($argv, 3, null);
if (!$email) {
    fwrite(STDERR, "Použití: php api/bin/set-password.php <email> [<heslo>]\n");
    fwrite(STDERR, "  bez druhého argumentu načte heslo ze STDIN bez echo (doporučeno).\n");
    exit(2);
}

if (!$password) {
    // Interactive prompt — vypneme echo pro skutečné heslo bez stop v terminálu
    fwrite(STDERR, "Heslo pro $email (skryté): ");
    $password = readPasswordSilent();
    fwrite(STDERR, "\n");
    if (!$password) {
        fwrite(STDERR, "Heslo nebylo zadáno.\n");
        exit(2);
    }
}

function readPasswordSilent(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        // PowerShell SecureString — vrací plain text přes pipe
        $cmd = 'powershell.exe -NoProfile -Command "$p = Read-Host -AsSecureString; ' .
               '[System.Runtime.InteropServices.Marshal]::PtrToStringAuto(' .
               '[System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $pwd = shell_exec($cmd);
        return rtrim((string) $pwd, "\r\n");
    }
    // Unix: vypnout echo přes stty
    @shell_exec('stty -echo');
    $line = trim((string) fgets(STDIN));
    @shell_exec('stty echo');
    return $line;
}

$app = \MyPortfolio\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyPortfolio\Infrastructure\Database\Connection::class)->pdo();
$hasher = $container->get(\MyPortfolio\Service\Auth\PasswordHasher::class);
$sessions = $container->get(\MyPortfolio\Service\Auth\SessionManager::class);

$stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "User '$email' neexistuje.\n");
    exit(3);
}

try {
    $hash = $hasher->hash($password);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Heslo: " . $e->getMessage() . "\n");
    exit(4);
}

$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([$hash, (int) $user['id']]);

// Invaliduj všechny aktivní sessions v autoritativní DB — force re-login
$killed = $sessions->destroyAllForUser((int) $user['id']);

echo "✓ Heslo nastaveno pro {$user['email']} (id={$user['id']}). Invalidováno $killed session(í).\n";
