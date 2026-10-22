<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Sharing\AccessContext;
use PDO;

/**
 * Účty uživatele u institucí. Každý dotaz je scopovaný na user_id.
 */
final class AccountRepository
{
    private const SELECT = 'SELECT a.id, a.institution_id, i.name AS institution_name, i.type AS institution_type,
                                   CONCAT(\'/api/icons/\', i.icon) AS institution_icon_url,
                                   a.name, a.currency, a.note, a.archived_at, a.created_at
                              FROM accounts a
                              JOIN institutions i ON i.id = a.institution_id';

    public function __construct(
        private readonly Connection $db,
        private readonly AccessContext $access,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $userId, bool $includeArchived): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE a.user_id = ?' . ($includeArchived ? '' : ' AND a.archived_at IS NULL') . $this->sharedOnly($userId)
            . ' ORDER BY a.archived_at IS NOT NULL, i.name, a.name'
        );
        $stmt->execute([$userId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE a.user_id = ? AND a.id = ?' . $this->sharedOnly($userId));
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function nameExists(int $userId, int $institutionId, string $name, ?int $exceptId = null): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM accounts WHERE user_id = ? AND institution_id = ? AND name = ? AND id <> ?'
        );
        $stmt->execute([$userId, $institutionId, $name, $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    public function findIdByName(int $userId, int $institutionId, string $name): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM accounts WHERE user_id = ? AND institution_id = ? AND name = ?');
        $stmt->execute([$userId, $institutionId, $name]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array{institution_id:int,name:string,currency:string,note:?string} $data */
    public function create(int $userId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounts (user_id, institution_id, name, currency, note) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $data['institution_id'], $data['name'], $data['currency'], $data['note']]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{institution_id:int,name:string,currency:string,note:?string,archived:bool} $data */
    public function update(int $userId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE accounts SET institution_id = ?, name = ?, currency = ?, note = ?,
                    archived_at = IF(?, COALESCE(archived_at, CURRENT_TIMESTAMP), NULL)
              WHERE user_id = ? AND id = ?'
        )->execute([
            $data['institution_id'], $data['name'], $data['currency'], $data['note'],
            $data['archived'] ? 1 : 0, $userId, $id,
        ]);
    }

    public function isUsed(int $userId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM positions WHERE user_id = ? AND account_id = ? LIMIT 1');
        $stmt->execute([$userId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    public function delete(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM accounts WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['institution_id'] = (int) $row['institution_id'];
        return $row;
    }

    /** Partner se sdílením portfolií vidí jen účty, na kterých mají sdílená portfolia pozici. */
    private function sharedOnly(int $userId): string
    {
        $in = $this->access->sqlIn($userId, 'px.portfolio_id');
        return $in === '' ? '' : ' AND EXISTS (SELECT 1 FROM positions px WHERE px.user_id = a.user_id AND px.account_id = a.id' . $in . ')';
    }
}
