<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Support\Decimal;
use PDO;

/**
 * Položky čistého jmění mimo investice (nemovitost, ostatní majetek, závazek) a jejich záznamy
 * (ocenění, u závazku zůstatek s úrokem a splátkou). Scopované na user_id.
 */
final class NetWorthRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function items(int $userId, bool $archived = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, kind, name, currency, note, archived_at, created_at FROM net_worth_items
              WHERE user_id = ?' . ($archived ? '' : ' AND archived_at IS NULL') . " ORDER BY FIELD(kind, 'real_estate', 'other', 'liability'), name, id"
        );
        $stmt->execute([$userId]);
        return array_map(static fn (array $r) => ['id' => (int) $r['id']] + $r, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function findItem(int $userId, int $id): ?array
    {
        foreach ($this->items($userId, true) as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /** @param array{kind:string,name:string,currency:string,note:?string} $data */
    public function createItem(int $userId, array $data): int
    {
        $this->db->pdo()->prepare('INSERT INTO net_worth_items (user_id, kind, name, currency, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $data['kind'], $data['name'], $data['currency'], $data['note']]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{name:string,currency:string,note:?string,archived:bool} $data */
    public function updateItem(int $userId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE net_worth_items SET name = ?, currency = ?, note = ?,
                    archived_at = CASE WHEN ? THEN COALESCE(archived_at, NOW()) END
              WHERE user_id = ? AND id = ?'
        )->execute([$data['name'], $data['currency'], $data['note'], $data['archived'] ? 1 : 0, $userId, $id]);
    }

    public function deleteItem(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM net_worth_items WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }

    /**
     * Záznamy po položkách, vzestupně podle data.
     *
     * @return array<int,list<array{id:int,date:string,value:string,rate_pct:?string,payment:?string,note:?string}>>
     */
    public function entries(int $userId, ?int $itemId = null): array
    {
        $sql = 'SELECT id, item_id, date, value, rate_pct, payment, note FROM net_worth_entries WHERE user_id = ?';
        $params = [$userId];
        if ($itemId !== null) {
            $sql .= ' AND item_id = ?';
            $params[] = $itemId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY item_id, date');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['item_id']][] = [
                'id'       => (int) $r['id'],
                'date'     => (string) $r['date'],
                'value'    => (string) Decimal::out((string) $r['value']),
                'rate_pct' => Decimal::out($r['rate_pct']),
                'payment'  => Decimal::out($r['payment']),
                'note'     => $r['note'],
            ];
        }
        return $out;
    }

    /** Záznam k datu (stejný den se přepíše). */
    public function saveEntry(int $userId, int $itemId, string $date, string $value, ?string $ratePct, ?string $payment, ?string $note): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO net_worth_entries (user_id, item_id, date, value, rate_pct, payment, note) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), rate_pct = VALUES(rate_pct), payment = VALUES(payment), note = VALUES(note)'
        )->execute([$userId, $itemId, $date, $value, $ratePct, $payment, $note]);
    }

    public function deleteEntry(int $userId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM net_worth_entries WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $id]);
        return $stmt->rowCount() > 0;
    }
}
