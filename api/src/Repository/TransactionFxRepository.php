<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * Systémová údržba kurzů u transakcí napříč uživateli (cron-fx). Nevrací uživatelská
 * data do API — jen doplňuje `fx_rate_czk` podle měny a data transakce.
 */
final class TransactionFxRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array{id:int,price_currency:string,date:string}> */
    public function missingRates(int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, price_currency, DATE(executed_at) AS date FROM transactions
              WHERE fx_rate_czk IS NULL AND price_currency IS NOT NULL
              ORDER BY executed_at LIMIT ' . max(1, $limit)
        );
        $stmt->execute();
        return array_map(
            static fn (array $r) => ['id' => (int) $r['id'], 'price_currency' => (string) $r['price_currency'], 'date' => (string) $r['date']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function setRate(int $transactionId, string $rate): void
    {
        $this->db->pdo()->prepare('UPDATE transactions SET fx_rate_czk = ? WHERE id = ? AND fx_rate_czk IS NULL')
            ->execute([$rate, $transactionId]);
    }

    public function earliestTransactionDate(): ?string
    {
        $date = $this->db->pdo()->query('SELECT MIN(DATE(executed_at)) FROM transactions')->fetchColumn();
        return $date !== false && $date !== null ? (string) $date : null;
    }

    /**
     * Instrumenty v pozicích a benchmarky uživatelů s datem první transakce — od kdy potřebujeme historii cen.
     *
     * @return array<int,string> instrument_id => YYYY-MM-DD
     */
    public function instrumentsNeedingHistory(): array
    {
        // Držené instrumenty od první transakce; benchmark uživatele od jeho první transakce
        $rows = $this->db->pdo()->query(
            'WITH used AS (
                SELECT pos.instrument_id, MIN(DATE(t.executed_at)) AS since
                  FROM positions pos
                  JOIN transactions t ON t.position_id = pos.id
                 WHERE pos.instrument_id IS NOT NULL
                 GROUP BY pos.instrument_id
             UNION ALL
                SELECT u.benchmark_instrument_id, MIN(DATE(t.executed_at))
                  FROM users u
                  JOIN transactions t ON t.user_id = u.id
                 WHERE u.benchmark_instrument_id IS NOT NULL
                 GROUP BY u.id, u.benchmark_instrument_id
             )
             SELECT instrument_id, MIN(since) AS since FROM used GROUP BY instrument_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['instrument_id']] = (string) $row['since'];
        }
        return $out;
    }
}
