<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;

/**
 * AES-256-GCM šifrování citlivých secretů (TOTP secret, klíče burz, hesla IMAP a SMTP).
 *
 * Formát: `enc:v2:{kid}:{base64(nonce|ciphertext|tag)}`. `kid` je otisk klíče — dešifruje se klíčem,
 * kterým se šifrovalo (aktuální `app.secret_encryption_key` nebo některý z `app.secret_encryption_previous_keys`).
 * Kontext (`tabulka.sloupec:user_id`, viz context()) jde do GCM jako AAD — zašifrovaná hodnota přenesená
 * do jiného sloupce nebo k jinému uživateli se nedešifruje.
 *
 * Starší formát `enc:v1:` (bez kid a AAD) a nešifrovaný text čte jen převod (SecretRotator) — běžné
 * decrypt() je odmítne. Klíč odvozený z `app.pepper` (HKDF) slouží jen ke čtení starých dat;
 * šifrovat s ním jde jen mimo produkci.
 */
final class SecretEncryption
{
    private const PREFIX = 'enc:v2:';
    private const LEGACY_PREFIX = 'enc:v1:';
    private const NONCE_LEN = 12; // GCM standard
    private const TAG_LEN = 16;

    public function __construct(private readonly Config $config) {}

    /** Kontext pro AAD: sloupec (`tabulka.sloupec`) a vlastník řádku; globální nastavení bez uživatele. */
    public static function context(string $column, ?int $userId = null): string
    {
        return $userId === null ? $column : "{$column}:{$userId}";
    }

    public function encrypt(string $plaintext, string $context): string
    {
        [$kid, $key] = $this->currentKey();
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, self::TAG_LEN);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::PREFIX . $kid . ':' . base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $stored, string $context): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException('Hodnota není v aktuálním šifrovaném formátu — spusť php api/bin/migrate.php (převede ji).');
        }
        $rest = substr($stored, strlen(self::PREFIX));
        $sep = strpos($rest, ':');
        $key = $sep !== false ? ($this->keys()[substr($rest, 0, $sep)] ?? null) : null;
        if ($key === null) {
            throw new \RuntimeException('Neznámý šifrovací klíč — chybí v app.secret_encryption_previous_keys?');
        }
        return self::open($key, substr($rest, $sep + 1), $context)
            ?? throw new \RuntimeException('Decryption failed');
    }

    /**
     * Pro převod (SecretRotator): přečte i `enc:v1:` a nešifrovaný text.
     * Vrací [plaintext, je potřeba převést na aktuální klíč a formát].
     *
     * @return array{0:string,1:bool}
     */
    public function decryptForRotation(string $stored, string $context): array
    {
        if (str_starts_with($stored, self::PREFIX)) {
            return [$this->decrypt($stored, $context), !str_starts_with($stored, self::PREFIX . $this->currentKey()[0] . ':')];
        }
        if (!str_starts_with($stored, self::LEGACY_PREFIX)) {
            return [$stored, true];
        }
        foreach ($this->keys() as $key) {
            $plain = self::open($key, substr($stored, strlen(self::LEGACY_PREFIX)), '');
            if ($plain !== null) {
                return [$plain, true];
            }
        }
        throw new \RuntimeException('Hodnotu enc:v1 nejde dešifrovat žádným známým klíčem.');
    }

    /**
     * Validace konfigurace klíče pro admin health warning.
     * null = OK (dedikovaný key), string = warning/error v CZ.
     */
    public function validateKey(): ?string
    {
        $b64 = (string) $this->config->get('app.secret_encryption_key', '');
        if ($b64 !== '') {
            if (self::decodeKey($b64) === null) {
                return 'cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.';
            }
            foreach ((array) $this->config->get('app.secret_encryption_previous_keys', []) as $old) {
                if (self::decodeKey((string) $old) === null) {
                    return 'cfg.app.secret_encryption_previous_keys obsahuje neplatný klíč (base64, 32B).';
                }
            }
            return null;
        }

        $pepper = (string) $this->config->get('app.pepper', '');
        if ($pepper === '') {
            return 'Ani secret_encryption_key, ani app.pepper nejsou nastaveny.';
        }
        if ($this->isProduction()) {
            return 'secret_encryption_key chybí — v produkci nejde ukládat tajemství (klíče burz, hesla, TOTP). '
                . 'Doplň klíč (openssl rand -base64 32) a spusť php api/bin/migrate.php.';
        }
        return 'secret_encryption_key chybí, používá se HKDF fallback z app.pepper (méně bezpečné).';
    }

    /** @return array{0:string,1:string} [kid, klíč] pro nové šifrování */
    private function currentKey(): array
    {
        $b64 = (string) $this->config->get('app.secret_encryption_key', '');
        if ($b64 !== '') {
            $key = self::decodeKey($b64)
                ?? throw new \RuntimeException('cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.');
            return [self::kid($key), $key];
        }
        if ($this->isProduction()) {
            throw new \RuntimeException('cfg.app.secret_encryption_key chybí — v produkci se tajemství z pepperu nešifrují.');
        }
        $key = $this->pepperKey() ?? throw new \RuntimeException('Ani secret_encryption_key, ani app.pepper nejsou nastaveny.');
        return [self::kid($key), $key];
    }

    /** @return array<string,string> kid => klíč; všechny klíče, kterými jde dešifrovat */
    private function keys(): array
    {
        $keys = [];
        $current = self::decodeKey((string) $this->config->get('app.secret_encryption_key', ''));
        foreach ([$current, ...array_map(static fn ($k) => self::decodeKey((string) $k),
            (array) $this->config->get('app.secret_encryption_previous_keys', [])), $this->pepperKey()] as $key) {
            if ($key !== null) {
                $keys[self::kid($key)] = $key;
            }
        }
        return $keys;
    }

    private function pepperKey(): ?string
    {
        $pepper = (string) $this->config->get('app.pepper', '');
        return $pepper !== '' ? hash_hkdf('sha256', $pepper, 32, 'totp-secret') : null;
    }

    private function isProduction(): bool
    {
        return $this->config->get('app.env', 'production') === 'production';
    }

    private static function open(string $key, string $payload, string $aad): ?string
    {
        $blob = base64_decode($payload, true);
        if ($blob === false || strlen($blob) < self::NONCE_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Invalid ciphertext');
        }
        $pt = openssl_decrypt(substr($blob, self::NONCE_LEN, -self::TAG_LEN), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($blob, 0, self::NONCE_LEN), substr($blob, -self::TAG_LEN), $aad);
        return $pt === false ? null : $pt;
    }

    private static function decodeKey(string $b64): ?string
    {
        $key = $b64 !== '' ? base64_decode($b64, true) : false;
        return $key !== false && strlen($key) === 32 ? $key : null;
    }

    private static function kid(string $key): string
    {
        return substr(hash('sha256', 'myportfolio-secret-key-id:' . $key), 0, 8);
    }
}
