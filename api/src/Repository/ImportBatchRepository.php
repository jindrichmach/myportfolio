<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Sharing\AccessContext;

/** Dávky importu — transakce na ně odkazují přes import_batch_id. Scopované na user_id. */
final class ImportBatchRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccessContext $access,
    ) {}

    public function create(int $userId, string $source, ?string $filename): int
    {
        $this->db->pdo()
            ->prepare('INSERT INTO import_batches (user_id, source, filename, created_by_user_id) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $source, $filename, $this->access->createdBy($userId)]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function finish(int $userId, int $id, int $total, int $imported, int $skipped): void
    {
        $this->db->pdo()
            ->prepare('UPDATE import_batches SET rows_total = ?, rows_imported = ?, rows_skipped = ? WHERE user_id = ? AND id = ?')
            ->execute([$total, $imported, $skipped, $userId, $id]);
    }

    public function delete(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM import_batches WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }

    /** @return list<array<string,mixed>> dávky uživatele (nejnovější první) s počtem dosud existujících transakcí */
    public function list(int $userId, int $limit = 100): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT b.id, b.source, b.filename, b.rows_total, b.rows_imported, b.rows_skipped, b.reverted_at, b.created_at,
                    (SELECT COUNT(*) FROM transactions t WHERE t.user_id = b.user_id AND t.import_batch_id = b.id) AS transactions
               FROM import_batches b WHERE b.user_id = ?' . $this->shared($userId, true) . ' ORDER BY b.created_at DESC, b.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$userId]);
        return array_map(static function (array $r): array {
            foreach (['id', 'rows_total', 'rows_imported', 'rows_skipped', 'transactions'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            return $r;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT b.id, b.source, b.filename, b.reverted_at FROM import_batches b WHERE b.user_id = ? AND b.id = ?' . $this->shared($userId, false));
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Partner se sdílením portfolií: v seznamu dávky s transakcí ve sdílených portfoliích, k vrácení jen dávky
     * celé ve sdílených portfoliích.
     */
    private function shared(int $userId, bool $any): string
    {
        $in = $this->access->sqlIn($userId, 'px.portfolio_id');
        if ($in === '') {
            return '';
        }
        $tx = 'SELECT 1 FROM transactions tx JOIN positions px ON px.id = tx.position_id WHERE tx.user_id = b.user_id AND tx.import_batch_id = b.id';
        return $any ? " AND EXISTS ($tx$in)" : ' AND NOT EXISTS (' . $tx . ' AND NOT (1 = 1' . $in . '))';
    }

    public function markReverted(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('UPDATE import_batches SET reverted_at = CURRENT_TIMESTAMP WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }
}
