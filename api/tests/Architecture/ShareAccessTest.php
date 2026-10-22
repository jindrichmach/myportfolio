<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Architecture;

use MyPortfolio\Bootstrap;
use MyPortfolio\Service\Sharing\ShareAccess;
use PHPUnit\Framework\TestCase;

/**
 * Izolace při sdílení: co smí partner v kontextu vlastníka (`X-Owner`) zapisovat,
 * je pevný seznam; každé pravidlo allowlistu odpovídá skutečné routě; osobní akce nečtou vlastníka sdílení.
 */
final class ShareAccessTest extends TestCase
{
    private const WRITE_ROUTES = [
        'POST /api/positions', 'PUT /api/positions/{id}',
        'POST /api/transactions', 'PUT /api/transactions/{id}', 'DELETE /api/transactions/{id}', 'POST /api/transfers',
        'POST /api/positions/{id}/valuations', 'POST /api/positions/{id}/cash-balance', 'DELETE /api/valuations/{id}',
        'GET /api/imports/preview/{token}', 'POST /api/imports/preview', 'POST /api/imports/preview/{token}/confirm', 'POST /api/imports/{id}/revert',
    ];

    /** @return array<string,string> "METHOD /api/path/{param}" → ukázková cesta */
    private static function routes(): array
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php') && getenv('MYPORTFOLIO_DB_NAME') === false) {
            self::markTestSkipped('cfg.php neexistuje.');
        }
        $out = [];
        foreach (Bootstrap::buildApp()->getRouteCollector()->getRoutes() as $route) {
            $pattern = (string) preg_replace('/\{(\w+):(?:[^{}]|\{[^{}]*\})+\}/', '{$1}', $route->getPattern());
            $sample = strtr($pattern, ['{id}' => '7', '{token}' => str_repeat('a', 32), '{script}' => 'cron-fx']);
            foreach ($route->getMethods() as $method) {
                $out["$method $pattern"] = $sample;
            }
        }
        return $out;
    }

    public function testWriteAccessIsExactlyTheApprovedList(): void
    {
        $writes = [];
        foreach (self::routes() as $key => $sample) {
            [$method] = explode(' ', $key, 2);
            if (!ShareAccess::isPersonal($sample) && (ShareAccess::rule($method, $sample)['write'] ?? false)) {
                $writes[] = $key;
            }
        }
        sort($writes);
        $expected = self::WRITE_ROUTES;
        sort($expected);
        self::assertSame($expected, $writes);
    }

    public function testEveryRuleMatchesARoute(): void
    {
        $routes = self::routes();
        foreach ((new \ReflectionClassConstant(ShareAccess::class, 'RULES'))->getValue() as [$method, $regex]) {
            $hit = array_filter($routes, static fn (string $sample, string $key) => str_starts_with($key, "$method ") && preg_match($regex, $sample) === 1, ARRAY_FILTER_USE_BOTH);
            self::assertNotEmpty($hit, "$method $regex neodpovídá žádné routě");
        }
    }

    public function testPersonalActionsNeverReadShareOwner(): void
    {
        $root = dirname(__DIR__, 2) . '/src/Action';
        foreach (['Settings', 'Auth', 'Admin', 'Sharing', 'Api', 'Catalog', 'Market'] as $dir) {
            foreach (glob("$root/$dir/*.php") ?: [] as $file) {
                self::assertStringNotContainsString('CurrentUser::owner', (string) file_get_contents($file), basename($file) . ': osobní akce nesmí číst vlastníka sdílení');
            }
        }
    }
}
