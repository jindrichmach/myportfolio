<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;

/**
 * „Zapamatovat toto zařízení" pro druhý faktor (email OTP).
 *
 * Klient drží opaque token v cookie; v DB je jen sha256 hash (`trusted_devices`).
 * Platný token + odpovídající user → druhý faktor se na daném zařízení po dobu
 * `cfg.auth.email_otp.trusted_device_days` přeskakuje. Heslo se vyžaduje vždy.
 *
 * Pozn.: nezeslabuje TOTP — týká se jen email-OTP fallbacku, tedy uživatelů
 * bez authenticator appky.
 */
final class TrustedDeviceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    public function days(): int
    {
        return max(1, (int) $this->config->get('auth.email_otp.trusted_device_days', 30));
    }

    public function cookieName(): string
    {
        // __Host- prefix → vyžaduje Secure + Path=/ + bez Domain. Pro HTTP dev
        // změnit na non-__Host- (stejně jako session cookie).
        return (string) $this->config->get('auth.email_otp.trusted_cookie_name', '__Host-myportfolio_td');
    }

    /**
     * Vystaví nový trusted-device token, uloží jeho hash a vrátí raw token
     * (ten patří do cookie). Lifetime = days().
     */
    public function issue(int $userId, string $ip, string $userAgent): string
    {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expiresAt = (new \DateTimeImmutable('+' . $this->days() . ' days'))->format('Y-m-d H:i:s');

        $this->db->pdo()->prepare(
            'INSERT INTO trusted_devices (user_id, token_hash, expires_at, user_agent, ip)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $hash,
            $expiresAt,
            substr($userAgent, 0, 255),
            @inet_pton($ip) ?: '',
        ]);

        return $raw;
    }

    /**
     * Ověří, že cookie token patří danému uživateli a je platný. Při úspěchu
     * aktualizuje last_used_at. Konstantní porovnání hashů (lookup přes hash).
     */
    public function verify(?string $rawToken, int $userId): bool
    {
        if ($rawToken === null || strlen($rawToken) !== 64 || !ctype_xdigit($rawToken)) {
            return false;
        }
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM trusted_devices
              WHERE token_hash = ? AND user_id = ? AND expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([$hash, $userId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return false;
        }
        $this->db->pdo()->prepare('UPDATE trusted_devices SET last_used_at = NOW() WHERE id = ?')
            ->execute([(int) $id]);
        return true;
    }

    /** Smaže expirovaná zařízení (volá cron-cleanup). */
    public function pruneExpired(): int
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM trusted_devices WHERE expires_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
