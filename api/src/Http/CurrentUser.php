<?php

declare(strict_types=1);

namespace MyPortfolio\Http;

use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Middleware\ShareContextMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přihlášený uživatel z atributu AuthMiddleware (session i bearer token) a vlastník čtených dat.
 *
 *   id()    — kdo jedná: předvolby (měna zobrazení), nastavení, autor zápisu
 *   owner() — čí data čte / zapisuje doménová akce: přihlášený, nebo vlastník sdílení (`X-Owner`)
 */
final class CurrentUser
{
    private function __construct() {}

    public static function id(Request $request): int
    {
        return (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
    }

    public static function owner(Request $request): int
    {
        return (int) ($request->getAttribute(ShareContextMiddleware::ATTR_OWNER) ?? self::id($request));
    }

    public static function isAdmin(Request $request): bool
    {
        return (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['role'] ?? '') === 'admin';
    }
}
