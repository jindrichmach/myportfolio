<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression guardy převzaté z interního auditu MyInvoice (2026-06-12) pro
 * zděděnou auth vrstvu. Code-inspection (bez DB). Behaviorální pokrytí
 * middleware je v RoleMiddlewareTest / ApiScopeMiddlewareTest.
 *
 * Nálezy:
 *   NX-P1-1  PAT path allowlist + default scope read
 *   NX-P2-1  RBAC — admin endpointy jen pro admina
 *   NX-P3-*  health gate
 */
final class SecurityHardening202606Test extends TestCase
{
    private function src(string $rel): string
    {
        $code = file_get_contents(dirname(__DIR__, 4) . '/api/src/' . $rel);
        self::assertIsString($code, "Soubor $rel musí jít načíst");
        return $code;
    }

    // ---- NX-P1-1 — PAT scoping ------------------------------------------------

    public function testApiScopeHasBearerPathAllowlist(): void
    {
        $code = $this->src('Middleware/ApiScopeMiddleware.php');
        self::assertStringContainsString('ApiCatalog::match', $code,
            'ApiScopeMiddleware musí mít allowlist endpointů pro bearer tokeny (ApiCatalog)');
        self::assertStringContainsString('token_endpoint_forbidden', $code);
        self::assertStringContainsString('bearerEndpoint', $code);
        // Pozn.: chování (/api/admin → 403) je ověřeno v ApiScopeMiddlewareTest.
    }

    public function testCreateTokenDefaultScopeIsRead(): void
    {
        $code = $this->src('Action/Auth/Tokens/CreateTokenAction.php');
        self::assertStringContainsString("\$body['scope'] ?? 'read'", $code,
            'Default scope nového tokenu musí být read (least-privilege)');
        self::assertStringNotContainsString("\$body['scope'] ?? 'read_write'", $code);
    }

    // ---- NX-P2-1 — RBAC -------------------------------------------------------

    public function testRoleMiddlewareReservesAdminPrefix(): void
    {
        $code = $this->src('Middleware/RoleMiddleware.php');
        self::assertStringContainsString("ADMIN_PREFIX = '/api/admin/'", $code,
            'RoleMiddleware musí držet /api/admin/* jen pro admina');
    }

    // ---- NX-P3 ----------------------------------------------------------------

    public function testHealthGatesWarningsBehindAuth(): void
    {
        $code = $this->src('Action/System/HealthAction.php');
        self::assertStringContainsString('ATTR_USER', $code,
            'HealthAction musí podmínit diagnostiku přihlášením');
    }
}
