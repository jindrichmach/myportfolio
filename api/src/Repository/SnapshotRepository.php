<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * Denní snapshoty pozic (odvozená data, SnapshotService) a fronta pozic k přepočtu.
 * Každý dotaz je scopovaný na user_id.
 */
final class SnapshotRepository
{
    public const CURRENCIES = ['CZK', 'EUR', 'USD'];

    private const COLUMNS = ['quantity', 'value_czk', 'value_eur', 'value_usd', 'invested_czk', 'invested_eur',
        'invested_usd', 'flow_czk', 'flow_eur', 'flow_usd', 'is_stale'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Nahradí snapshoty pozice od data `$from` (včetně) novými řádky.
     *
     * @param list<array<string,mixed>> $rows klíče `date` + COLUMNS
     */
    public function replaceFrom(int $userId, int $positionId, string $from, array $rows): void
    {
        $this->db->pdo()->prepare('DELETE FROM daily_snapshots WHERE user_id = ? AND position_id = ? AND date >= ?')
            ->execute([$userId, $positionId, $from]);
        foreach (array_chunk($rows, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, 3 + count(self::COLUMNS), '?')) . ')'));
            $params = [];
            foreach ($chunk as $row) {
                array_push($params, $userId, $positionId, $row['date']);
                foreach (self::COLUMNS as $col) {
                    $params[] = $row[$col] ?? null;
                }
            }
            $this->db->pdo()->prepare(
                'INSERT INTO daily_snapshots (user_id, position_id, date, ' . implode(', ', self::COLUMNS) . ") VALUES $placeholders"
            )->execute($params);
        }
    }

    /**
     * Denní součty vybraných pozic v měně `$currency`.
     *
     * @param list<int> $positionIds
     * @return list<array{date:string,value:?string,invested:?string,flow:string,missing:int,stale:int}>
     */
    public function series(int $userId, array $positionIds, string $currency, string $from, string $to): array
    {
        if ($positionIds === [] || !in_array($currency, self::CURRENCIES, true)) {
            return [];
        }
        $c = strtolower($currency);
        $in = implode(',', array_fill(0, count($positionIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT date, SUM(value_$c) AS value, SUM(invested_$c) AS invested, SUM(flow_$c) AS flow,
                    SUM(value_$c IS NULL) AS missing, SUM(is_stale) AS stale
               FROM daily_snapshots
              WHERE user_id = ? AND position_id IN ($in) AND date BETWEEN ? AND ?
              GROUP BY date ORDER BY date"
        );
        $stmt->execute([$userId, ...$positionIds, $from, $to]);
        return array_map(static fn (array $r) => [
            'date'     => (string) $r['date'],
            'value'    => $r['value'],
            'invested' => $r['invested'],
            'flow'     => (string) $r['flow'],
            'missing'  => (int) $r['missing'],
            'stale'    => (int) $r['stale'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Měsíční řady vybraných pozic: hodnota k poslednímu dni měsíce (u běžného měsíce k poslednímu snapshotu)
     * a součet toků v měsíci. `missing` = některá pozice nemá k tomu dni cenu nebo kurz.
     *
     * @param list<int> $positionIds
     * @return list<array{month:string,last_date:string,value:string,flow:string,missing:int}>
     */
    public function monthly(int $userId, array $positionIds, string $currency): array
    {
        if ($positionIds === [] || !in_array($currency, self::CURRENCIES, true)) {
            return [];
        }
        $c = strtolower($currency);
        $in = implode(',', array_fill(0, count($positionIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "WITH s AS (
                SELECT DATE_FORMAT(date, '%Y-%m') AS month, date, value_$c AS value, flow_$c AS flow,
                       ROW_NUMBER() OVER (PARTITION BY position_id, DATE_FORMAT(date, '%Y-%m') ORDER BY date DESC) AS rn
                  FROM daily_snapshots
                 WHERE user_id = ? AND position_id IN ($in)
             )
             SELECT month, MAX(date) AS last_date, COALESCE(SUM(CASE WHEN rn = 1 THEN value END), 0) AS value,
                    SUM(flow) AS flow, SUM(rn = 1 AND value IS NULL) AS missing
               FROM s GROUP BY month ORDER BY month"
        );
        $stmt->execute([$userId, ...$positionIds]);
        return array_map(static fn (array $r) => [
            'month'     => (string) $r['month'],
            'last_date' => (string) $r['last_date'],
            'value'     => (string) $r['value'],
            'flow'      => (string) $r['flow'],
            'missing'   => (int) $r['missing'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Per pozice: hodnota ke dni `$from` a `$to` a součet toků ve dnech ($from, $to] v měně `$currency`.
     * Pozice bez snapshotu k `$from` ještě neexistovala (hodnota 0); `*_missing` = chybí cena/kurz.
     *
     * @return array<int,array{value_from:?string,from_missing:bool,value_to:?string,to_missing:bool,has_to:bool,flow:string}>
     */
    public function periodByPosition(int $userId, string $currency, string $from, string $to): array
    {
        if (!in_array($currency, self::CURRENCIES, true)) {
            return [];
        }
        $c = strtolower($currency);
        $stmt = $this->db->pdo()->prepare(
            "SELECT position_id,
                    MAX(CASE WHEN date = ? THEN value_$c END) AS value_from,
                    MAX(CASE WHEN date = ? AND value_$c IS NULL THEN 1 ELSE 0 END) AS from_missing,
                    MAX(CASE WHEN date = ? THEN value_$c END) AS value_to,
                    MAX(CASE WHEN date = ? AND value_$c IS NULL THEN 1 ELSE 0 END) AS to_missing,
                    MAX(CASE WHEN date = ? THEN 1 ELSE 0 END) AS has_to,
                    SUM(CASE WHEN date > ? THEN flow_$c ELSE 0 END) AS flow
               FROM daily_snapshots
              WHERE user_id = ? AND date BETWEEN ? AND ?
              GROUP BY position_id"
        );
        $stmt->execute([$from, $from, $to, $to, $to, $from, $userId, $from, $to]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['position_id']] = [
                'value_from'   => $r['value_from'],
                'from_missing' => (bool) $r['from_missing'],
                'value_to'     => $r['value_to'],
                'to_missing'   => (bool) $r['to_missing'],
                'has_to'       => (bool) $r['has_to'],
                'flow'         => (string) $r['flow'],
            ];
        }
        return $out;
    }

    /** Poslední den se snapshotem po pozicích uživatele. @return array<int,string> */
    public function lastDates(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT position_id, MAX(date) FROM daily_snapshots WHERE user_id = ? GROUP BY position_id');
        $stmt->execute([$userId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** Snapshot pozice k datu (pro test shody s Přehledem a ladění). */
    public function find(int $userId, int $positionId, string $date): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM daily_snapshots WHERE user_id = ? AND position_id = ? AND date = ?');
        $stmt->execute([$userId, $positionId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<int> pozice uživatele, které ještě nemají žádný snapshot */
    public function positionsWithoutSnapshots(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id FROM positions p
              WHERE p.user_id = ? AND EXISTS (SELECT 1 FROM transactions t WHERE t.position_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM daily_snapshots s WHERE s.position_id = p.id)'
        );
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Nejstarší den se snapshotem mezi pozicemi. */
    public function firstDate(int $userId, array $positionIds): ?string
    {
        if ($positionIds === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($positionIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT MIN(date) FROM daily_snapshots WHERE user_id = ? AND position_id IN ($in)");
        $stmt->execute([$userId, ...$positionIds]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null ? (string) $v : null;
    }

    /** Označí pozici k přepočtu od data (drží nejstarší datum). */
    public function markDirty(int $userId, int $positionId, string $fromDate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO snapshot_dirty (position_id, user_id, from_date) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE from_date = LEAST(from_date, VALUES(from_date))'
        )->execute([$positionId, $userId, $fromDate]);
    }

    /** @return array<int,string> position_id → from_date */
    public function dirty(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT position_id, from_date FROM snapshot_dirty WHERE user_id = ?');
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['position_id']] = (string) $row['from_date'];
        }
        return $out;
    }

    /** Odebere z fronty, pokud mezitím nepřibyla starší změna. */
    public function clearDirty(int $userId, int $positionId, string $processedFrom): void
    {
        $this->db->pdo()->prepare('DELETE FROM snapshot_dirty WHERE user_id = ? AND position_id = ? AND from_date >= ?')
            ->execute([$userId, $positionId, $processedFrom]);
    }
}
