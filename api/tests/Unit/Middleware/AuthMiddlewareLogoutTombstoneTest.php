<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Middleware;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\Auth\ApiTokenService;
use MyPortfolio\Service\Auth\SessionManager;
use MyPortfolio\Service\IpMatcher;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class AuthMiddlewareLogoutTombstoneTest extends TestCase
{
    public function testActiveSessionUsesCombinedLoadAndThrottledTouch(): void
    {
        $token = str_repeat('a', 64);
        $session = [
            'user_id' => 17,
            'csrf_token' => str_repeat('b', 64),
            'assurance_level' => 'strong',
            'last_seen' => 1_721_822_100,
            'evaluated_at_epoch' => 1_721_822_400,
        ];
        $user = [
            'id' => 17,
            'email' => 'synthetic@example.invalid',
            'name' => 'Synthetic User',
            'role' => 'admin',
            'locale' => 'cs',
            'is_active' => true,
            'totp_enabled' => false,
            'session_lock_after_minutes' => 15,
        ];
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::once())
            ->method('loadAuthenticationContext')
            ->with($token)
            ->willReturn(['session' => $session, 'user' => $user]);
        $sessions->expects(self::never())->method('load');
        $sessions->expects(self::never())->method('loadTombstoneForLogout');
        $sessions->expects(self::never())->method('touch');
        $sessions->expects(self::once())
            ->method('touchIfStale')
            ->with($token, 1_721_822_100, 1_721_822_400);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $middleware = new AuthMiddleware(
            new Config(['session' => ['cookie_name' => '__Host-myportfolio_session']]),
            $sessions,
            $db,
            new ResponseFactory(),
            $this->createMock(ApiTokenService::class),
            $this->createMock(IpMatcher::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/invoices')
            ->withCookieParams(['__Host-myportfolio_session' => $token]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(
                static function (ServerRequestInterface $request) use ($session, $user): bool {
                    self::assertSame($session, $request->getAttribute(AuthMiddleware::ATTR_SESSION));
                    self::assertSame($user, $request->getAttribute(AuthMiddleware::ATTR_USER));
                    self::assertSame('session', $request->getAttribute(AuthMiddleware::ATTR_METHOD));
                    return true;
                },
            ))
            ->willReturn((new ResponseFactory())->createResponse(204));

        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
    }

    public function testPostLogoutLoadsTombstoneWithoutTouchingIt(): void
    {
        $token = str_repeat('a', 64);
        $session = [
            'user_id' => 17,
            'csrf_token' => str_repeat('b', 64),
            'assurance_level' => 'strong',
        ];
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::once())
            ->method('loadAuthenticationContext')
            ->with($token)
            ->willReturn(null);
        $sessions->expects(self::once())
            ->method('loadTombstoneForLogout')
            ->with($token)
            ->willReturn($session);
        $sessions->expects(self::never())->method('touch');
        $sessions->expects(self::never())->method('touchIfStale');

        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([17]);
        $statement->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'id' => 17,
            'email' => 'synthetic@example.invalid',
            'name' => 'Synthetic User',
            'role' => 'admin',
            'locale' => 'cs',
            'is_active' => 1,
            'totp_enabled' => 0,
            'session_lock_after_minutes' => null,
        ]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $middleware = new AuthMiddleware(
            new Config(['session' => ['cookie_name' => '__Host-myportfolio_session']]),
            $sessions,
            $db,
            new ResponseFactory(),
            $this->createMock(ApiTokenService::class),
            $this->createMock(IpMatcher::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/logout')
            ->withCookieParams(['__Host-myportfolio_session' => $token]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::assertTrue($request->getAttribute(AuthMiddleware::ATTR_LOGOUT_TOMBSTONE));
                TestCase::assertSame('session', $request->getAttribute(AuthMiddleware::ATTR_METHOD));
                TestCase::assertSame(str_repeat('a', 64), $request->getAttribute(AuthMiddleware::ATTR_TOKEN));
                TestCase::assertSame(17, $request->getAttribute(AuthMiddleware::ATTR_USER)['id'] ?? null);
                return (new ResponseFactory())->createResponse(204);
            }
        };

        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
    }
}
