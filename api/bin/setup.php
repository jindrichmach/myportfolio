<?php

declare(strict_types=1);

/**
 * SETUP — interaktivní úvodní zřízení (CLI):
 *   1. Zkontroluje cfg.php (instrukce pro kopii z cfg.sample.php pokud chybí)
 *   2. Otestuje DB připojení (instrukce pokud nelze)
 *   3. Spustí pending migrace (db/migrations/*.sql)
 *   4. Zeptá se na admin email + jméno + heslo
 *   5. Uloží do DB
 *
 * Spustit:  php api/bin/setup.php
 *
 * Doporučené pořadí (fresh install):
 *   1. cp cfg.sample.php cfg.php  +  vyplň db/smtp/pepper
 *   2. php api/bin/setup.php       # tento skript
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$rootDir = dirname(__DIR__, 2);

// === Krok 1: cfg.php ===
$cfgPath    = $rootDir . '/cfg.php';
$samplePath = $rootDir . '/cfg.sample.php';
if (!is_file($cfgPath)) {
    echo "\n❌  cfg.php nenalezen.\n\n";
    echo "Postup:\n";
    echo "  1. cp cfg.sample.php cfg.php\n";
    echo "  2. Otevři cfg.php a vyplň hodnoty označené CHANGE-ME:\n";
    echo "     - app.url       (https://tvoje-domena.cz)\n";
    echo "     - app.pepper    (32B base64: openssl rand -base64 32)\n";
    echo "     - db.host / db.name / db.user / db.pass\n";
    echo "     - smtp.* pro odesílání e-mailů\n";
    echo "     - captcha.* (volitelné)\n";
    echo "  3. Spusť znovu: php api/bin/setup.php\n\n";
    if (!is_file($samplePath)) {
        echo "  POZOR: ani cfg.sample.php neexistuje — repo je poškozené.\n";
    }
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\PasswordHasher;
use MyPortfolio\Service\Auth\WebAuthnConfig;
use MyPortfolio\Service\Config\CfgLocalWriter;

// === Krok 2: načti config ===
try {
    $config = Config::load($rootDir);
} catch (\Throwable $e) {
    echo "\n❌  Chyba při načítání cfg.php: " . $e->getMessage() . "\n";
    exit(1);
}

// === Krok 3: DB připojení ===
echo "\n🔌  Testuji připojení k DB…\n";
try {
    $pdo = (new Connection($config))->pdo();
    echo "    ✓ Připojeno k " . $config->get('db.name') . " @ " . $config->get('db.host') . "\n";
} catch (\Throwable $e) {
    echo "\n❌  Nelze se připojit k DB: " . $e->getMessage() . "\n\n";
    echo "Možné příčiny:\n";
    echo "  - cfg.php má špatné db.host / db.user / db.pass (uprav cfg.php)\n";
    echo "  - MariaDB neběží\n";
    echo "  - Databáze '" . $config->get('db.name') . "' neexistuje. Vytvoř ji:\n";
    echo "      mysql -u root -p -e \"CREATE DATABASE " . $config->get('db.name') . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"\n";
    echo "  - Uživatel nemá práva na DB\n";
    exit(1);
}

// === Krok 4: migrace ===
echo "\n📦  Spouštím migrace…\n";
$out = shell_exec('php ' . escapeshellarg(__DIR__ . '/migrate.php') . ' 2>&1');
echo "    " . trim((string) $out) . "\n";

// === Krok 5: ověř, jestli setup už neproběhl ===
$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
if ($adminCount > 0) {
    echo "\n⚠   Systém už má admin účet ($adminCount).\n";
    echo "    Heslo lze změnit přes: php api/bin/set-password.php\n";
    exit(1);
}

echo "\n================================================\n";
echo "  ADMIN ÚČET\n";
echo "================================================\n";
$adminName  = prompt('Jméno admina', '');
$adminEmail = prompt('Email admina', '');
$adminPass  = promptPassword('Heslo (min. 12 znaků): ');
while (strlen($adminPass) < 12) {
    echo "    ⚠  Heslo musí mít alespoň 12 znaků.\n";
    $adminPass = promptPassword('Heslo: ');
}

echo "\n================================================\n";
echo "  BEZPEČNOST\n";
echo "================================================\n";
echo "  Vynucení vícefaktorového ověření (MFA) pro VŠECHNY uživatele?\n";
echo "  Doporučeno pro produkci. Po loginu uživatel dokončí passkey nebo TOTP.\n";
$requireMfaAns = strtolower(prompt('Vynutit MFA? (ano/NE)', 'ne'));
$requireMfa = in_array($requireMfaAns, ['ano', 'a', 'y', 'yes', 'true'], true);
$allowedMfaMethods = ['passkey', 'totp'];
if ($requireMfa) {
    while (true) {
        $methodsRaw = strtolower(prompt('Povolené metody (passkey,totp)', 'passkey,totp'));
        $allowedMfaMethods = array_values(array_unique(array_filter(array_map('trim', explode(',', $methodsRaw)))));
        if ($allowedMfaMethods !== []
            && array_diff($allowedMfaMethods, ['passkey', 'totp']) === []
        ) {
            if ($allowedMfaMethods === ['passkey']) {
                try {
                    new WebAuthnConfig($config);
                } catch (\InvalidArgumentException $e) {
                    echo "    ⚠  Passkey-only MFA nelze s aktuální app.url použít: {$e->getMessage()}\n";
                    echo "       Uprav app.url, ukonči setup, nebo zvol také TOTP jako dostupnou metodu.\n";
                    continue;
                }
            }
            break;
        }
        echo "    ⚠  Zadej neprázdnou kombinaci: passkey, totp nebo passkey,totp.\n";
    }
}

echo "\n================================================\n";
echo "  Shrnutí:\n";
echo "    Admin:  {$adminName} <{$adminEmail}>\n";
echo "    MFA:    " . ($requireMfa
    ? 'VYNUCENO (' . implode(', ', $allowedMfaMethods) . ')'
    : 'volitelné (per-user)') . "\n";
echo "================================================\n";
$confirm = prompt('Pokračovat? (ANO/ne)', 'ANO');
if ($confirm !== 'ANO') {
    echo "Zrušeno.\n";
    exit(0);
}

// === Zápis do DB ===
$pdo->beginTransaction();
try {
    // POZOR: hash MUSÍ projít přes PasswordHasher (bcrypt cost 12 + pepper z cfg.app.pepper),
    // protože LoginAction ověřuje pomocí PasswordHasher::verify() s pepperem.
    // Pure password_hash() bez pepperu by produkoval hash, který nikdy nematchne při loginu.
    $hasher = new PasswordHasher($config);
    $pdo->prepare(
        'INSERT INTO users (email, password_hash, name, role, locale, is_active)
         VALUES (?, ?, ?, "admin", "cs", 1)'
    )->execute([
        $adminEmail,
        $hasher->hash($adminPass),
        $adminName,
    ]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "\n❌  Setup selhal: " . $e->getMessage() . "\n";
    exit(1);
}

// === Zapiš obecnou MFA politiku a vypni legacy TOTP-only přepínač ===
try {
    CfgLocalWriter::setKeys(CfgLocalWriter::resolveTargetDir($rootDir), [
        'auth.require_mfa' => $requireMfa,
        'auth.allowed_mfa_methods' => $allowedMfaMethods,
        'auth.require_totp' => false,
    ]);
    echo "\n🔒  Nastavení MFA zapsáno do cfg.local.php.\n";
} catch (\Throwable $e) {
    echo "\n⚠   Nepodařilo se zapsat cfg.local.php: " . $e->getMessage() . "\n";
    echo "    Nastav ručně auth.require_mfa a auth.allowed_mfa_methods.\n";
}

echo "\n✅  Hotovo.\n";
echo "    Přihlas se na " . $config->get('app.url', '/') . "/login\n";
echo "    Email: $adminEmail\n";
if ($requireMfa) {
    echo "    Po přihlášení budeš přesměrován na /setup-mfa pro aktivaci MFA.\n";
}
echo "\n";

// === Helpers ===

function prompt(string $label, string $default = ''): string
{
    if ($default !== '') echo "  $label [$default]: ";
    else echo "  $label: ";
    $line = trim((string) fgets(STDIN));
    return $line !== '' ? $line : $default;
}

function promptPassword(string $label): string
{
    echo "  $label";
    if (DIRECTORY_SEPARATOR === '/') {
        // POSIX: vypni echo
        @system('stty -echo');
        $pass = trim((string) fgets(STDIN));
        @system('stty echo');
        echo "\n";
        return $pass;
    }
    // Windows: bez echo-disable, jen čteme
    return trim((string) fgets(STDIN));
}
