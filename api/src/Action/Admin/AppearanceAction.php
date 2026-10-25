<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Appearance\ColorSchemes;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET|PUT /api/admin/appearance — výchozí barevné schéma instance (uživatel si ho v profilu může přepsat).
 */
final class AppearanceAction
{
    public function __construct(
        private readonly ColorSchemes $schemes,
        private readonly ActivityLogger $activity,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        return Json::ok($response, ['color_scheme' => $this->schemes->instance()]);
    }

    public function update(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $key = $body['color_scheme'] ?? null;
        if (!ColorSchemes::isValid($key)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => ['color_scheme' => ['Neznámé barevné schéma.']]]);
        }
        $this->schemes->setInstance((string) $key);
        $this->activity->log('appearance.updated', CurrentUser::id($request), null, null, ['color_scheme' => $key]);
        return $this->get($request, $response);
    }
}
