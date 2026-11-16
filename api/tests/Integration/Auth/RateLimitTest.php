<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Auth;

use MyPortfolio\Infrastructure\Cache\RateCounter;
use MyPortfolio\Infrastructure\Cache\RedisFactory;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Rate limit platí i bez Redisu (počítadla v MariaDB) — lokálně běží DB větev, v CI Redis.
 */
#[Group('integration')]
final class RateLimitTest extends ApiTestCase
{
    public function testForgotPasswordIsLimitedPerEmail(): void
    {
        $email = 'rl-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $limit = (int) $this->container->get(Config::class)->get('rate_limits.forgot_per_hour_per_email', 3);
        try {
            $statuses = [];
            for ($i = 0; $i <= $limit; $i++) {
                $statuses[] = $this->call($this->userId, 'POST', '/api/auth/forgot', ['email' => $email])['status'];
            }
            self::assertSame([...array_fill(0, $limit, 204), 429], $statuses);
        } finally {
            $this->container->get(RateCounter::class)->reset(['rl:forgot:email:' . sha1($email)]);
        }
    }

    public function testDatabaseCounterStartsNewWindowAfterExpiry(): void
    {
        if ($this->container->get(RedisFactory::class)->isAvailable()) {
            self::markTestSkipped('Redis je dostupný — DB počítadlo se nepoužije.');
        }
        $counter = $this->container->get(RateCounter::class);
        $key = 'rl:test:' . bin2hex(random_bytes(4));
        try {
            self::assertSame([1, 2], [$counter->hit($key, 60)[0], $counter->hit($key, 60)[0]]);
            $this->db->pdo()->prepare('UPDATE rate_limits SET expires_at = NOW() - INTERVAL 1 SECOND WHERE bucket_key = ?')->execute([$key]);
            [$count, $ttl] = $counter->hit($key, 60);
            self::assertSame(1, $count);
            self::assertGreaterThan(50, $ttl);
        } finally {
            $counter->reset([$key]);
        }
    }
}
