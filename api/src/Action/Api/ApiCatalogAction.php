<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Api;

use MyPortfolio\Api\ApiCatalog;
use MyPortfolio\Api\OpenApiBuilder;
use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\Update\VersionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Dokumentace API z ApiCatalog:
 *   GET /api/api-catalog   — endpointy pro stránku Profil → API (jen session)
 *   GET /api/openapi.json  — OpenAPI 3.1 (session i token)
 */
final class ApiCatalogAction
{
    public function __construct(
        private readonly Config $config,
        private readonly VersionService $version,
    ) {}

    public function catalog(Request $request, Response $response): Response
    {
        $locale = $this->locale($request);
        return Json::ok($response, [
            'base_url'   => $this->baseUrl($request),
            'version'    => $this->version->getCurrentVersion(),
            'rate_limit' => (int) $this->config->get('rate_limits.api_per_min_per_token', 600),
            'groups'     => array_map(static fn (string $key, array $label) => ['key' => $key, 'label' => $label[$locale === 'en' ? 1 : 0]],
                array_keys(ApiCatalog::groups()), ApiCatalog::groups()),
            'endpoints'  => ApiCatalog::localized($locale),
        ]);
    }

    public function openApi(Request $request, Response $response): Response
    {
        $spec = OpenApiBuilder::build($this->baseUrl($request), $this->version->getCurrentVersion(), $this->locale($request));
        $response->getBody()->write((string) json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="myportfolio-openapi.json"')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function locale(Request $request): string
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ($user['locale'] ?? 'cs') === 'en' ? 'en' : 'cs';
    }

    /** app.url z konfigurace, jinak adresa požadavku. */
    private function baseUrl(Request $request): string
    {
        $url = rtrim((string) $this->config->get('app.url', ''), '/');
        if ($url !== '') {
            return $url;
        }
        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }
}
