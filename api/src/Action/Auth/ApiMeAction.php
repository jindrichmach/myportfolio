<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth;

use MyPortfolio\Http\Json;
use MyPortfolio\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/auth/api-me — connection-test pro API klienty (Make, Zapier, vlastní skripty).
 *
 * Funguje pro bearer i session auth. Vrátí:
 *   - user: { id, email, name, role }
 *   - auth_method: 'bearer' | 'session'
 *   - token: { id, prefix, scope, expires_at } | null   (jen pro bearer)
 */
final class ApiMeAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (empty($user)) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $method = (string) $request->getAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $tokenOut = null;
        if ($method === 'bearer') {
            $tok = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
            $tokenOut = [
                'id'         => (int) ($tok['id'] ?? 0),
                'name'       => $tok['name'] ?? null,
                'prefix'     => $tok['prefix'] ?? null,
                'scope'      => $tok['scope'] ?? null,
                'expires_at' => $tok['expires_at'] ?? null,
            ];
        }

        return Json::ok($response, [
            'user' => [
                'id'    => (int) $user['id'],
                'email' => $user['email'],
                'name'  => $user['name'],
                'role'  => $user['role'],
            ],
            'auth_method' => $method,
            'token'       => $tokenOut,
        ]);
    }
}
