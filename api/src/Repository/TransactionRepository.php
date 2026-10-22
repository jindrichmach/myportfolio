<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Support\Decimal;
use MyPortfolio\Service\Sharing\AccessContext;
use PDO;

/**
 * Transakce — zdroj pravdy pro držené množství, loty i P&L. Každý dotaz je scopovaný na user_id.
 */
final class TransactionRepository
{
    private const COLUMNS = ['type', 'executed_at', 'quantity', 'unit_price', 'price_currency', 'amount',
        'fee', 'fee_currency', 'split_ratio', 'note'];

    private const DECIMALS = ['quantity', 'unit_price', 'amount', 'fee', 'fx_rate_czk', 'split_ratio'];

    /** Povolené sloupce pro `sort` (klíč z API → SQL výraz). */
    private const SORTS = [
        'executed_at' => 't.executed_at',
        'type'        => 't.type',
        'amount'      => 't.amount',
        'quantity'    => 't.quantity',
        'id'          => 't.id',
    ];

    private const SELECT = 'SELECT t.id, t.position_id, t.type, t.executed_at, t.quantity, t.unit_price, t.price_currency,
                                   t.amount, t.fee, t.fee_currency, t.fx_rate_czk, t.split_ratio, t.transfer_group_id,
                                   t.note, t.source, t.external_id, t.import_batch_id, t.created_at, t.created_by_user_id, cu.name AS created_by_name,
                                   pos.portfolio_id, pf.name AS portfolio_name, pos.account_id, acc.name AS account_name,
                                   inst.name AS institution_name, pos.valuation_mode,
                                   COALESCE(ins.symbol, pos.name) AS position_name, ins.asset_class,
                                   CONCAT(\'/api/icons/\', inst.icon) AS institution_icon_url, CONCAT(\'/api/icons/\', ins.icon) AS instrument_icon_url
                              FROM transactions t
                              JOIN positions pos ON pos.id = t.position_id
                              JOIN portfolios pf ON pf.id = pos.portfolio_id
                              JOIN accounts acc ON acc.id = pos.account_id
                              JOIN institutions inst ON inst.id = acc.institution_id
                         LEFT JOIN instruments ins ON ins.id = pos.instrument_id
                         LEFT JOIN users cu ON cu.id = t.created_by_user_id';

    public function __construct(
        private readonly Connection $db,
        private readonly AccessContext $access,
    ) {}

    /**
     * Surové transakce pozice pro HoldingCalculator (bez joinů).
     *
     * @return list<array<string,mixed>>
     */
    public function forPosition(int $userId, int $positionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, type, executed_at, quantity, amount, split_ratio, fee, fee_currency
               FROM transactions WHERE user_id = ? AND position_id = ?
              ORDER BY executed_at, id'
        );
        $stmt->execute([$userId, $positionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Surové transakce všech tržních pozic instrumentu uživatele (pro LotEngine).
     *
     * @return list<array<string,mixed>>
     */
    public function forInstrument(int $userId, int $instrumentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.position_id, t.type, t.executed_at, t.quantity, t.unit_price, t.price_currency, t.amount,
                    t.fee, t.fee_currency, t.split_ratio, t.transfer_group_id
               FROM transactions t
               JOIN positions pos ON pos.id = t.position_id
              WHERE t.user_id = ? AND pos.instrument_id = ? AND pos.valuation_mode = \'market\'
              ORDER BY t.executed_at, t.id'
        );
        $stmt->execute([$userId, $instrumentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Peněžní toky všech pozic uživatele pro ocenění (bez joinů).
     *
     * @return list<array<string,mixed>>
     */
    public function cashFlows(int $userId, ?int $positionId = null): array
    {
        $sql = 'SELECT id, position_id, type, executed_at, amount, price_currency, fee, fee_currency
                  FROM transactions
                 WHERE user_id = ? AND type IN (\'deposit\', \'withdrawal\', \'dividend\', \'interest\', \'fee\', \'transfer_out\', \'transfer_in\')';
        $params = [$userId];
        if ($positionId !== null) {
            $sql .= ' AND position_id = ?';
            $params[] = $positionId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY executed_at, id');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Čas posledního pohybu na pozicích účtu (navázání synchronizace burzy na dřívější import). */
    /** Den první transakce uživatele (od kdy potřebuje historii cen benchmarku). */
    public function firstDate(int $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT MIN(DATE(executed_at)) FROM transactions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $v = $stmt->fetchColumn();
        return $v !== null && $v !== false ? (string) $v : null;
    }

    public function lastExecutedAtForAccount(int $userId, int $accountId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(t.executed_at) FROM transactions t JOIN positions p ON p.id = t.position_id AND p.user_id = t.user_id
              WHERE t.user_id = ? AND p.account_id = ?'
        );
        $stmt->execute([$userId, $accountId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null ? (string) $value : null;
    }

    /**
     * Dividendy, úroky, poplatky a transakce s poplatkem — pro souhrny a přehled příjmů.
     *
     * @return list<array<string,mixed>>
     */
    public function incomeAndFees(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.position_id, t.type, t.executed_at, t.amount, t.price_currency, t.fee, t.fee_currency, t.note,
                    pos.portfolio_id, pos.account_id, pos.name AS position_name, acc.name AS account_name, inst.name AS institution_name,
                    ins.symbol, ins.name AS instrument_name, ins.isin, ins.asset_class
               FROM transactions t
               JOIN positions pos ON pos.id = t.position_id AND pos.user_id = t.user_id
               JOIN accounts acc ON acc.id = pos.account_id
               JOIN institutions inst ON inst.id = acc.institution_id
          LEFT JOIN instruments ins ON ins.id = pos.instrument_id
              WHERE t.user_id = ? AND (t.type IN (\'dividend\', \'interest\', \'fee\') OR t.fee IS NOT NULL)
              ORDER BY t.executed_at, t.id'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{position_id?:?int,portfolio_id?:?int,account_id?:?int,type?:?string,from?:?string,to?:?string} $filter
     * @return array{data:list<array<string,mixed>>,meta:array{total:int,page:int,per_page:int,pages:int}}
     */
    public function list(int $userId, array $filter, int $page, int $perPage, string $sort): array
    {
        $where = ['t.user_id = ?'];
        $params = [$userId];
        foreach (['position_id' => 't.position_id', 'portfolio_id' => 'pos.portfolio_id', 'account_id' => 'pos.account_id'] as $key => $col) {
            if (!empty($filter[$key])) {
                $where[] = "$col = ?";
                $params[] = (int) $filter[$key];
            }
        }
        if (!empty($filter['type'])) {
            $where[] = 't.type = ?';
            $params[] = $filter['type'];
        }
        if (!empty($filter['from'])) {
            $where[] = 't.executed_at >= ?';
            $params[] = $filter['from'] . ' 00:00:00';
        }
        if (!empty($filter['to'])) {
            $where[] = 't.executed_at <= ?';
            $params[] = $filter['to'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where) . $this->access->sqlIn($userId, 'pos.portfolio_id');

        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM transactions t JOIN positions pos ON pos.id = t.position_id WHERE ' . $whereSql
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE ' . $whereSql . ' ORDER BY ' . self::orderBy($sort)
            . sprintf(' LIMIT %d OFFSET %d', $perPage, $offset)
        );
        $stmt->execute($params);

        return [
            'data' => array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE t.user_id = ? AND t.id = ?' . $this->access->sqlIn($userId, 'pos.portfolio_id'));
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<int> ID obou nohou převodu */
    public function idsInTransferGroup(int $userId, string $groupId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM transactions WHERE user_id = ? AND transfer_group_id = ? ORDER BY id');
        $stmt->execute([$userId, $groupId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<string> $externalIds
     * @return list<string> ta z $externalIds, která už u uživatele pro daný zdroj existují
     */
    public function existingExternalIds(int $userId, string $source, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT external_id FROM transactions WHERE user_id = ? AND source = ? AND external_id IN ('
            . implode(', ', array_fill(0, count($externalIds), '?')) . ')'
        );
        $stmt->execute([$userId, $source, ...$externalIds]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> transakce dávky importu (id, position_id, executed_at, transfer_group_id) */
    public function forBatch(int $userId, int $batchId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, position_id, executed_at, transfer_group_id FROM transactions WHERE user_id = ? AND import_batch_id = ? ORDER BY id'
        );
        $stmt->execute([$userId, $batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function idByExternalId(int $userId, string $source, string $externalId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM transactions WHERE user_id = ? AND source = ? AND external_id = ?');
        $stmt->execute([$userId, $source, $externalId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * @param array<string,?string> $data výstup TransactionValidator::normalize()['data']
     * @param array{transfer_group_id?:?string,source?:string,external_id?:?string,import_batch_id?:?int} $meta
     */
    public function insert(int $userId, int $positionId, array $data, array $meta = []): int
    {
        $columns = ['user_id', 'position_id', ...self::COLUMNS, 'transfer_group_id', 'source', 'external_id', 'import_batch_id', 'created_by_user_id'];
        $values = [$userId, $positionId];
        foreach (self::COLUMNS as $col) {
            $values[] = $data[$col] ?? null;
        }
        array_push(
            $values,
            $meta['transfer_group_id'] ?? null,
            $meta['source'] ?? 'manual',
            $meta['external_id'] ?? null,
            $meta['import_batch_id'] ?? null,
            $this->access->createdBy($userId),
        );
        $this->db->pdo()->prepare(
            'INSERT INTO transactions (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        )->execute($values);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,?string> $data */
    public function update(int $userId, int $id, array $data): void
    {
        $sets = [];
        $values = [];
        foreach (self::COLUMNS as $col) {
            $sets[] = "$col = ?";
            $values[] = $data[$col] ?? null;
        }
        array_push($values, $userId, $id);
        $this->db->pdo()
            ->prepare('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE user_id = ? AND id = ?')
            ->execute($values);
    }

    /** @param list<int> $ids */
    public function delete(int $userId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->db->pdo()->prepare(
            'DELETE FROM transactions WHERE user_id = ? AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
        )->execute([$userId, ...$ids]);
    }

    /** `-executed_at,id` → `t.executed_at DESC, t.id ASC`; neznámé sloupce se ignorují. */
    private static function orderBy(string $sort): string
    {
        $parts = [];
        foreach (explode(',', $sort) as $token) {
            $token = trim($token);
            $desc = str_starts_with($token, '-');
            $key = ltrim($token, '-');
            if (isset(self::SORTS[$key])) {
                $parts[] = self::SORTS[$key] . ($desc ? ' DESC' : ' ASC');
            }
        }
        if ($parts === []) {
            $parts[] = 't.executed_at DESC';
        }
        $parts[] = 't.id DESC';
        return implode(', ', $parts);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        foreach (['id', 'position_id', 'portfolio_id', 'account_id'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['import_batch_id'] = $row['import_batch_id'] !== null ? (int) $row['import_batch_id'] : null;
        foreach (self::DECIMALS as $k) {
            $row[$k] = Decimal::out($row[$k]);
        }
        return $row;
    }
}
