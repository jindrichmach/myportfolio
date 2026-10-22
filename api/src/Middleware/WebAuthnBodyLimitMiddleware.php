<?php

declare(strict_types=1);

namespace MyPortfolio\Middleware;

use MyPortfolio\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class WebAuthnBodyLimitMiddleware implements MiddlewareInterface
{
    private const MAX_BYTES = 65_536;

    private const VERIFY_PATHS = [
        '/api/auth/webauthn/register/verify',
        '/api/auth/webauthn/login/verify',
        '/api/auth/webauthn/step-up/verify',
        '/api/auth/session/unlock/verify',
    ];

    public function __construct(private readonly ResponseFactory $responseFactory) {}

    public function process(Request $request, Handler $handler): Response
    {
        if (!in_array($request->getUri()->getPath(), self::VERIFY_PATHS, true)) {
            return $handler->handle($request);
        }

        $body = $request->getBody();
        $size = $body->getSize();
        if ($size === null) {
            $size = strlen((string) $body);
        }
        if ($size <= self::MAX_BYTES) {
            return $handler->handle($request);
        }

        return Json::error(
            $this->responseFactory->createResponse(413),
            'request_too_large',
            'WebAuthn požadavek je příliš velký.',
            413,
        );
    }
}
