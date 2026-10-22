<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * Globální katalog institucí (burzy, brokeři, peněženky, robo-advisoři, banky).
 * Zakládat smí každý uživatel, upravovat a mazat jen admin (řeší Action + RoleMiddleware).
 */
final class InstitutionRepository
{
    public const TYPES = ['exchange', 'broker', 'wallet', 'robo', 'bank', 'pension'];

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(bool $includeInactive): array
    {
        $sql = 'SELECT id, name, type, website, icon, created_by_user_id, is_active FROM institutions'
            . ($includeInactive ? '' : ' WHERE is_active = 1')
            . ' ORDER BY name';
        return array_map([self::class, 'hydrate'], $this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, type, website, icon, created_by_user_id, is_active FROM institutions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM institutions WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    public function findIdByName(string $name): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM institutions WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array{name:string,type:string,website:?string} $data */
    public function create(array $data, int $createdByUserId): int
    {
        $this->db->pdo()
            ->prepare('INSERT INTO institutions (name, type, website, created_by_user_id) VALUES (?, ?, ?, ?)')
            ->execute([$data['name'], $data['type'], $data['website'], $createdByUserId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{name:string,type:string,website:?string,is_active:bool} $data */
    public function update(int $id, array $data): void
    {
        $this->db->pdo()
            ->prepare('UPDATE institutions SET name = ?, type = ?, website = ?, is_active = ? WHERE id = ?')
            ->execute([$data['name'], $data['type'], $data['website'], $data['is_active'] ? 1 : 0, $id]);
    }

    public function setIcon(int $id, ?string $icon): void
    {
        $this->db->pdo()->prepare('UPDATE institutions SET icon = ? WHERE id = ?')->execute([$icon, $id]);
    }

    public function usesIcon(string $icon): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM institutions WHERE icon = ? LIMIT 1');
        $stmt->execute([$icon]);
        return $stmt->fetchColumn() !== false;
    }

    public function isUsed(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM accounts WHERE institution_id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    public function delete(int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM institutions WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['created_by_user_id'] = $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null;
        return $row;
    }
}
