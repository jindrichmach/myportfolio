<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\PasskeyCredentialRepository;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\BruteForceGuard;
use MyPortfolio\Service\Auth\LoginSessionIssuer;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\SessionAuthContext;
use MyPortfolio\Service\Auth\SessionLockPolicy;
use MyPortfolio\Service\Auth\SessionManager;
use MyPortfolio\Service\Auth\TrustedDeviceService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;

#[AllowMockObjectsWithoutExpectations]
final class LoginSessionIssuerTest extends TestCase
{
    public function testStrongLoginCreatesCookieAndRunsSharedSideEffectsOnce(): void
    {
        $db = $this->createMock(Connection::class);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with([inet_pton('127.0.0.1'), 'PHPUnit', 17])
            ->willReturn(true);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $context = SessionAuthContext::strong(
            'passkey',
            new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
            42,
        );
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::once())
            ->method('create')
            ->with(17, '127.0.0.1', 'PHPUnit', $context)
            ->willReturn([
                'token' => str_repeat('a', 64),
                'csrf_token' => str_repeat('b', 64),
                'expires_at' => 1_774_694_400,
            ]);
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->expects(self::once())
            ->method('recordSuccess')
            ->with('user@example.invalid', '127.0.0.1');
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                'auth.login',
                17,
                'user',
                17,
                ['email' => 'user@example.invalid', 'auth_method' => 'passkey'],
                '127.0.0.1',
                'PHPUnit',
            );
        $trusted = $this->createMock(TrustedDeviceService::class);
        $trusted->expects(self::never())->method('issue');
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable('@1774690800'));
        $config = new Config([
            'session' => [
                'cookie_name' => '__Host-myportfolio_session',
                'cookie_secure' => true,
                'cookie_samesite' => 'Lax',
                'lock_after_minutes' => 15,
            ],
            'auth' => [
                'require_mfa' => true,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(2);

        $issuer = new LoginSessionIssuer(
            $db,
            $sessions,
            $bruteForce,
            $logger,
            $trusted,
            $config,
            $clock,
            $credentials,
            new MfaPolicyService($config),
            new SessionLockPolicy($config),
        );
        $response = $issuer->issue(
            (new ResponseFactory())->createResponse(),
            [
                'id' => 17,
                'email' => 'user@example.invalid',
                'name' => 'Synthetic User',
                'role' => 'admin',
                'locale' => 'cs',
                'totp_enabled' => 0,
                'session_lock_after_minutes' => 5,
            ],
            '127.0.0.1',
            'PHPUnit',
            $context,
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(str_repeat('b', 64), $body['csrf_token']);
        self::assertSame([
            'mfa_enabled' => true,
            'mfa_methods' => ['passkey'],
            'passkey_count' => 2,
            'must_setup_mfa' => false,
        ], array_intersect_key($body['user'], array_flip([
            'mfa_enabled',
            'mfa_methods',
            'passkey_count',
            'must_setup_mfa',
        ])));
        self::assertTrue($body['require_mfa']);
        self::assertSame(['passkey', 'totp'], $body['allowed_mfa_methods']);
        self::assertSame('active', $body['session_state']);
        self::assertSame(5, $body['lock_after_minutes']);
        self::assertSame('2026-03-28T09:40:00.000000Z', $body['server_time']);
        self::assertSame('2026-03-28T09:45:00.000000Z', $body['idle_expires_at']);
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('__Host-myportfolio_session=' . str_repeat('a', 64), $cookie);
        self::assertStringContainsString('HttpOnly; Path=/; Max-Age=3600; SameSite=Lax; Secure', $cookie);
    }
}
