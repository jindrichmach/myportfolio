<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;

/**
 * Bcrypt cost 12 + pepper z cfg.app.pepper.
 *
 * Pepper se přidává jako suffix k heslu před hashováním. Pokud je pepper prázdný,
 * hashujeme jen heslo (devel režim) — v produkci je třeba mít pepper nastavený.
 */
final class PasswordHasher
{
    private const COST = 12;
    private const MIN_LENGTH = 12;
    private const MAX_LENGTH = 128;

    public function __construct(private readonly Config $config) {}

    public function hash(string $plain): string
    {
        $this->validate($plain);
        return password_hash($this->withPepper($plain), PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    public function verify(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }
        return password_verify($this->withPepper($plain), $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    /**
     * Vždy spustit (i pro neexistující email) → konstantní timing proti user enumeration.
     */
    public function dummyVerify(): void
    {
        password_verify('dummy', '$2y$12$' . str_repeat('a', 53));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validate(string $plain): void
    {
        $len = strlen($plain);
        if ($len < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Heslo musí mít alespoň %d znaků.', self::MIN_LENGTH));
        }
        if ($len > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Heslo nesmí být delší než %d znaků.', self::MAX_LENGTH));
        }
    }

    private function withPepper(string $plain): string
    {
        $pepper = (string) $this->config->get('app.pepper', '');
        return $pepper === '' ? $plain : ($plain . $pepper);
    }
}
