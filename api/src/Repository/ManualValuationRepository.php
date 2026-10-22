<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Support\Decimal;
use MyPortfolio\Service\Sharing\AccessContext;
use PDO;

/**
 * Ruční ocenění pozic (Portu, spořicí účty) — jedna hodnota na pozici a den.
 * Každý dotaz je scopovaný na user_id.
 */
final class ManualValuationRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccessContext $access,
    ) {}

    /** @return list<array<string,mixed>> nejnovější první */
    public function list(int $userId, int $positionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, position_id, date, value, currency, note FROM manual_valuations
              WHERE user_id = ? AND position_id = ? ORDER BY date DESC'
        );
        $stmt->execute([$userId, $positionId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Poslední ocenění k datu pro každou pozici uživatele.
     *
     * @return array<int,array<string,mixed>> position_id → ocenění
     */
    public function latestPerPosition(int $userId, string $onOrBefore): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT v.id, v.position_id, v.date, v.value, v.currency, v.note
               FROM manual_valuations v
               JOIN (SELECT position_id, MAX(date) AS max_date FROM manual_valuations
                      WHERE user_id = ? AND date <= ? GROUP BY position_id) m
                 ON m.position_id = v.position_id AND m.max_date = v.date
              WHERE v.user_id = ?'
        );
        $stmt->execute([$userId, $onOrBefore, $userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['position_id']] = self::hydrate($row);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, position_id, date, value, currency, note FROM manual_valuations WHERE user_id = ? AND id = ?'
        );
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** Uloží ocenění; existující ocenění ke stejnému dni přepíše. */
    public function upsert(int $userId, int $positionId, string $date, string $value, string $currency, ?string $note): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO manual_valuations (user_id, position_id, date, value, currency, note, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), currency = VALUES(currency), note = VALUES(note), created_by_user_id = VALUES(created_by_user_id)'
        )->execute([$userId, $positionId, $date, $value, $currency, $note, $this->access->createdBy($userId)]);
    }

    public function delete(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM manual_valuations WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['position_id'] = (int) $row['position_id'];
        $row['value'] = Decimal::out((string) $row['value']);
        return $row;
    }
}
