<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Api;

use MyPortfolio\Api\ApiCatalog;
use MyPortfolio\Service\Auth\ApiTokenService;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Endpointy z katalogu skutečně odpovídají API tokenem; dokumentace API (katalog, OpenAPI). */
#[Group('integration')]
final class ApiTokenAccessTest extends ApiTestCase
{
    /** @return array{status:int,body:mixed,raw:string} */
    private function bearer(string $token, string $method, string $path): array
    {
        $uri = parse_url($path);
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Authorization', "Bearer $token")->withHeader('Accept', 'application/json');
        if (isset($uri['query'])) {
            parse_str($uri['query'], $query);
            $request = $request->withQueryParams($query);
        }
        $response = $this->app->handle($request);
        $raw = (string) $response->getBody();
        return ['status' => $response->getStatusCode(), 'raw' => $raw,
            'body' => str_contains($response->getHeaderLine('Content-Type'), 'json') && $raw !== '' ? json_decode($raw, true) : null];
    }

    public function testEveryReadEndpointAnswersToToken(): void
    {
        $token = $this->container->get(ApiTokenService::class)->generate($this->userId, 'smoke', 'read')['plaintext'];
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic API Bank', 'type' => 'bank'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'API'])['body']['id'];
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'API'])['body']['id'];
        $pos = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'manual', 'portfolio_id' => $pf, 'account_id' => $acc, 'name' => 'Cash'])['body']['id'];
        $ins = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => 'etf', 'symbol' => 'SYNAPI', 'name' => 'Synthetic', 'quote_currency' => 'CZK'])['body']['id'];
        $ids = ['/api/accounts/{id}' => $acc, '/api/portfolios/{id}' => $pf, '/api/positions/{id}' => $pos,
            '/api/positions/{id}/valuation' => $pos, '/api/positions/{id}/valuations' => $pos];
        $query = ['/api/prices' => "?instrument_id=$ins", '/api/tax-report' => '?year=2025', '/api/tax-report/export.csv' => '?year=2025',
            '/api/tax-report/filing' => '?year=2025', '/api/tax-report/filing.csv' => '?year=2025&dividends=38'];

        foreach (ApiCatalog::endpoints() as $e) {
            if ($e['method'] !== 'GET' || (str_contains($e['path'], '{') && !isset($ids[$e['path']]))) {
                continue;
            }
            $path = str_replace('{id}', (string) ($ids[$e['path']] ?? ''), $e['path']) . ($query[$e['path']] ?? '');
            $r = $this->bearer($token, 'GET', $path);
            self::assertSame(200, $r['status'], "GET $path: {$r['raw']}");
        }

        $write = $this->bearer($token, 'POST', '/api/portfolios');
        self::assertSame(['403', 'insufficient_scope'], [(string) $write['status'], $write['body']['error']['code']]);
        $internal = $this->bearer($token, 'GET', '/api/settings/profile');
        self::assertSame('token_endpoint_forbidden', $internal['body']['error']['code']);
        self::assertSame('token_endpoint_forbidden', $this->bearer($token, 'GET', '/api/api-catalog')['body']['error']['code']);
    }

    public function testCatalogAndOpenApiDocumentation(): void
    {
        $catalog = $this->call($this->userId, 'GET', '/api/api-catalog');
        self::assertSame(200, $catalog['status']);
        self::assertCount(count(ApiCatalog::endpoints()), $catalog['body']['endpoints']);
        self::assertIsString($catalog['body']['endpoints'][0]['summary']);
        self::assertNotEmpty($catalog['body']['groups']);

        $spec = $this->call($this->userId, 'GET', '/api/openapi.json');
        self::assertSame(200, $spec['status']);
        self::assertSame('3.1.0', $spec['body']['openapi']);
        self::assertArrayHasKey('/api/imports/preview/{token}/confirm', $spec['body']['paths']);
        self::assertSame('bearer', $spec['body']['components']['securitySchemes']['bearerAuth']['scheme']);
    }
}
