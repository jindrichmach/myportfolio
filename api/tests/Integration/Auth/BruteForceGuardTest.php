<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Auth;

use MyPortfolio\Infrastructure\Cache\RedisFactory;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\BruteForceGuard;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ochrana proti hádání hesla: práh per účet napříč IP a zámek, který skutečně trvá 24 hodin.
 * Lokálně běží DB větev, v CI Redis.
 */
#[Group('integration')]
final class BruteForceGuardTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->exec("DELETE FROM login_attempts WHERE email LIKE 'bf-%@example.invalid' OR bucket_key LIKE 'lock24h:%'");
        }
        parent::tearDown();
    }

    /** @param array<string,mixed> $limits */
    private function guard(array $limits): BruteForceGuard
    {
        return new BruteForceGuard(
            new Config(['brute_force' => $limits + ['captcha_after' => 100, 'lockout_15m_at' => 100, 'lockout_24h_at' => 100, 'account_lockout_15m_at' => 100]]),
            $this->container->get(RedisFactory::class),
            $this->container->get(Connection::class),
        );
    }

    private static function email(): string
    {
        return 'bf-' . bin2hex(random_bytes(4)) . '@example.invalid';
    }

    public function testAccountIsLockedWhenAttackerRotatesIps(): void
    {
        $guard = $this->guard(['account_lockout_15m_at' => 3]);
        $email = self::email();
        foreach (['10.0.1.1', '10.0.2.1', '2001:db8:1::1'] as $ip) {
            self::assertSame(BruteForceGuard::STATE_OK, $guard->check($email, $ip));
            $guard->recordFailure($email, $ip);
        }

        self::assertSame(BruteForceGuard::STATE_LOCKED_15M, $guard->check(strtoupper($email), '10.0.9.1'));
        self::assertSame(BruteForceGuard::STATE_OK, $guard->check(self::email(), '10.0.9.1'), 'jiný účet zámek nemá');
    }

    public function testDayLockOutlivesFailureCounters(): void
    {
        $guard = $this->guard(['lockout_24h_at' => 2]);
        $email = self::email();
        $guard->recordFailure($email, '10.0.1.1');
        $guard->recordFailure($email, '10.0.1.1');
        self::assertSame(BruteForceGuard::STATE_LOCKED_24H, $guard->check($email, '10.0.1.1'));

        // Počítadla v hodinovém okně zmizí (tady smazáním) — zámek trvá dál
        $guard->recordSuccess($email, '10.0.1.1');
        self::assertSame(BruteForceGuard::STATE_LOCKED_24H, $guard->check($email, '10.0.1.1'));
    }
}
