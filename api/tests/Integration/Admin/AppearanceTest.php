<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Admin;

use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AppearanceTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->reset();
        }
        parent::tearDown();
    }

    public function testInstanceSchemeIsAdminOnlyAndValidated(): void
    {
        self::assertSame(['color_scheme' => 'forest'], $this->call($this->adminId, 'GET', '/api/admin/appearance')['body']);
        self::assertSame(403, $this->call($this->userId, 'PUT', '/api/admin/appearance', ['color_scheme' => 'ocean'])['status']);
        self::assertSame(400, $this->call($this->adminId, 'PUT', '/api/admin/appearance', ['color_scheme' => 'neon'])['status']);

        $saved = $this->call($this->adminId, 'PUT', '/api/admin/appearance', ['color_scheme' => 'ocean']);
        self::assertSame(['color_scheme' => 'ocean'], $saved['body']);
        self::assertSame('ocean', $this->call($this->userId, 'GET', '/api/auth/setup-status')['body']['color_scheme']);
    }

    public function testUserOverrideFallsBackToInstanceAndColorsEmails(): void
    {
        $this->call($this->adminId, 'PUT', '/api/admin/appearance', ['color_scheme' => 'ocean']);
        self::assertNull($this->call($this->userId, 'GET', '/api/settings/profile')['body']['color_scheme']);
        self::assertStringContainsString('#0369A1', $this->renderFor($this->userId));

        self::assertSame(400, $this->call($this->userId, 'PUT', '/api/settings/profile', ['color_scheme' => 'neon'])['status']);
        $saved = $this->call($this->userId, 'PUT', '/api/settings/profile', ['color_scheme' => 'amethyst']);
        self::assertSame('amethyst', $saved['body']['color_scheme']);
        self::assertSame('amethyst', $this->call($this->userId, 'GET', '/api/auth/me')['body']['user']['color_scheme']);
        self::assertStringContainsString('#6D28D9', $this->renderFor($this->userId));

        // null = zpět na schéma instance
        self::assertNull($this->call($this->userId, 'PUT', '/api/settings/profile', ['color_scheme' => null])['body']['color_scheme']);
    }

    private function renderFor(int $userId): string
    {
        return $this->container->get(Mailer::class)->render('password_reset', 'cs', ['user_id' => $userId, 'name' => 'Test', 'resetLink' => 'https://example.invalid', 'expiresIn' => '60 minut'])['html'];
    }

    private function reset(): void
    {
        $this->db->pdo()->exec("DELETE FROM app_meta WHERE k = 'color_scheme'");
        $this->db->pdo()->exec('UPDATE users SET color_scheme = NULL');
    }
}
