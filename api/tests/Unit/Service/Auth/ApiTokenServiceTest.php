<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Cache\RedisFactory;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\ApiTokenService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vyžaduje běžící MariaDB s konfigurací v cfg.php — používá ostrou DB,
 * proto si po sobě uklízí (DELETE všech testovacích tokenů).
 */
#[Group('integration')]
final class ApiTokenServiceTest extends TestCase
{
    private Connection $db;
    private ApiTokenService $svc;
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
            $redis = new RedisFactory($config);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        $this->svc = new ApiTokenService($this->db, $redis);
        $this->userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->userId <= 0) {
            $this->markTestSkipped('No users in DB — skip.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->userId > 0) {
            $this->db->pdo()->prepare('DELETE FROM api_tokens WHERE user_id = ? AND name LIKE ?')
                ->execute([$this->userId, '__test_%']);
        }
        if (isset($this->db)) $this->db->close(); // uvolni MySQL spojení (kumulace → max_connections)
    }

    public function testGenerateReturnsPrefixedPlaintext(): void
    {
        $out = $this->svc->generate($this->userId, '__test_plain', 'read_write', null);
        self::assertStringStartsWith('mp_pat_', $out['plaintext']);
        self::assertSame('mp_pat_', substr($out['plaintext'], 0, 7));
        self::assertGreaterThan(20, strlen($out['plaintext']));
        self::assertSame(12, strlen($out['prefix']));
        self::assertStringStartsWith('mp_pat_', $out['prefix']);
        self::assertGreaterThan(0, $out['id']);
    }

    public function testValidateRoundTrip(): void
    {
        $out = $this->svc->generate($this->userId, '__test_rt', 'read', null);
        $row = $this->svc->validate($out['plaintext']);
        self::assertNotNull($row);
        self::assertSame($this->userId, $row['user_id']);
        self::assertSame('read', $row['scope']);
    }

    public function testValidateRejectsTamperedPlaintext(): void
    {
        $out = $this->svc->generate($this->userId, '__test_tamper', 'read', null);
        $tampered = $out['plaintext'] . 'X';
        self::assertNull($this->svc->validate($tampered));
    }

    public function testValidateRejectsForeignPrefix(): void
    {
        self::assertNull($this->svc->validate('Bearer foo'));
        self::assertNull($this->svc->validate('random_string'));
        self::assertNull($this->svc->validate('mp_pat_'));  // too short
    }

    public function testRevokeMakesTokenInvalid(): void
    {
        $out = $this->svc->generate($this->userId, '__test_revoke', 'read_write', null);
        self::assertNotNull($this->svc->validate($out['plaintext']));
        self::assertTrue($this->svc->revoke($out['id'], $this->userId));
        self::assertNull($this->svc->validate($out['plaintext']));
    }

    public function testRevokeRefusesForeignUser(): void
    {
        $out = $this->svc->generate($this->userId, '__test_foreign', 'read', null);
        $otherUser = $this->userId + 99999;  // probably no such user
        self::assertFalse($this->svc->revoke($out['id'], $otherUser));
        self::assertNotNull($this->svc->validate($out['plaintext']), 'Token must still work for owner');
    }

    public function testExpiredTokenInvalid(): void
    {
        $past = new \DateTimeImmutable('-1 hour');
        // generate() rejects past dates? Let's check via raw insert
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, prefix, scope, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $plaintext = 'mp_pat_EXPIRED_TOKEN_FOR_TESTING_ONLY_xyz';
        $stmt->execute([
            $this->userId, '__test_expired',
            hash('sha256', $plaintext), 'mp_pat_EXPI', 'read',
            $past->format('Y-m-d H:i:s'),
        ]);

        self::assertNull($this->svc->validate($plaintext));
    }

    public function testListForUserReturnsOwnedTokens(): void
    {
        $a = $this->svc->generate($this->userId, '__test_list_a', 'read', null);
        $b = $this->svc->generate($this->userId, '__test_list_b', 'read_write', null);

        $list = $this->svc->listForUser($this->userId);
        $names = array_column($list, 'name');
        self::assertContains('__test_list_a', $names);
        self::assertContains('__test_list_b', $names);

        foreach ($list as $row) {
            self::assertArrayNotHasKey('token_hash', $row, 'List must not leak token_hash');
            self::assertArrayNotHasKey('plaintext', $row, 'List must not leak plaintext');
        }
    }

    public function testInvalidScopeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->generate($this->userId, '__test_badscope', 'admin', null);
    }

    public function testEmptyNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->generate($this->userId, '   ', 'read', null);
    }
}
