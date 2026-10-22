<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\TrustedDeviceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vyžaduje běžící MariaDB s konfigurací v cfg.php — používá ostrou DB
 * (trusted_devices prvního usera), proto si po sobě uklízí.
 */
#[Group('integration')]
final class TrustedDeviceServiceTest extends TestCase
{
    private Connection $db;
    private TrustedDeviceService $svc;
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing — skip live-DB test.');
        }
        try {
            $config = Config::load($rootDir);
            $this->db = new Connection($config);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        $this->userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->userId <= 0) {
            $this->markTestSkipped('No users in DB — skip.');
        }
        $this->svc = new TrustedDeviceService($this->db, $config);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->userId > 0) {
            $this->cleanup();
        }
        if (isset($this->db)) $this->db->close(); // uvolni MySQL spojení (kumulace → max_connections)
    }

    private function cleanup(): void
    {
        // Smaž jen testovací zařízení (UA marker), ať nezahodíme reálná.
        $this->db->pdo()->prepare("DELETE FROM trusted_devices WHERE user_id = ? AND user_agent LIKE '__test_%'")
            ->execute([$this->userId]);
    }

    public function testIssueReturnsHexTokenAndVerifySucceeds(): void
    {
        $token = $this->svc->issue($this->userId, '127.0.0.1', '__test_ua');
        self::assertSame(64, strlen($token), 'Token = 32B hex (64 znaků).');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        self::assertTrue($this->svc->verify($token, $this->userId));
    }

    public function testTokenIsStoredHashedNotPlaintext(): void
    {
        $token = $this->svc->issue($this->userId, '127.0.0.1', '__test_ua');
        $stmt = $this->db->pdo()->prepare(
            "SELECT token_hash FROM trusted_devices WHERE user_id = ? AND user_agent = '__test_ua' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$this->userId]);
        $stored = (string) $stmt->fetchColumn();
        self::assertSame(hash('sha256', $token), $stored);
        self::assertNotSame($token, $stored);
    }

    public function testVerifyFailsForDifferentUser(): void
    {
        $token = $this->svc->issue($this->userId, '127.0.0.1', '__test_ua');
        self::assertFalse($this->svc->verify($token, $this->userId + 999999), 'Token nesmí platit pro jiného uživatele.');
    }

    public function testVerifyFailsForGarbageOrNullToken(): void
    {
        self::assertFalse($this->svc->verify(null, $this->userId));
        self::assertFalse($this->svc->verify('', $this->userId));
        self::assertFalse($this->svc->verify('not-a-valid-hex-token', $this->userId));
        self::assertFalse($this->svc->verify(str_repeat('z', 64), $this->userId), 'Nehexadecimální 64-znakový token musí selhat.');
    }

    public function testExpiredDeviceFails(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->db->pdo()->prepare(
            "INSERT INTO trusted_devices (user_id, token_hash, expires_at, user_agent, ip)
             VALUES (?, ?, NOW() - INTERVAL 1 DAY, '__test_ua', '')"
        )->execute([$this->userId, hash('sha256', $token)]);

        self::assertFalse($this->svc->verify($token, $this->userId), 'Expirované zařízení nesmí projít.');
    }

    public function testVerifyUpdatesLastUsedAt(): void
    {
        $token = $this->svc->issue($this->userId, '127.0.0.1', '__test_ua');
        self::assertTrue($this->svc->verify($token, $this->userId));

        $stmt = $this->db->pdo()->prepare(
            "SELECT last_used_at FROM trusted_devices WHERE user_id = ? AND user_agent = '__test_ua' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$this->userId]);
        self::assertNotNull($stmt->fetchColumn(), 'verify() musí nastavit last_used_at.');
    }
}
