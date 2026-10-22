<?php

declare(strict_types=1);

namespace MyPortfolio\Http;

use MyPortfolio\I18n\ErrorCatalog;
use MyPortfolio\I18n\Locale;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Http\Message\ResponseInterface as Response;

final class Json
{
    public static function ok(Response $response, mixed $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function error(Response $response, string $code, string $message, int $status = 400, array $extra = []): Response
    {
        $message = ErrorCatalog::lookup($message, Locale::current());
        return self::ok($response, [
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
        ], $status);
    }

    public static function domainError(Response $response, DomainError $error): Response
    {
        return self::error($response, $error->errorCode, $error->getMessage(), $error->status, $error->extra);
    }
}
