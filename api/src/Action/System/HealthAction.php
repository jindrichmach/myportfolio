<?php

declare(strict_types=1);

namespace MyPortfolio\Action\System;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Cache\RedisProbe;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\PasskeyService;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Auth\SessionLockPolicy;
use MyPortfolio\Service\Update\VersionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedisProbe $redis,
        private readonly SecretEncryption $crypto,
        private readonly VersionService $version,
        private readonly PasskeyService $passkeys,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly SessionLockPolicy $sessionLockPolicy,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $payload = [
            'status'  => 'ok',
            'version' => $this->version->getCurrentVersion(),
            'db'      => $this->db->ping(),
            'redis'   => $this->redis->isAvailable(),
            'time'    => date(\DateTimeInterface::ATOM),
        ];

        // Diagnostické warningy (např. slabý fallback secret_encryption_key) jen
        // pro přihlášené — anonymnímu volajícímu (Docker healthcheck, monitoring)
        // neprozrazujeme detaily konfigurace.
        if ($request->getAttribute(AuthMiddleware::ATTR_USER) !== null) {
            $warnings = [];
            $keyWarning = $this->crypto->validateKey();
            if ($keyWarning !== null) {
                $warnings[] = [
                    'code' => 'secret_encryption_key',
                    'message' => $keyWarning,
                ];
            }
            if ($this->mfaPolicy->isMethodAllowed('passkey')
                && !$this->passkeys->isAvailable()
            ) {
                $warnings[] = [
                    'code' => 'webauthn_configuration',
                    'message' => $this->passkeys->configurationError()
                        ?? 'Konfigurace WebAuthn není platná.',
                ];
            }
            $mfaWarning = $this->mfaPolicy->configurationWarning();
            if ($mfaWarning !== null) {
                $warnings[] = [
                    'code' => 'mfa_methods_configuration',
                    'message' => $mfaWarning,
                ];
            }
            $lockWarning = $this->sessionLockPolicy->configurationWarning();
            if ($lockWarning !== null) {
                $warnings[] = [
                    'code' => 'session_lock_configuration',
                    'message' => $lockWarning,
                ];
            }
            if ($this->sessionLockPolicy->isEnabled() && $this->hasUserWithoutPasskey()) {
                $warnings[] = [
                    'code' => 'session_lock_without_unlock_method',
                    'message' => 'Automatický zámek session je zapnutý, ale někteří aktivní '
                        . 'uživatelé nemají passkey. Zamčenou session pak lze jen odhlásit '
                        . '— registrujte jim passkey, nebo nastavte session.lock_after_minutes = 0.',
                ];
            }
            $payload['warnings'] = $warnings;
        }

        return Json::ok($response, $payload);
    }

    /**
     * Diagnostika, ne guard — proto tiše ustoupí, když tabulka passkeys ještě
     * neexistuje (health musí odpovědět i před doběhnutím migrací).
     */
    private function hasUserWithoutPasskey(): bool
    {
        try {
            $statement = $this->db->pdo()->query(
                'SELECT EXISTS (
                    SELECT 1
                      FROM users u
                     WHERE u.is_active = 1
                       AND NOT EXISTS (
                             SELECT 1
                               FROM webauthn_credentials c
                              WHERE c.user_id = u.id
                                AND c.revoked_at IS NULL
                           )
                 )'
            );
            if ($statement === false) {
                return false;
            }
            return (int) $statement->fetchColumn() === 1;
        } catch (\PDOException) {
            return false;
        }
    }
}
