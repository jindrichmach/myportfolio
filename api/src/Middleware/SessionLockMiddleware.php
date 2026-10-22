<?php

declare(strict_types=1);

namespace MyPortfolio\Middleware;

use MyPortfolio\Http\Json;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\SessionLockService;
use MyPortfolio\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class SessionLockMiddleware implements MiddlewareInterface
{
    public const ATTR_RESULT = 'auth.session_lock_result';

    /** @var array<string,list<string>> */
    private const LOCKED_ALLOWED = [
        'GET' => [
            '/api/auth/session/status',
        ],
        'POST' => [
            '/api/auth/logout',
            '/api/auth/session/unlock/options',
            '/api/auth/session/unlock/verify',
        ],
    ];

    /** @var array<string,list<string>> */
    private const PUBLIC_PATHS = [
        'GET' => [
            '/api/health',
            '/api/version',
            '/api/auth/setup-status',
        ],
        'POST' => [
            '/api/auth/setup',
            '/api/auth/setup/smtp-test',
            '/api/auth/forgot',
            '/api/auth/reset',
        ],
    ];

    public function __construct(
        private readonly SessionLockService $locks,
        private readonly ResponseFactory $responseFactory,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return $handler->handle($request);
        }
        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        if (is_array($session) && ($session['assurance_level'] ?? 'legacy') === 'setup') {
            return $handler->handle($request);
        }
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        if ($request->getAttribute(AuthMiddleware::ATTR_LOGOUT_TOMBSTONE) === true) {
            if (self::isAllowed($method, $path, self::LOCKED_ALLOWED)
                && $path === '/api/auth/logout'
            ) {
                return $handler->handle($request);
            }
            return Json::error(
                $this->responseFactory->createResponse(401),
                'session_expired',
                'Session vypršela.',
                401,
            );
        }
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userTimeout = ($user['session_lock_after_minutes'] ?? null) !== null
            ? (int) $user['session_lock_after_minutes']
            : null;
        $result = $this->locks->evaluateForRequest(
            $token,
            is_array($session) ? $session : [],
            $userTimeout,
        );
        if (!$result->sessionExists) {
            return Json::error(
                $this->responseFactory->createResponse(401),
                'session_expired',
                'Session vypršela.',
                401,
            );
        }
        $request = $request->withAttribute(self::ATTR_RESULT, $result);
        if (!$result->locked) {
            return $handler->handle($request);
        }

        if ($result->transitioned) {
            $userId = isset($user['id']) ? (int) $user['id'] : null;
            $this->logger->log(
                'auth.session_locked',
                $userId,
                'user',
                $userId,
                ['reason' => $result->reason],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            );
        }

        if (self::isAllowed($method, $path, self::LOCKED_ALLOWED)) {
            return $handler->handle($request);
        }
        if (self::isAllowed($method, $path, self::PUBLIC_PATHS)) {
            return $handler->handle(
                $request
                    ->withoutAttribute(AuthMiddleware::ATTR_USER)
                    ->withoutAttribute(AuthMiddleware::ATTR_SESSION)
                    ->withoutAttribute(AuthMiddleware::ATTR_TOKEN)
                    ->withoutAttribute(AuthMiddleware::ATTR_METHOD)
                    ->withoutAttribute(self::ATTR_RESULT),
            );
        }

        return Json::error(
            $this->responseFactory->createResponse(423),
            'session_locked',
            'Session je zamčená a musí se znovu odemknout.',
            423,
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
