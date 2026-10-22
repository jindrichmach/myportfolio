<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Middleware;

use MyPortfolio\Middleware\ApiScopeMiddleware;
use MyPortfolio\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiScopeMiddlewareTest extends TestCase
{
    public function testSessionRequestPassesThroughEvenOnAdminPath(): void
    {
        // Non-bearer (session) request — ApiScope ho neřeší vůbec.
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/users')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $r = $this->middleware()->process($req, $this->okHandler());
        self::assertSame(204, $r->getStatusCode());
    }

    public function testBearerReadCanGetAllowedResources(): void
    {
        foreach (['/api/health', '/api/version', '/api/auth/api-me'] as $path) {
            $r = $this->middleware()->process($this->bearer('GET', $path, 'read'), $this->okHandler());
            self::assertSame(204, $r->getStatusCode(), "bearer GET $path");
        }
    }

    public function testBearerBlockedFromAdminEndpoint(): void
    {
        $r = $this->middleware()->process(
            $this->bearer('GET', '/api/admin/users', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('token_endpoint_forbidden', $this->errorCode($r));
    }

    public function testBearerBlockedFromTokenManagementAndAccountSettings(): void
    {
        foreach ([
            '/api/auth/tokens',
            '/api/auth/login',
            '/api/auth/webauthn/credentials',
            '/api/auth/webauthn/register/options',
            '/api/settings/profile',
        ] as $path) {
            $r = $this->middleware()->process($this->bearer('POST', $path, 'read_write'), $this->okHandler());
            self::assertSame(403, $r->getStatusCode(), "bearer POST $path");
            self::assertSame('token_endpoint_forbidden', $this->errorCode($r));
        }
    }

    public function testBearerReadCannotWriteAllowedResource(): void
    {
        // Endpoint je v katalogu, ale read scope nesmí zapisovat → insufficient_scope (NE path).
        $r = $this->middleware()->process(
            $this->bearer('POST', '/api/accounts', 'read'),
            $this->okHandler(),
        );
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('insufficient_scope', $this->errorCode($r));
    }

    public function testBearerReadWriteCanWriteAllowedResource(): void
    {
        $r = $this->middleware()->process(
            $this->bearer('POST', '/api/accounts', 'read_write'),
            $this->okHandler(),
        );
        self::assertSame(204, $r->getStatusCode());
    }

    public function testBearerMethodMustMatchCatalog(): void
    {
        // Cesta existuje (GET), ale POST na detail v katalogu není → token_endpoint_forbidden.
        $r = $this->middleware()->process($this->bearer('POST', '/api/positions/7', 'read_write'), $this->okHandler());
        self::assertSame('token_endpoint_forbidden', $this->errorCode($r));
        $ok = $this->middleware()->process($this->bearer('HEAD', '/api/positions/7', 'read'), $this->okHandler());
        self::assertSame(204, $ok->getStatusCode());
    }

    public function testBearerReachesTaxImportsAndAlerts(): void
    {
        foreach ([['GET', '/api/tax-report', 'read'], ['GET', '/api/imports', 'read'], ['POST', '/api/imports/preview', 'read_write'],
            ['DELETE', '/api/price-alerts/3', 'read_write']] as [$method, $path, $scope]) {
            $r = $this->middleware()->process($this->bearer($method, $path, $scope), $this->okHandler());
            self::assertSame(204, $r->getStatusCode(), "$method $path");
        }
        $r = $this->middleware()->process($this->bearer('POST', '/api/imports/preview', 'read'), $this->okHandler());
        self::assertSame('insufficient_scope', $this->errorCode($r));
    }

    private function middleware(): ApiScopeMiddleware
    {
        return new ApiScopeMiddleware(new ResponseFactory());
    }

    private function bearer(string $method, string $path, string $scope): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope]);
    }

    private function errorCode(ResponseInterface $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);
        return $body['error']['code'] ?? null;
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
