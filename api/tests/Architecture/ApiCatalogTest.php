<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Architecture;

use MyPortfolio\Api\ApiCatalog;
use MyPortfolio\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Katalog API (Profil → API, OpenAPI, oprávnění tokenu) odpovídá skutečným routám a nepouští token
 * do interní plochy (admin, nastavení účtu, správa tokenů, přihlášení).
 */
final class ApiCatalogTest extends TestCase
{
    /** @return array<string,true> "METHOD /api/path/{param}" ze Slim rout */
    private static function routes(): array
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php') && getenv('MYPORTFOLIO_DB_NAME') === false) {
            self::markTestSkipped('cfg.php neexistuje.');
        }
        $out = [];
        foreach (Bootstrap::buildApp()->getRouteCollector()->getRoutes() as $route) {
            $pattern = (string) preg_replace('/\{(\w+):(?:[^{}]|\{[^{}]*\})+\}/', '{$1}', $route->getPattern());
            foreach ($route->getMethods() as $method) {
                $out["$method $pattern"] = true;
            }
        }
        return $out;
    }

    public function testEveryEndpointIsARealRoute(): void
    {
        $routes = self::routes();
        foreach (ApiCatalog::endpoints() as $e) {
            self::assertArrayHasKey("{$e['method']} {$e['path']}", $routes, "{$e['id']}: routa neexistuje");
        }
    }

    public function testCatalogIsConsistent(): void
    {
        $ids = [];
        foreach (ApiCatalog::endpoints() as $e) {
            self::assertArrayNotHasKey($e['id'], $ids, "duplicitní id {$e['id']}");
            $ids[$e['id']] = true;
            self::assertArrayHasKey($e['group'], ApiCatalog::groups(), $e['id']);
            self::assertSame($e['method'] === 'GET' ? ApiCatalog::SCOPE_READ : ApiCatalog::SCOPE_WRITE, $e['scope'], "{$e['id']}: rozsah");
            preg_match_all('/\{(\w+)\}/', $e['path'], $m);
            $pathParams = array_values(array_map(static fn (array $p) => $p['name'], array_filter($e['params'], static fn (array $p) => $p['in'] === 'path')));
            self::assertSame($m[1], $pathParams, "{$e['id']}: parametry cesty");
            foreach ($e['params'] as $p) {
                self::assertNotSame('', $p['description'][0], "{$e['id']}.{$p['name']} cs");
                self::assertNotSame('', $p['description'][1], "{$e['id']}.{$p['name']} en");
            }
        }
    }

    public function testInternalSurfaceIsNotReachableByToken(): void
    {
        foreach (ApiCatalog::endpoints() as $e) {
            self::assertDoesNotMatchRegularExpression('#^/api/(admin|settings|api-catalog)\b#', $e['path'], $e['id']);
            self::assertTrue(!str_starts_with($e['path'], '/api/auth/') || $e['path'] === '/api/auth/api-me', $e['id']);
        }
        foreach ([['GET', '/api/admin/users'], ['POST', '/api/auth/tokens'], ['GET', '/api/settings/profile'], ['PUT', '/api/settings/alerts'],
            ['GET', '/api/api-catalog'], ['POST', '/api/auth/change-password'], ['GET', '/api/settings/imap-accounts']] as [$method, $path]) {
            self::assertNull(ApiCatalog::match($method, $path), "$method $path");
        }
    }
}
