<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Middleware;

use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Middleware\SessionLockMiddleware;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\SessionLockResult;
use MyPortfolio\Service\Auth\SessionLockService;
use MyPortfolio\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class SessionLockMiddlewareTest extends TestCase
{
    public function testLockedBusinessRequestReturns423(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::once())
            ->method('evaluateForRequest')
            ->with(
                str_repeat('a', 64),
                ['csrf_token' => str_repeat('b', 64)],
                null,
            )
            ->willReturn(SessionLockResult::locked(
                new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
                new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
                'idle',
                false,
            ));
        $response = $this->middleware($locks)->process(
            $this->sessionRequest('/api/invoices'),
            $this->handler(),
        );

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(
            'session_locked',
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code'],
        );
    }

    public function testSessionMissingDuringLockEvaluationFailsClosed(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::once())
            ->method('evaluateForRequest')
            ->with(
                str_repeat('a', 64),
                ['csrf_token' => str_repeat('b', 64)],
                null,
            )
            ->willReturn(SessionLockResult::missing());
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware($locks)->process(
            $this->sessionRequest('/api/invoices'),
            $handler,
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            'session_expired',
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code'],
        );
    }

    public function testPassesExactEvaluationResultToHandler(): void
    {
        $result = SessionLockResult::active(
            new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
            new \DateTimeImmutable('2026-07-24 12:05:00 UTC'),
        );
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::once())
            ->method('evaluateForRequest')
            ->with(
                str_repeat('a', 64),
                ['csrf_token' => str_repeat('b', 64)],
                null,
            )
            ->willReturn($result);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(
                static function (ServerRequestInterface $request) use ($result): bool {
                    self::assertSame(
                        $result,
                        $request->getAttribute(SessionLockMiddleware::ATTR_RESULT),
                    );
                    return true;
                },
            ))
            ->willReturn((new ResponseFactory())->createResponse(204));

        $response = $this->middleware($locks)->process(
            $this->sessionRequest('/api/invoices'),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testLockedStatusAndUnlockRoutesPassThrough(): void
    {
        foreach ([
            ['GET', '/api/auth/session/status'],
            ['POST', '/api/auth/session/unlock/options'],
            ['POST', '/api/auth/session/unlock/verify'],
            ['POST', '/api/auth/logout'],
        ] as [$method, $path]) {
            $locks = $this->createMock(SessionLockService::class);
            $locks->method('evaluateForRequest')->willReturn(SessionLockResult::locked(
                new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
                new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
                'idle',
                false,
            ));
            $response = $this->middleware($locks)->process(
                $this->sessionRequest($path, $method),
                $this->handler(),
            );
            self::assertSame(204, $response->getStatusCode(), $path);
        }
    }

    public function testLockedLoginAndLoginVerifyAreRejected(): void
    {
        foreach ([
            '/api/auth/login',
            '/api/auth/webauthn/login/options',
            '/api/auth/webauthn/login/verify',
        ] as $path) {
            $locks = $this->createMock(SessionLockService::class);
            $locks->method('evaluateForRequest')->willReturn(SessionLockResult::locked(
                new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
                new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
                'idle',
                false,
            ));

            $response = $this->middleware($locks)->process(
                $this->sessionRequest($path, 'POST'),
                $this->handler(),
            );

            self::assertSame(423, $response->getStatusCode(), $path);
        }
    }

    public function testLockedAllowlistRequiresExactHttpMethod(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->method('evaluateForRequest')->willReturn(SessionLockResult::locked(
            new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
            new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
            'idle',
            false,
        ));

        $response = $this->middleware($locks)->process(
            $this->sessionRequest('/api/auth/session/status', 'POST'),
            $this->handler(),
        );

        self::assertSame(423, $response->getStatusCode());
    }

    public function testLogoutTombstonePassesOnlyExactLogoutRequest(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::never())->method('evaluateForRequest');
        $request = $this->sessionRequest('/api/auth/logout', 'POST')
            ->withAttribute(AuthMiddleware::ATTR_LOGOUT_TOMBSTONE, true);

        self::assertSame(
            204,
            $this->middleware($locks)->process($request, $this->handler())->getStatusCode(),
        );

        $wrongMethod = $request->withMethod('GET');
        self::assertSame(
            401,
            $this->middleware($locks)->process($wrongMethod, $this->handler())->getStatusCode(),
        );
    }

    public function testLockedPublicRequestCannotUseOptionalAuthenticatedContext(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->method('evaluateForRequest')->willReturn(SessionLockResult::locked(
            new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
            new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
            'manual',
            false,
        ));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_USER));
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_SESSION));
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_TOKEN));
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_METHOD));
                TestCase::assertNull($request->getAttribute(SessionLockMiddleware::ATTR_RESULT));
                return (new ResponseFactory())->createResponse(204);
            }
        };

        $response = $this->middleware($locks)->process(
            $this->sessionRequest('/api/health'),
            $handler,
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testLockedPublicRequestRequiresExactKnownRouteAndMethod(): void
    {
        foreach ([
            ['DELETE', '/api/health'],
            ['GET', '/api/health/unknown'],
            ['POST', '/api/health'],
        ] as [$method, $path]) {
            $locks = $this->createMock(SessionLockService::class);
            $locks->method('evaluateForRequest')->willReturn(SessionLockResult::locked(
                new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
                new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
                'manual',
                false,
            ));

            self::assertSame(
                423,
                $this->middleware($locks)->process(
                    $this->sessionRequest($path, $method),
                    $this->handler(),
                )->getStatusCode(),
                $method . ' ' . $path,
            );
        }
    }

    public function testSetupSessionIsNotEvaluatedByIdleLock(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::never())->method('evaluateForRequest');

        $request = $this->sessionRequest('/api/auth/totp/status')
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'setup',
            ]);

        self::assertSame(204, $this->middleware($locks)->process(
            $request,
            $this->handler(),
        )->getStatusCode());
    }

    private function middleware(SessionLockService $locks): SessionLockMiddleware
    {
        return new SessionLockMiddleware(
            $locks,
            new ResponseFactory(),
            $this->createMock(ActivityLogger::class),
            $this->createMock(IpMatcher::class),
        );
    }

    private function sessionRequest(string $path, string $method = 'GET'): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['csrf_token' => str_repeat('b', 64)]);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
