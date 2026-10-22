<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * Globální katalog instrumentů (krypto, ETF, akcie, fondy, hotovost).
 * `source_refs` = identifikátory u zdrojů cen, např. {"coingecko":"bitcoin","yahoo":"CSPX.L"}.
 */
final class InstrumentRepository
{
    public const ASSET_CLASSES = ['crypto', 'etf', 'stock', 'fund', 'cash'];
    public const PRICE_SOURCES = ['coingecko', 'xtb', 'yahoo', 'manual'];

    private const COLUMNS = 'id, asset_class, symbol, name, isin, quote_currency, decimals, price_source, source_refs, icon, created_by_user_id, is_active';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(bool $includeInactive, ?string $query = null, ?string $assetClass = null): array
    {
        $where = [];
        $params = [];
        if (!$includeInactive) {
            $where[] = 'is_active = 1';
        }
        if ($assetClass !== null) {
            $where[] = 'asset_class = ?';
            $params[] = $assetClass;
        }
        if ($query !== null && $query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $where[] = '(symbol LIKE ? OR name LIKE ? OR isin LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM instruments'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY asset_class, symbol, quote_currency LIMIT 500';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM instruments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function exists(string $assetClass, string $symbol, string $quoteCurrency, ?int $exceptId = null): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM instruments WHERE asset_class = ? AND symbol = ? AND quote_currency = ? AND id <> ?'
        );
        $stmt->execute([$assetClass, $symbol, $quoteCurrency, $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Kandidáti pro import: shoda ISIN, jinak symbolu v daných třídách aktiv (aktivní první).
     *
     * @param list<string> $classes
     * @return list<array<string,mixed>>
     */
    public function match(?string $isin, string $symbol, array $classes): array
    {
        if ($isin !== null && $isin !== '') {
            $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM instruments WHERE isin = ? ORDER BY is_active DESC, id');
            $stmt->execute([strtoupper($isin)]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== []) {
                return array_map([self::class, 'hydrate'], $rows);
            }
        }
        if ($symbol === '' || $classes === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($classes), '?'));
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . " FROM instruments WHERE symbol = ? AND asset_class IN ($in) ORDER BY is_active DESC, id");
        $stmt->execute([strtoupper($symbol), ...$classes]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findId(string $assetClass, string $symbol, string $quoteCurrency): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM instruments WHERE asset_class = ? AND symbol = ? AND quote_currency = ?');
        $stmt->execute([$assetClass, $symbol, $quoteCurrency]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, int $createdByUserId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO instruments (asset_class, symbol, name, isin, quote_currency, decimals, price_source, source_refs, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['asset_class'], $data['symbol'], $data['name'], $data['isin'], $data['quote_currency'],
            $data['decimals'], $data['price_source'], self::encodeRefs($data['source_refs']), $createdByUserId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE instruments SET asset_class = ?, symbol = ?, name = ?, isin = ?, quote_currency = ?, decimals = ?,
                    price_source = ?, source_refs = ?, is_active = ?
              WHERE id = ?'
        )->execute([
            $data['asset_class'], $data['symbol'], $data['name'], $data['isin'], $data['quote_currency'],
            $data['decimals'], $data['price_source'], self::encodeRefs($data['source_refs']),
            $data['is_active'] ? 1 : 0, $id,
        ]);
    }

    public function setIcon(int $id, ?string $icon): void
    {
        $this->db->pdo()->prepare('UPDATE instruments SET icon = ? WHERE id = ?')->execute([$icon, $id]);
    }

    public function usesIcon(string $icon): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM instruments WHERE icon = ? LIMIT 1');
        $stmt->execute([$icon]);
        return $stmt->fetchColumn() !== false;
    }

    public function isUsed(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM positions WHERE instrument_id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    public function delete(int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM instruments WHERE id = ?')->execute([$id]);
    }

    /** @param array<string,string> $refs */
    private static function encodeRefs(array $refs): ?string
    {
        return $refs === [] ? null : json_encode($refs, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['decimals'] = (int) $row['decimals'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['created_by_user_id'] = $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null;
        $refs = $row['source_refs'] !== null ? json_decode((string) $row['source_refs'], true) : null;
        $row['source_refs'] = is_array($refs) ? $refs : (object) [];
        return $row;
    }
}
