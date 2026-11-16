<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Infrastructure\Database\Connection;

/**
 * Ověří TOTP kód uživatele a přijatý časový krok rovnou spotřebuje (`users.totp_last_step`):
 * kód, který už jednou prošel, ani starší kód z okna ±1 kroku podruhé neprojde (RFC 6238 §5.2).
 * Podmíněný UPDATE je atomický — ze dvou souběžných požadavků se stejným kódem projde jen jeden.
 */
final class TotpVerifier
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly Connection $db,
    ) {}

    public function verify(int $userId, string $base32Secret, string $code): bool
    {
        $step = $this->totp->matchStep($base32Secret, $code);
        if ($step === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)'
        );
        $stmt->execute([$step, $userId, $step]);
        return $stmt->rowCount() === 1;
    }
}
