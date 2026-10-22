<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Middleware;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Middleware\RequireMfaMiddleware;
use MyPortfolio\Service\Auth\MfaPolicyService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RequireMfaMiddlewareTest extends TestCase
{
    public function testRequiredPolicyAllowsOnlyStrongBusinessSession(): void
    {
        $middleware = $this->middleware(required: true);

        self::assertSame(204, $middleware->process(
            $this->sessionRequest('/api/settings/profile', 'strong'),
            $this->handler(),
        )->getStatusCode());

        foreach (['basic', 'legacy'] as $assurance) {
            $response = $middleware->process(
                $this->sessionRequest('/api/settings/profile', $assurance),
                $this->handler(),
            );
            self::assertSame(401, $response->getStatusCode(), $assurance);
            self::assertSame(
                'mfa_reauthentication_required',
                json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code'],
            );
        }
    }

    public function testOptionalPolicyAllowsBasicAndLegacySessions(): void
    {
        $middleware = $this->middleware(required: false);

        foreach (['basic', 'legacy', 'strong'] as $assurance) {
            self::assertSame(204, $middleware->process(
                $this->sessionRequest('/api/settings/profile', $assurance),
                $this->handler(),
            )->getStatusCode(), $assurance);
        }
    }

    public function testSetupSessionHasExactEnrollmentAllowlist(): void
    {
        $middleware = $this->middleware(required: true);
        $allowed = [
            ['GET', '/api/health'],
            ['GET', '/api/version'],
            ['GET', '/api/auth/me'],
            ['GET', '/api/auth/totp/status'],
            ['GET', '/api/auth/webauthn/credentials'],
            ['POST', '/api/auth/totp/setup'],
            ['POST', '/api/auth/totp/enable'],
            ['POST', '/api/auth/webauthn/register/options'],
            ['POST', '/api/auth/webauthn/register/verify'],
            ['POST', '/api/auth/mfa/step-up/totp'],
            ['POST', '/api/auth/logout'],
        ];

        foreach ($allowed as [$method, $path]) {
            self::assertSame(204, $middleware->process(
                $this->sessionRequest($path, 'setup', $method),
                $this->handler(assertMustSetup: $path !== '/api/auth/logout'),
            )->getStatusCode(), "{$method} {$path}");
        }
    }

    public function testSetupAllowlistRejectsPrefixesWrongMethodsAndBusinessRoutes(): void
    {
        $middleware = $this->middleware(required: true);
        $blocked = [
            ['GET', '/api/auth/webauthn/register/options'],
            ['POST', '/api/auth/webauthn/register/options/extra'],
            ['GET', '/api/auth/session/activity'],
            ['POST', '/api/settings/profile'],
        ];

        foreach ($blocked as [$method, $path]) {
            $response = $middleware->process(
                $this->sessionRequest($path, 'setup', $method),
                $this->handler(),
            );
            self::assertSame(403, $response->getStatusCode(), "{$method} {$path}");
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('mfa_setup_required', $body['error']['code']);
            self::assertSame(['passkey', 'totp'], $body['error']['allowed_mfa_methods']);
        }
    }

    public function testLockedRecoveryPathsAndBearerBypassMfaGuard(): void
    {
        $middleware = $this->middleware(required: true);
        foreach ([
            ['GET', '/api/auth/session/status'],
            ['POST', '/api/auth/session/unlock/options'],
            ['POST', '/api/auth/session/unlock/verify'],
            ['POST', '/api/auth/logout'],
        ] as [$method, $path]) {
            self::assertSame(204, $middleware->process(
                $this->sessionRequest($path, 'legacy', $method),
                $this->handler(),
            )->getStatusCode(), "{$method} {$path}");
        }

        $bearer = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/settings/profile')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]);
        self::assertSame(204, $middleware->process($bearer, $this->handler())->getStatusCode());
    }

    private function middleware(bool $required): RequireMfaMiddleware
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa' => $required,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]));

        return new RequireMfaMiddleware($policy, new ResponseFactory());
    }

    private function sessionRequest(
        string $path,
        string $assurance,
        string $method = 'GET',
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['assurance_level' => $assurance]);
    }

    private function handler(bool $assertMustSetup = false): RequestHandlerInterface
    {
        return new class ($assertMustSetup) implements RequestHandlerInterface {
            public function __construct(private readonly bool $assertMustSetup) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->assertMustSetup) {
                    TestCase::assertTrue($request->getAttribute(RequireMfaMiddleware::ATTR_MUST_SETUP_MFA));
                }
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
