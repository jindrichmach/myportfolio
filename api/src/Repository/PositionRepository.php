<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Support\Decimal;
use MyPortfolio\Service\Sharing\AccessContext;
use PDO;

/**
 * Pozice = instrument (nebo ručně oceňovaná hodnota) na účtu v portfoliu.
 * Každý dotaz je scopovaný na user_id.
 */
final class PositionRepository
{
    private const SELECT = 'SELECT pos.id, pos.portfolio_id, pf.name AS portfolio_name,
                                   pos.account_id, acc.name AS account_name, inst.name AS institution_name, inst.type AS institution_type,
                                   pos.instrument_id, ins.symbol AS instrument_symbol, ins.name AS instrument_name,
                                   ins.asset_class, ins.quote_currency, ins.decimals AS instrument_decimals,
                                   pos.valuation_mode, pos.is_cash, pos.name, pos.currency, pos.interest_rate_pa, pos.bank_account, pos.bank_notices_from,
                                   pos.archived_at, pos.created_at,
                                   CONCAT(\'/api/icons/\', inst.icon) AS institution_icon_url, CONCAT(\'/api/icons/\', ins.icon) AS instrument_icon_url
                              FROM positions pos
                              JOIN portfolios pf ON pf.id = pos.portfolio_id
                              JOIN accounts acc ON acc.id = pos.account_id
                              JOIN institutions inst ON inst.id = acc.institution_id
                         LEFT JOIN instruments ins ON ins.id = pos.instrument_id';

    public function __construct(
        private readonly Connection $db,
        private readonly AccessContext $access,
    ) {}

    /**
     * @param array{portfolio_id?:?int,account_id?:?int,archived?:bool} $filter
     * @return list<array<string,mixed>>
     */
    public function list(int $userId, array $filter = []): array
    {
        $where = ['pos.user_id = ?'];
        $params = [$userId];
        if (!empty($filter['portfolio_id'])) {
            $where[] = 'pos.portfolio_id = ?';
            $params[] = $filter['portfolio_id'];
        }
        if (!empty($filter['account_id'])) {
            $where[] = 'pos.account_id = ?';
            $params[] = $filter['account_id'];
        }
        if (empty($filter['archived'])) {
            $where[] = 'pos.archived_at IS NULL';
        }
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE ' . implode(' AND ', $where) . $this->access->sqlIn($userId, 'pos.portfolio_id')
            . ' ORDER BY pf.sort, pf.name, pos.archived_at IS NOT NULL, inst.name, acc.name, COALESCE(ins.symbol, pos.name)'
        );
        $stmt->execute($params);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE pos.user_id = ? AND pos.id = ?' . $this->access->sqlIn($userId, 'pos.portfolio_id'));
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** Zamkne řádek pozice (FOR UPDATE) — serializuje souběžné zápisy transakcí téže pozice. */
    public function lock(int $userId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM positions WHERE user_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$userId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    public function duplicateExists(int $userId, int $portfolioId, int $accountId, ?int $instrumentId, ?int $exceptId = null): bool
    {
        if ($instrumentId === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM positions WHERE user_id = ? AND portfolio_id = ? AND account_id = ? AND instrument_id = ? AND id <> ?'
        );
        $stmt->execute([$userId, $portfolioId, $accountId, $instrumentId, $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    /** Tržní pozici určuje instrument, ruční (bez instrumentu) název. */
    public function findId(int $userId, int $portfolioId, int $accountId, ?int $instrumentId, ?string $name): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM positions WHERE user_id = ? AND portfolio_id = ? AND account_id = ? AND instrument_id <=> ?
                AND (instrument_id IS NOT NULL OR name = ?)'
        );
        $stmt->execute([$userId, $portfolioId, $accountId, $instrumentId, $name]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array<string,mixed> $data */
    public function create(int $userId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO positions (user_id, portfolio_id, account_id, instrument_id, valuation_mode, is_cash, name, currency, interest_rate_pa, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $data['portfolio_id'], $data['account_id'], $data['instrument_id'], $data['valuation_mode'],
            !empty($data['is_cash']) ? 1 : 0, $data['name'], $data['currency'], $data['interest_rate_pa'], $this->access->createdBy($userId),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $userId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE positions SET portfolio_id = ?, account_id = ?, name = ?, interest_rate_pa = ?,
                    archived_at = IF(?, COALESCE(archived_at, CURRENT_TIMESTAMP), NULL)
              WHERE user_id = ? AND id = ?'
        )->execute([
            $data['portfolio_id'], $data['account_id'], $data['name'], $data['interest_rate_pa'],
            $data['archived'] ? 1 : 0, $userId, $id,
        ]);
    }

    /** Napojení ruční pozice na bankovní avíza (null = odpojit). */
    public function setBankLink(int $userId, int $id, ?string $bankAccount, ?string $noticesFrom): void
    {
        $this->db->pdo()->prepare(
            'UPDATE positions SET bank_account = ?, bank_notices_from = ? WHERE user_id = ? AND id = ? AND valuation_mode = \'manual\''
        )->execute([$bankAccount, $bankAccount !== null ? $noticesFrom : null, $userId, $id]);
    }

    /**
     * Nearchivované ruční pozice s číslem účtu pro avíza.
     *
     * @return list<array<string,mixed>>
     */
    public function withBankAccount(int $userId): array
    {
        return array_values(array_filter(
            $this->list($userId),
            static fn (array $p) => $p['valuation_mode'] === 'manual' && $p['bank_account'] !== null,
        ));
    }

    public function hasTransactions(int $userId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM transactions WHERE user_id = ? AND position_id = ? LIMIT 1');
        $stmt->execute([$userId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    public function delete(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM positions WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        foreach (['id', 'portfolio_id', 'account_id'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['instrument_id'] = $row['instrument_id'] !== null ? (int) $row['instrument_id'] : null;
        $row['instrument_decimals'] = $row['instrument_decimals'] !== null ? (int) $row['instrument_decimals'] : null;
        $row['interest_rate_pa'] = Decimal::out($row['interest_rate_pa']);
        $row['is_cash'] = (bool) ($row['is_cash'] ?? false);
        $row['display_name'] = $row['instrument_symbol'] ?? $row['name'];
        return $row;
    }
}
