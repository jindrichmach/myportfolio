<?php

declare(strict_types=1);

namespace MyPortfolio\Middleware;

use MyPortfolio\Http\Json;
use MyPortfolio\Service\Auth\MfaPolicyService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Vynucuje MFA podle assurance aktuální browserové session.
 *
 * Existující faktor u uživatele sám o sobě nestačí: business request při
 * povinné MFA musí pocházet ze strong session. Setup session má bez ohledu na
 * roli pouze přesný enrollment allowlist a logout.
 */
final class RequireMfaMiddleware implements MiddlewareInterface
{
    public const ATTR_MUST_SETUP_MFA = 'auth.must_setup_mfa';

    /** @var array<string,list<string>> */
    private const LOCKED_SESSION_PATHS = [
        'GET' => [
            '/api/auth/session/status',
        ],
        'POST' => [
            '/api/auth/session/unlock/options',
            '/api/auth/session/unlock/verify',
            '/api/auth/logout',
        ],
    ];

    /** @var array<string,list<string>> */
    private const SETUP_PATHS = [
        'GET' => [
            '/api/health',
            '/api/version',
            '/api/auth/me',
            '/api/auth/totp/status',
            '/api/auth/webauthn/credentials',
        ],
        'POST' => [
            '/api/auth/totp/setup',
            '/api/auth/totp/enable',
            '/api/auth/webauthn/register/options',
            '/api/auth/webauthn/register/verify',
            '/api/auth/mfa/step-up/totp',
            '/api/auth/logout',
        ],
    ];

    public function __construct(
        private readonly MfaPolicyService $policy,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return $handler->handle($request);
        }

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!is_array($user)) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        $assurance = is_array($session) ? (string) ($session['assurance_level'] ?? 'legacy') : 'legacy';
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // SessionLockMiddleware běží před tímto guardem. Jeho přesné recovery
        // endpointy nesmí zastavit MFA kontrola, jinak by session nešla odemknout.
        if (self::isAllowed($method, $path, self::LOCKED_SESSION_PATHS)) {
            return $handler->handle($request);
        }

        if ($assurance === 'setup') {
            $request = $request->withAttribute(self::ATTR_MUST_SETUP_MFA, true);
            if (self::isAllowed($method, $path, self::SETUP_PATHS)) {
                return $handler->handle($request);
            }

            return Json::error(
                $this->responseFactory->createResponse(403),
                'mfa_setup_required',
                'Pro pokračování je nutné aktivovat vícefaktorové ověření.',
                403,
                ['allowed_mfa_methods' => $this->policy->allowedMethods()],
            );
        }

        if (!$this->policy->isRequired() || $assurance === 'strong') {
            return $handler->handle($request);
        }

        return Json::error(
            $this->responseFactory->createResponse(401),
            'mfa_reauthentication_required',
            'Platnost přihlášení neprokazuje požadované vícefaktorové ověření. Přihlas se znovu.',
            401,
        );
    }

    /**
     * @param array<string,list<string>> $allowlist
     */
    private static function isAllowed(string $method, string $path, array $allowlist): bool
    {
        return in_array($path, $allowlist[$method] ?? [], true);
    }
}
