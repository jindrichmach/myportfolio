<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Repository\PasskeyCredentialRepository;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\SessionLockPolicy;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MeAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly SessionLockPolicy $lockPolicy,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);

        $totpEnabled  = (bool) ($user['totp_enabled'] ?? false);
        $requireTotp  = (bool) $this->config->get('auth.require_totp', false);
        $mustSetupTotp = $requireTotp && !$totpEnabled;
        $userId = (int) ($user['id'] ?? 0);
        $displayCurrency = 'CZK';
        $colorScheme = null;
        if ($userId > 0) {
            $stmt = $this->db->pdo()->prepare('SELECT display_currency, color_scheme FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $prefs = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $displayCurrency = (string) (($prefs['display_currency'] ?? '') ?: 'CZK');
            $colorScheme = $prefs['color_scheme'] ?? null;
        }
        $passkeyCount = $userId > 0 ? $this->credentials->countActiveForUser($userId) : 0;
        $mfaMethods = [];
        if ($passkeyCount > 0) {
            $mfaMethods[] = 'passkey';
        }
        if ($totpEnabled) {
            $mfaMethods[] = 'totp';
        }
        $assurance = (string) ($session['assurance_level'] ?? 'legacy');
        $mustSetupMfa = $assurance === 'setup';
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $userTimeout = ($user['session_lock_after_minutes'] ?? null) !== null
            ? (int) $user['session_lock_after_minutes']
            : null;
        $effectiveTimeout = $this->lockPolicy->effectiveTimeoutMinutes($userTimeout);
        $idleExpiresAt = null;
        if ($assurance !== 'setup' && $effectiveTimeout > 0) {
            // Autoritou zámku je SessionLockMiddleware; /me jen reportuje. Chybějící
            // nebo pokažený čas aktivity proto nesmí shodit celý profil na 500 —
            // klient si deadline dotáhne z /api/auth/session/status.
            $lastActivity = self::tryParseUtc((string) ($session['last_user_activity_at'] ?? ''));
            $idleExpiresAt = $lastActivity !== null
                ? self::isoUtc($lastActivity->modify(sprintf('+%d minutes', $effectiveTimeout)))
                : null;
        }

        return Json::ok($response, [
            'user' => [
                'id'              => $userId,
                'email'           => $user['email'] ?? '',
                'name'            => $user['name'] ?? '',
                'role'            => $user['role'] ?? 'user',
                'locale'          => $user['locale'] ?? 'cs',
                'display_currency' => $displayCurrency,
                'color_scheme'    => $colorScheme,
                'totp_enabled'    => $totpEnabled,
                'must_setup_totp' => $mustSetupTotp,
                'mfa_enabled'     => $mfaMethods !== [],
                'mfa_methods'     => $mfaMethods,
                'passkey_count'   => $passkeyCount,
                'must_setup_mfa'  => $mustSetupMfa,
            ],
            'csrf_token'          => $session['csrf_token'] ?? '',
            'require_totp'        => $requireTotp,
            'require_mfa'         => $this->mfaPolicy->isRequired(),
            'allowed_mfa_methods' => $this->mfaPolicy->allowedMethods(),
            'session_state'       => 'active',
            'lock_after_minutes'  => $effectiveTimeout,
            'server_time'         => self::isoUtc($now),
            'idle_expires_at'     => $idleExpiresAt,
        ]);
    }

    private static function tryParseUtc(string $time): ?\DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $time, new \DateTimeZone('UTC'));
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return null;
    }

    private static function isoUtc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
