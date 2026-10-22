<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Support\Decimal;
use PDO;

/**
 * Podklady daňového reportu za kalendářní rok.
 * Každý dotaz je scopovaný na user_id.
 */
final class TaxRepository
{
    private const POSITION = 'JOIN positions pos ON pos.id = t.position_id AND pos.user_id = t.user_id
                              JOIN accounts acc ON acc.id = pos.account_id
                              JOIN institutions inst ON inst.id = acc.institution_id
                              JOIN instruments ins ON ins.id = pos.instrument_id';

    public function __construct(private readonly Connection $db) {}

    /**
     * Prodeje v roce i s úbytky lotů (FIFO) — jeden řádek na úbytek.
     *
     * @return list<array<string,mixed>>
     */
    public function sales(int $userId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id AS transaction_id, t.executed_at, t.quantity AS sold_quantity, t.amount, t.price_currency, t.fee, t.fee_currency,
                    ins.id AS instrument_id, ins.asset_class, ins.symbol, ins.name AS instrument_name, ins.isin,
                    inst.name AS institution_name, acc.name AS account_name,
                    d.kind, d.quantity, d.cost_amount, d.cost_currency, l.acquired_at
               FROM transactions t
               ' . self::POSITION . '
               JOIN lot_disposals d ON d.transaction_id = t.id AND d.user_id = t.user_id AND d.kind IN (\'sell\', \'fee\')
               JOIN tax_lots l ON l.id = d.lot_id AND l.user_id = t.user_id
              WHERE t.user_id = ? AND t.type = \'sell\' AND t.executed_at >= ? AND t.executed_at < ?
              ORDER BY t.executed_at, t.id, l.acquired_at, d.id'
        );
        $stmt->execute([$userId, "$year-01-01", ($year + 1) . '-01-01']);
        return array_map(static function (array $r): array {
            foreach (['transaction_id', 'instrument_id'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            foreach (['sold_quantity', 'amount', 'fee', 'quantity', 'cost_amount'] as $k) {
                $r[$k] = $r[$k] !== null ? Decimal::out((string) $r[$k]) : null;
            }
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Dividendy a úroky v roce.
     *
     * @return list<array<string,mixed>>
     */
    public function income(int $userId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.type, t.executed_at, t.amount, t.price_currency, t.fee, t.fee_currency,
                    pos.id AS position_id, pos.name AS position_name, ins.asset_class, ins.symbol, ins.name AS instrument_name, ins.isin,
                    inst.name AS institution_name, acc.name AS account_name
               FROM transactions t
               JOIN positions pos ON pos.id = t.position_id AND pos.user_id = t.user_id
               JOIN accounts acc ON acc.id = pos.account_id
               JOIN institutions inst ON inst.id = acc.institution_id
          LEFT JOIN instruments ins ON ins.id = pos.instrument_id
              WHERE t.user_id = ? AND t.type IN (\'dividend\', \'interest\') AND t.amount IS NOT NULL
                AND t.executed_at >= ? AND t.executed_at < ?
              ORDER BY t.executed_at, t.id'
        );
        $stmt->execute([$userId, "$year-01-01", ($year + 1) . '-01-01']);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['position_id'] = (int) $r['position_id'];
            foreach (['amount', 'fee'] as $k) {
                $r[$k] = $r[$k] !== null ? Decimal::out((string) $r[$k]) : null;
            }
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Roky, ve kterých má uživatel prodej, dividendu nebo úrok (sestupně). @return list<int> */
    public function years(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT YEAR(executed_at) FROM transactions
              WHERE user_id = ? AND type IN (\'sell\', \'dividend\', \'interest\') ORDER BY 1 DESC'
        );
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
