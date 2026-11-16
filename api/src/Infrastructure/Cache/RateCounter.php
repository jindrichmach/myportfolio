<?php

declare(strict_types=1);

namespace MyPortfolio\Infrastructure\Cache;

use MyPortfolio\Infrastructure\Database\Connection;

/**
 * Počítadlo pevného okna pro rate limit: Redis (INCR), bez Redisu MEMORY tabulka `rate_limits`.
 * Každý zásah se započítá atomicky ještě před rozhodnutím, takže souběžné požadavky limit nepřeskočí.
 */
final class RateCounter
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly Connection $db,
    ) {}

    /** @return array{0:int,1:int} [počet zásahů v okně včetně tohoto, sekund do konce okna] */
    public function hit(string $key, int $window): array
    {
        $r = $this->redis->client();
        if ($r !== null) {
            $count = (int) $r->incr($key);
            $ttl = (int) $r->ttl($key);
            if ($count === 1 || $ttl < 0) {
                $r->expire($key, $window);
                $ttl = $window;
            }
            return [$count, $ttl];
        }

        $pdo = $this->db->pdo();
        // Přiřazení v ON DUPLICATE KEY UPDATE se vyhodnocují zleva — obě podmínky vidí ještě původní expires_at
        $pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, hits, expires_at) VALUES (?, 1, NOW() + INTERVAL ? SECOND)
             ON DUPLICATE KEY UPDATE
                 hits = IF(expires_at <= NOW(), 1, hits + 1),
                 expires_at = IF(expires_at <= NOW(), VALUES(expires_at), expires_at)'
        )->execute([$key, $window]);
        $stmt = $pdo->prepare('SELECT hits, GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) FROM rate_limits WHERE bucket_key = ?');
        $stmt->execute([$key]);
        [$count, $ttl] = $stmt->fetch(\PDO::FETCH_NUM) ?: [1, $window];
        return [(int) $count, (int) $ttl];
    }

    /** @param list<string> $keys */
    public function reset(array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $r = $this->redis->client();
        if ($r !== null) {
            $r->del($keys);
            return;
        }
        $this->db->pdo()->prepare('DELETE FROM rate_limits WHERE bucket_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')')
            ->execute($keys);
    }
}
