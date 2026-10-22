<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * Cílová alokace. Sada cílů pro celý majetek (`portfolio_id` NULL)
 * nebo pro jedno portfolio; ukládá se vždy celá sada najednou. Scopované na user_id.
 */
final class AllocationTargetRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array{category:string,instrument_id:?int,target_pct:string,band_pct:?string}> */
    public function list(int $userId, ?int $portfolioId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT category, instrument_id, target_pct, band_pct FROM allocation_targets
              WHERE user_id = ? AND portfolio_id <=> ? ORDER BY category, instrument_id IS NOT NULL, id'
        );
        $stmt->execute([$userId, $portfolioId]);
        return array_map(static fn (array $r) => [
            'category'      => (string) $r['category'],
            'instrument_id' => $r['instrument_id'] !== null ? (int) $r['instrument_id'] : null,
            'target_pct'    => (string) $r['target_pct'],
            'band_pct'      => $r['band_pct'] !== null ? (string) $r['band_pct'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Rozsahy, které mají nastavené cíle: null = celý majetek, jinak portfolio_id. @return list<?int> */
    public function scopes(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT portfolio_id FROM allocation_targets WHERE user_id = ? ORDER BY portfolio_id');
        $stmt->execute([$userId]);
        return array_map(static fn ($v) => $v !== null ? (int) $v : null, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<array{category:string,instrument_id:?int,target_pct:string,band_pct:?string}> $rows */
    public function replace(int $userId, ?int $portfolioId, array $rows): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM allocation_targets WHERE user_id = ? AND portfolio_id <=> ?')->execute([$userId, $portfolioId]);
            $insert = $pdo->prepare(
                'INSERT INTO allocation_targets (user_id, portfolio_id, category, instrument_id, target_pct, band_pct) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($rows as $r) {
                $insert->execute([$userId, $portfolioId, $r['category'], $r['instrument_id'], $r['target_pct'], $r['band_pct']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
