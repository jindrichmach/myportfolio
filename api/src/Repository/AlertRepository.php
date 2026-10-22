<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Support\Decimal;
use PDO;

/**
 * Cenová upozornění a log ohlášených upozornění.
 * Každý dotaz je scopovaný na user_id.
 */
final class AlertRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function priceAlerts(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.instrument_id, a.direction, a.price, a.currency, a.note, a.triggered_at, a.triggered_price, a.created_at,
                    ins.symbol, ins.name AS instrument_name, ins.asset_class, ins.quote_currency
               FROM price_alerts a
               JOIN instruments ins ON ins.id = a.instrument_id
              WHERE a.user_id = ?
              ORDER BY a.triggered_at IS NOT NULL, ins.symbol, a.price'
        );
        $stmt->execute([$userId]);
        return array_map(self::alertRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function findPriceAlert(int $userId, int $id): ?array
    {
        foreach ($this->priceAlerts($userId) as $a) {
            if ($a['id'] === $id) {
                return $a;
            }
        }
        return null;
    }

    /** @param array{instrument_id:int,direction:string,price:string,currency:string,note:?string} $data */
    public function createPriceAlert(int $userId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO price_alerts (user_id, instrument_id, direction, price, currency, note) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $data['instrument_id'], $data['direction'], $data['price'], $data['currency'], $data['note']]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Úprava hranice ji znovu aktivuje. @param array{instrument_id:int,direction:string,price:string,currency:string,note:?string} $data */
    public function updatePriceAlert(int $userId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE price_alerts SET instrument_id = ?, direction = ?, price = ?, currency = ?, note = ?, triggered_at = NULL, triggered_price = NULL
              WHERE user_id = ? AND id = ?'
        )->execute([$data['instrument_id'], $data['direction'], $data['price'], $data['currency'], $data['note'], $userId, $id]);
    }

    public function deletePriceAlert(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM price_alerts WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }

    public function markTriggered(int $userId, int $id, string $price): void
    {
        $this->db->pdo()->prepare('UPDATE price_alerts SET triggered_at = NOW(), triggered_price = ? WHERE user_id = ? AND id = ? AND triggered_at IS NULL')
            ->execute([$price, $userId, $id]);
    }

    /**
     * Které z klíčů už byly ohlášené.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public function loggedKeys(int $userId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT alert_key FROM alert_log WHERE user_id = ? AND alert_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')'
        );
        $stmt->execute([$userId, ...$keys]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param list<string> $keys */
    public function log(int $userId, array $keys): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT IGNORE INTO alert_log (user_id, alert_key) VALUES (?, ?)');
        foreach ($keys as $key) {
            $stmt->execute([$userId, $key]);
        }
    }

    /** @param array<string,mixed> $r */
    private static function alertRow(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['instrument_id'] = (int) $r['instrument_id'];
        $r['price'] = Decimal::out((string) $r['price']);
        $r['triggered_price'] = $r['triggered_price'] !== null ? Decimal::out((string) $r['triggered_price']) : null;
        return $r;
    }
}
