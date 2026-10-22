<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth\Tokens;

use MyPortfolio\Http\Json;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\Auth\ApiTokenService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/auth/tokens — výpis vlastních API tokenů (bez plaintextu).
 */
final class ListTokensAction
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        return Json::ok($response, [
            'tokens' => $this->tokens->listForUser($userId),
        ]);
    }
}
