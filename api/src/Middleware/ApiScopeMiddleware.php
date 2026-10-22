<?php

declare(strict_types=1);

namespace MyPortfolio\Middleware;

use MyPortfolio\Api\ApiCatalog;
use MyPortfolio\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Enforce token scopes + allowlist endpointů pro bearer-authed requesty.
 *
 * 1) Allowlist — bearer token smí volat JEN endpointy z `ApiCatalog` (metoda + cesta). Interní plocha
 *    (`/api/admin/*`, správa tokenů, login/totp/change-password, nastavení) v katalogu není, takže je
 *    pro token nedostupná, i kdyby ho vytvořil admin. Katalog je zároveň dokumentace (Profil → API).
 * 2) Scope podle katalogu: `read` (čtení) splní každý token, `read_write` (zápis) jen token s tímto rozsahem.
 *
 * Session auth (browser SPA) tímto MW není dotčen — uživatel má plná práva své role.
 *
 * Běží AŽ PO AuthMiddleware (potřebuje načtený api_token attribute).
 */
final class ApiScopeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'bearer') {
            return $handler->handle($request);
        }

        // 1) Allowlist — token mimo katalog → 403 (ne 404, aby integrátor dostal jasný signál;
        //    samotná existence interních cest není tajemství — jsou zdokumentované jako session-only).
        $endpoint = $this->bearerEndpoint($request->getMethod(), $request->getUri()->getPath());
        if ($endpoint === null) {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'token_endpoint_forbidden',
                'API token nemá k tomuto endpointu přístup. Seznam dostupných endpointů je v Profilu → API.',
                403,
            );
        }

        // 2) Scope
        $apiToken = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        $scope    = (string) ($apiToken['scope'] ?? '');
        if ($endpoint['scope'] === ApiCatalog::SCOPE_WRITE && $scope !== ApiCatalog::SCOPE_WRITE) {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'insufficient_scope',
                'API token nemá scope `read_write` pro tuto operaci.',
                403,
            );
        }

        return $handler->handle($request);
    }

    /** @return array<string,mixed>|null položka katalogu, nebo null = token sem nesmí */
    private function bearerEndpoint(string $method, string $path): ?array
    {
        return ApiCatalog::match($method, $path);
    }
}
