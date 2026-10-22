<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth\Tokens;

use MyPortfolio\Http\Json;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\ApiTokenService;
use MyPortfolio\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/auth/tokens/{id} — zruší vlastní API token.
 */
final class RevokeTokenAction
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $tokenId = (int) ($args['id'] ?? 0);
        if ($tokenId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí ID tokenu.', 400);
        }

        $ok = $this->tokens->revoke($tokenId, $userId);
        if (!$ok) {
            return Json::error($response, 'not_found', 'Token nenalezen nebo nepatří uživateli.', 404);
        }

        $ip = $this->ipMatcher->clientIp($request->getServerParams(), [], 'X-Forwarded-For');
        $this->activity->log(
            'api_token.revoked',
            $userId,
            'api_token',
            $tokenId,
            null,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, ['ok' => true]);
    }
}
