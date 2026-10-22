<?php

declare(strict_types=1);

namespace MyPortfolio\Middleware;

use MyPortfolio\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Role-based access control (RBAC).
 *
 * Role: admin > user.
 *
 *   - user:  vše kromě admin endpointů (`/api/admin/*`). Izolaci dat mezi
 *            uživateli zajišťuje repository vrstva (každý dotaz scopovaný na user_id).
 *   - admin: vše + správa uživatelů, globálních číselníků (instituce, instrumenty),
 *            cronů a activity logu.
 *
 * AuthMiddleware už zajistil, že je uživatel přihlášen (jinak public path).
 * Tento middleware běží PO Auth a kontroluje minimální roli pro danou kombinaci
 * method+path.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /** Cesty, kde RBAC neaplikujeme (public + self-service). */
    private const PUBLIC_OR_SELF = [
        '/api/health',
        '/api/version',
        '/api/auth/setup-status',
        '/api/auth/setup',
        '/api/auth/setup/smtp-test',
        '/api/auth/login',
        '/api/auth/webauthn/login/options',
        '/api/auth/webauthn/login/verify',
        '/api/auth/logout',
        '/api/auth/me',
        '/api/auth/forgot',
        '/api/auth/reset',
        '/api/auth/change-password',
        '/api/auth/totp/status',
        '/api/auth/totp/setup',
        '/api/auth/totp/enable',
        '/api/auth/webauthn/credentials',
        '/api/auth/webauthn/register/options',
        '/api/auth/webauthn/register/verify',
        '/api/auth/webauthn/step-up/options',
        '/api/auth/webauthn/step-up/verify',
        '/api/auth/mfa/step-up/totp',
        '/api/auth/mfa/step-up/recovery',
        // Vlastní záložní kódy spravuje jen jejich majitel — generování si navíc
        // vynucuje čerstvý step-up skutečným faktorem (MfaRecoveryCodeAction).
        '/api/auth/mfa/recovery-codes',
        '/api/auth/session/status',
        '/api/auth/session/activity',
        '/api/auth/session/lock',
        '/api/auth/session/lock-preference',
        '/api/auth/session/unlock/options',
        '/api/auth/session/unlock/verify',
        '/api/csrf-token',
    ];

    /** Prefix vyhrazený pro admina — vše ostatní smí i role user. */
    private const ADMIN_PREFIX = '/api/admin/';

    public function __construct(
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        // OPTIONS / HEAD pouštíme dál (CORS preflight, monitoring)
        if ($method === 'OPTIONS' || $method === 'HEAD') {
            return $handler->handle($request);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $role = (string) ($user['role'] ?? '');

        // Self-service / public — Auth už dovnitř pustí jen oprávněné, role nás nezajímá
        if (in_array($path, self::PUBLIC_OR_SELF, true)
            || str_starts_with($path, '/api/public/')
        ) {
            return $handler->handle($request);
        }

        // Bez role = bez přístupu (Auth měl už 401, ale defensive)
        if ($role === '') {
            $response = $this->responseFactory->createResponse(401);
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        // admin smí všechno
        if ($role === 'admin') {
            return $handler->handle($request);
        }

        if ($role === 'user' && !str_starts_with($path, self::ADMIN_PREFIX)) {
            return $handler->handle($request);
        }

        // Cokoliv jiného (např. admin endpointy pro non-admin role) → 403
        $response = $this->responseFactory->createResponse(403);
        return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
    }
}
