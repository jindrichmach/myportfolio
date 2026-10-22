<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * Údržba snapshotů napříč uživateli (cron, změna globální ceny) — záměrně mimo
 * user-scoped repository. Nevrací uživatelská data, jen identifikátory.
 */
final class SnapshotMaintenanceRepository
{
    public function __construct(private readonly Connection $db) {}

    /** Změna ceny instrumentu k datu → přepočet všech jeho tržních pozic od toho dne. */
    public function markInstrumentDirty(int $instrumentId, string $fromDate): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO snapshot_dirty (position_id, user_id, from_date)
             SELECT id, user_id, ? FROM positions WHERE instrument_id = ? AND valuation_mode = 'market'
             ON DUPLICATE KEY UPDATE from_date = LEAST(snapshot_dirty.from_date, VALUES(from_date))"
        )->execute([$fromDate, $instrumentId]);
    }

    /** @return list<int> uživatelé s nějakou pozicí (volitelně jen jeden) */
    public function users(?int $onlyUserId = null): array
    {
        $sql = 'SELECT DISTINCT user_id FROM positions';
        $params = [];
        if ($onlyUserId !== null) {
            $sql .= ' WHERE user_id = ?';
            $params[] = $onlyUserId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY user_id');
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
