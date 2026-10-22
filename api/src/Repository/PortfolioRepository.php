<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Sharing\AccessContext;
use PDO;

/**
 * Portfolia — uživatelské seskupení pozic. Každý dotaz je scopovaný na user_id.
 */
final class PortfolioRepository
{
    private const SELECT = 'SELECT p.id, p.name, p.sort, p.archived_at, p.created_at,
                                   (SELECT COUNT(*) FROM positions pos WHERE pos.portfolio_id = p.id AND pos.archived_at IS NULL) AS position_count
                              FROM portfolios p';

    public function __construct(
        private readonly Connection $db,
        private readonly AccessContext $access,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $userId, bool $includeArchived): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE p.user_id = ?' . ($includeArchived ? '' : ' AND p.archived_at IS NULL') . $this->access->sqlIn($userId, 'p.id')
            . ' ORDER BY p.archived_at IS NOT NULL, p.sort, p.name'
        );
        $stmt->execute([$userId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE p.user_id = ? AND p.id = ?' . $this->access->sqlIn($userId, 'p.id'));
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function nameExists(int $userId, string $name, ?int $exceptId = null): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM portfolios WHERE user_id = ? AND name = ? AND id <> ?');
        $stmt->execute([$userId, $name, $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    public function findIdByName(int $userId, string $name): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM portfolios WHERE user_id = ? AND name = ?');
        $stmt->execute([$userId, $name]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array{name:string,sort:int} $data */
    public function create(int $userId, array $data): int
    {
        $this->db->pdo()
            ->prepare('INSERT INTO portfolios (user_id, name, sort) VALUES (?, ?, ?)')
            ->execute([$userId, $data['name'], $data['sort']]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{name:string,sort:int,archived:bool} $data */
    public function update(int $userId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE portfolios SET name = ?, sort = ?, archived_at = IF(?, COALESCE(archived_at, CURRENT_TIMESTAMP), NULL)
              WHERE user_id = ? AND id = ?'
        )->execute([$data['name'], $data['sort'], $data['archived'] ? 1 : 0, $userId, $id]);
    }

    public function isUsed(int $userId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM positions WHERE user_id = ? AND portfolio_id = ? LIMIT 1');
        $stmt->execute([$userId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    public function delete(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM portfolios WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['sort'] = (int) $row['sort'];
        $row['position_count'] = (int) $row['position_count'];
        return $row;
    }
}
