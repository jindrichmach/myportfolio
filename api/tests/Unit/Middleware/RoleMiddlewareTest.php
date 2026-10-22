<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Middleware;

use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Middleware\RoleMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RoleMiddlewareTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public static function adminEndpoints(): array
    {
        return [
            'list users'     => ['GET', '/api/admin/users'],
            'create user'    => ['POST', '/api/admin/users'],
            'activity log'   => ['GET', '/api/admin/activity-log'],
            'run cron job'   => ['POST', '/api/admin/cron-jobs/cron-cleanup/run'],
            'update status'  => ['GET', '/api/admin/update'],
        ];
    }

    #[DataProvider('adminEndpoints')]
    public function testAdminCanUseAdminEndpoints(string $method, string $path): void
    {
        $response = $this->middleware()->process($this->request($method, $path, 'admin'), $this->okHandler());

        self::assertSame(204, $response->getStatusCode());
    }

    #[DataProvider('adminEndpoints')]
    public function testUserCannotUseAdminEndpoints(string $method, string $path): void
    {
        $response = $this->middleware()->process($this->request($method, $path, 'user'), $this->okHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUserCanReadAndMutateOwnData(): void
    {
        foreach ([
            ['GET', '/api/settings/profile'],
            ['PUT', '/api/settings/profile'],
            ['GET', '/api/auth/tokens'],
            ['DELETE', '/api/auth/tokens/5'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process($this->request($method, $path, 'user'), $this->okHandler());
            self::assertSame(204, $response->getStatusCode(), "$method $path");
        }
    }

    public function testAllRolesCanManageOwnPasskeys(): void
    {
        foreach (['admin', 'user'] as $role) {
            foreach ([
                ['GET', '/api/auth/webauthn/credentials'],
                ['PATCH', '/api/auth/webauthn/credentials/7'],
                ['DELETE', '/api/auth/webauthn/credentials/7'],
            ] as [$method, $path]) {
                $response = $this->middleware()->process($this->request($method, $path, $role), $this->okHandler());
                self::assertSame(204, $response->getStatusCode(), "$role $method $path");
            }
        }
    }

    public function testUnknownRoleIsDenied(): void
    {
        $response = $this->middleware()->process(
            $this->request('GET', '/api/settings/profile', 'readonly'),
            $this->okHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMissingRoleIsUnauthenticated(): void
    {
        $response = $this->middleware()->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/settings/profile'),
            $this->okHandler(),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    private function middleware(): RoleMiddleware
    {
        return new RoleMiddleware(new ResponseFactory());
    }

    private function request(string $method, string $path, string $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10,
                'role' => $role,
            ]);
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
