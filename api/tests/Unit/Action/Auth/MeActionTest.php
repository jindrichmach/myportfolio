<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Action\Auth;

use MyPortfolio\Action\Auth\MeAction;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Repository\PasskeyCredentialRepository;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\SessionLockPolicy;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class MeActionTest extends TestCase
{
    public function testResponseContainsMfaAndAuthoritativeSessionState(): void
    {
        $currencyStatement = $this->createMock(\PDOStatement::class);
        $currencyStatement->expects(self::once())->method('execute')->with([17])->willReturn(true);
        $currencyStatement->expects(self::once())->method('fetch')->willReturn(['display_currency' => 'EUR', 'color_scheme' => 'ocean']);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->with('SELECT display_currency, color_scheme FROM users WHERE id = ?')
            ->willReturn($currencyStatement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(1);
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $config = new Config([
            'session' => ['lock_after_minutes' => 15],
            'auth' => [
                'require_mfa' => true,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]);

        $action = new MeAction(
            $db,
            $config,
            $credentials,
            new MfaPolicyService($config),
            new SessionLockPolicy($config),
            $clock,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/me')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'email' => 'synthetic@example.invalid',
                'name' => 'Synthetic User',
                'role' => 'admin',
                'locale' => 'cs',
                'totp_enabled' => true,
                'session_lock_after_minutes' => 5,
            ])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'strong',
                'last_user_activity_at' => '2026-07-24 11:58:00.000000',
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['passkey', 'totp'], $body['user']['mfa_methods']);
        self::assertTrue($body['user']['mfa_enabled']);
        self::assertSame(1, $body['user']['passkey_count']);
        self::assertSame('EUR', $body['user']['display_currency']);
        self::assertSame('ocean', $body['user']['color_scheme']);
        self::assertFalse($body['user']['must_setup_mfa']);
        self::assertTrue($body['require_mfa']);
        self::assertSame(['passkey', 'totp'], $body['allowed_mfa_methods']);
        self::assertSame('active', $body['session_state']);
        self::assertSame(5, $body['lock_after_minutes']);
        self::assertSame('2026-07-24T12:00:00.000000Z', $body['server_time']);
        self::assertSame('2026-07-24T12:03:00.000000Z', $body['idle_expires_at']);
        self::assertSame(str_repeat('b', 64), $body['csrf_token']);
    }
}
