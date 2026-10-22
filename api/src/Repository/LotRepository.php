<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Support\Decimal;
use PDO;

/**
 * FIFO loty a jejich úbytky — odvozená data (LotRebuilder je přepisuje z transakcí).
 * Každý dotaz je scopovaný na user_id.
 */
final class LotRepository
{
    public function __construct(private readonly Connection $db) {}

    /** Smaže loty instrumentu uživatele (úbytky padnou kaskádou). */
    public function deleteForInstrument(int $userId, int $instrumentId): void
    {
        $this->db->pdo()->prepare('DELETE FROM tax_lots WHERE user_id = ? AND instrument_id = ?')
            ->execute([$userId, $instrumentId]);
    }

    /** @param array<string,?string|int> $lot */
    public function insertLot(int $userId, int $instrumentId, array $lot): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_lots (user_id, position_id, instrument_id, source_transaction_id, origin_lot_id, acquired_at,
                                   quantity, remaining_quantity, cost_amount, cost_currency)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $lot['position_id'], $instrumentId, $lot['source_transaction_id'], $lot['origin_lot_id'],
            $lot['acquired_at'], $lot['quantity'], $lot['remaining_quantity'], $lot['cost_amount'], $lot['cost_currency'],
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,?string|int> $d */
    public function insertDisposal(int $userId, array $d): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO lot_disposals (user_id, lot_id, transaction_id, kind, disposed_at, quantity,
                                        cost_amount, cost_currency, proceeds_amount, proceeds_currency)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $d['lot_id'], $d['transaction_id'], $d['kind'], $d['disposed_at'], $d['quantity'],
            $d['cost_amount'], $d['cost_currency'], $d['proceeds_amount'], $d['proceeds_currency'],
        ]);
    }

    /**
     * Loty uživatele (volitelně jen jedné pozice), nejstarší první.
     *
     * @return list<array<string,mixed>>
     */
    public function lots(int $userId, ?int $positionId = null): array
    {
        $sql = 'SELECT l.id, l.position_id, l.instrument_id, l.source_transaction_id, l.origin_lot_id, l.acquired_at,
                       l.quantity, l.remaining_quantity, l.cost_amount, l.cost_currency, t.type AS source_type
                  FROM tax_lots l
             LEFT JOIN transactions t ON t.id = l.source_transaction_id
                 WHERE l.user_id = ?';
        $params = [$userId];
        if ($positionId !== null) {
            $sql .= ' AND l.position_id = ?';
            $params[] = $positionId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY l.acquired_at, l.id');
        $stmt->execute($params);
        return array_map(static function (array $row): array {
            foreach (['id', 'position_id', 'instrument_id', 'source_transaction_id'] as $k) {
                $row[$k] = (int) $row[$k];
            }
            $row['origin_lot_id'] = $row['origin_lot_id'] !== null ? (int) $row['origin_lot_id'] : null;
            foreach (['quantity', 'remaining_quantity', 'cost_amount'] as $k) {
                $row[$k] = Decimal::out((string) $row[$k]);
            }
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Úbytky uživatele s datem nabytí lotu (volitelně jen jedné pozice).
     *
     * @return list<array<string,mixed>>
     */
    public function disposals(int $userId, ?int $positionId = null): array
    {
        $sql = 'SELECT d.id, d.lot_id, d.transaction_id, d.kind, d.disposed_at, d.quantity, d.cost_amount, d.cost_currency,
                       d.proceeds_amount, d.proceeds_currency, l.position_id, l.acquired_at
                  FROM lot_disposals d
                  JOIN tax_lots l ON l.id = d.lot_id
                 WHERE d.user_id = ?';
        $params = [$userId];
        if ($positionId !== null) {
            $sql .= ' AND l.position_id = ?';
            $params[] = $positionId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY d.disposed_at, d.id');
        $stmt->execute($params);
        return array_map(static function (array $row): array {
            foreach (['id', 'lot_id', 'transaction_id', 'position_id'] as $k) {
                $row[$k] = (int) $row[$k];
            }
            foreach (['quantity', 'cost_amount', 'proceeds_amount'] as $k) {
                $row[$k] = Decimal::out((string) $row[$k]);
            }
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
