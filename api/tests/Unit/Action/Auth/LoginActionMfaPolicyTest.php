<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Action\Auth;

use MyPortfolio\Action\Auth\LoginAction;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\PasskeyCredentialRepository;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\BruteForceGuard;
use MyPortfolio\Service\Auth\EmailOtpService;
use MyPortfolio\Service\Auth\LoginSessionIssuer;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\PasskeyService;
use MyPortfolio\Service\Auth\PasswordHasher;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Auth\SessionAuthContext;
use MyPortfolio\Service\Auth\TotpService;
use MyPortfolio\Service\Auth\TrustedDeviceService;
use MyPortfolio\Service\Auth\WebAuthnCeremonyStore;
use MyPortfolio\Service\Captcha\TurnstileVerifier;
use MyPortfolio\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `allowed_mfa_methods` řídí, co splní povinné MFA — nikdy nesmí vést k tomu, že
 * se přeskočí faktor, který uživatel reálně má.
 */
#[AllowMockObjectsWithoutExpectations]
final class LoginActionMfaPolicyTest extends TestCase
{
    public function testEnrolledTotpIsStillRequiredWhenMethodIsNotAllowed(): void
    {
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $response = $this->login(
            issuer: $issuer,
            totpEnabled: true,
            allowedMethods: ['passkey'],
            emailOtpEnabled: false,
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('totp_required', $this->errorCode($response));
    }

    public function testEnrolledTotpIsNotDowngradedToEmailOtp(): void
    {
        $emailOtp = $this->createMock(EmailOtpService::class);
        $emailOtp->expects(self::never())->method('issue');
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $response = $this->login(
            issuer: $issuer,
            totpEnabled: true,
            allowedMethods: ['passkey'],
            emailOtpEnabled: true,
            emailOtp: $emailOtp,
        );

        self::assertSame('totp_required', $this->errorCode($response));
    }

    public function testDisallowedTotpVerificationYieldsBasicAssuranceOnly(): void
    {
        $captured = null;
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->expects(self::once())->method('issue')->willReturnCallback(
            static function (
                ResponseInterface $response,
                array $user,
                string $ip,
                string $userAgent,
                SessionAuthContext $context,
            ) use (&$captured): ResponseInterface {
                $captured = $context;
                return $response->withStatus(204);
            }
        );
        $totp = $this->createMock(TotpService::class);
        $totp->method('verify')->willReturn(true);

        $this->login(
            issuer: $issuer,
            totpEnabled: true,
            allowedMethods: ['passkey'],
            emailOtpEnabled: false,
            body: ['totp' => '123456'],
            totpService: $totp,
        );

        self::assertInstanceOf(SessionAuthContext::class, $captured);
        self::assertSame('totp', $captured->authMethod);
        self::assertSame('basic', $captured->assuranceLevel);
    }

    public function testAllowedTotpVerificationYieldsStrongAssurance(): void
    {
        $captured = null;
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->expects(self::once())->method('issue')->willReturnCallback(
            static function (
                ResponseInterface $response,
                array $user,
                string $ip,
                string $userAgent,
                SessionAuthContext $context,
            ) use (&$captured): ResponseInterface {
                $captured = $context;
                return $response->withStatus(204);
            }
        );
        $totp = $this->createMock(TotpService::class);
        $totp->method('verify')->willReturn(true);

        $this->login(
            issuer: $issuer,
            totpEnabled: true,
            allowedMethods: ['passkey', 'totp'],
            emailOtpEnabled: false,
            body: ['totp' => '123456'],
            totpService: $totp,
        );

        self::assertInstanceOf(SessionAuthContext::class, $captured);
        self::assertSame('strong', $captured->assuranceLevel);
    }

    public function testEnrolledPasskeyBlocksPasswordOnlyLoginWhenWebAuthnIsUnavailable(): void
    {
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $response = $this->login(
            issuer: $issuer,
            totpEnabled: false,
            allowedMethods: ['passkey', 'totp'],
            emailOtpEnabled: false,
            passkeysAvailable: false,
            activePasskeys: 1,
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('passkeys_unavailable', $this->errorCode($response));
    }

    public function testEnrolledPasskeyFallsBackToEmailOtpWhenWebAuthnIsUnavailable(): void
    {
        $emailOtp = $this->createMock(EmailOtpService::class);
        $emailOtp->expects(self::once())->method('issue')->willReturn([
            'sent' => true,
            'cooldown_remaining' => 0,
        ]);
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $response = $this->login(
            issuer: $issuer,
            totpEnabled: false,
            allowedMethods: ['passkey', 'totp'],
            emailOtpEnabled: true,
            passkeysAvailable: false,
            activePasskeys: 1,
            emailOtp: $emailOtp,
        );

        self::assertSame('email_otp_required', $this->errorCode($response));
    }

    /**
     * @param list<string> $allowedMethods
     * @param array<string,mixed> $body
     */
    private function login(
        LoginSessionIssuer $issuer,
        bool $totpEnabled,
        array $allowedMethods,
        bool $emailOtpEnabled,
        bool $passkeysAvailable = true,
        int $activePasskeys = 0,
        array $body = [],
        ?EmailOtpService $emailOtp = null,
        ?TotpService $totpService = null,
    ): ResponseInterface {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn([
            'id' => 17,
            'email' => 'user@example.invalid',
            'name' => 'Synthetic User',
            'role' => 'admin',
            'locale' => 'cs',
            'password_hash' => 'synthetic-hash',
            'is_active' => 1,
            'totp_secret' => $totpEnabled ? 'encrypted-secret' : null,
            'totp_enabled' => $totpEnabled ? 1 : 0,
            'session_lock_after_minutes' => null,
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->method('verify')->willReturn(true);
        $hasher->method('needsRehash')->willReturn(false);
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $bruteForce->method('isTotpLocked')->willReturn(false);
        $turnstile = $this->createMock(TurnstileVerifier::class);
        $turnstile->method('verify')->willReturn(true);
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');

        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->method('findAllForUser')->willReturn([]);
        $credentials->method('countActiveForUser')->willReturn($activePasskeys);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('isAvailable')->willReturn($passkeysAvailable);
        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('SECRET');

        $action = new LoginAction(
            $db,
            $hasher,
            $bruteForce,
            $turnstile,
            $this->createMock(ActivityLogger::class),
            $ipMatcher,
            new Config([
                'auth' => [
                    'require_mfa' => false,
                    'allowed_mfa_methods' => $allowedMethods,
                    'email_otp' => ['enabled' => $emailOtpEnabled],
                ],
            ]),
            $totpService ?? $this->createMock(TotpService::class),
            $crypto,
            $emailOtp ?? $this->createMock(EmailOtpService::class),
            $this->createMock(TrustedDeviceService::class),
            $credentials,
            $passkeys,
            $this->createMock(WebAuthnCeremonyStore::class),
            new MfaPolicyService(new Config([
                'auth' => [
                    'require_mfa' => false,
                    'require_totp' => false,
                    'allowed_mfa_methods' => $allowedMethods,
                ],
            ])),
            $issuer,
            $this->createMock(ClockInterface::class),
            $this->createMock(\MyPortfolio\Service\Auth\MfaRecoveryCodeService::class),
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/login')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody([
                'email' => 'user@example.invalid',
                'password' => 'Synthetic-password-42',
            ] + $body);

        return $action($request, (new ResponseFactory())->createResponse());
    }

    private function errorCode(ResponseInterface $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);
        return is_array($body) ? ($body['error']['code'] ?? null) : null;
    }
}
