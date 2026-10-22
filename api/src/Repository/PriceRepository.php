<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Support\Decimal;
use PDO;

/**
 * Denní ceny instrumentů (uzavírací; dnešní přepisuje hodinový cron). Globální data.
 */
final class PriceRepository
{
    public function __construct(private readonly Connection $db) {}

    public function upsert(int $instrumentId, string $date, string $close, string $currency, string $source): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE close = VALUES(close), currency = VALUES(currency), source = VALUES(source)'
        )->execute([$instrumentId, $date, $close, $currency, $source]);
    }

    /** Vloží jen chybějící dny — existující (včetně ručních) nepřepisuje. */
    public function insertMissing(int $instrumentId, string $date, string $close, string $currency, string $source): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO prices (instrument_id, date, close, currency, source) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$instrumentId, $date, $close, $currency, $source]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{date:string,close:string,currency:string,source:string}|null */
    public function latest(int $instrumentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT date, close, currency, source FROM prices WHERE instrument_id = ? ORDER BY date DESC LIMIT 1'
        );
        $stmt->execute([$instrumentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @param list<int> $instrumentIds
     * @return array<int,array{date:string,close:string,currency:string,source:string}>
     */
    public function latestFor(array $instrumentIds): array
    {
        if ($instrumentIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($instrumentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.instrument_id, p.date, p.close, p.currency, p.source
               FROM prices p
               JOIN (SELECT instrument_id, MAX(date) AS max_date FROM prices WHERE instrument_id IN ($in) GROUP BY instrument_id) m
                 ON m.instrument_id = p.instrument_id AND m.max_date = p.date"
        );
        $stmt->execute($instrumentIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['instrument_id']] = self::hydrate($row);
        }
        return $out;
    }

    /**
     * Poslední cena k datu (nejvýše `$notBefore`) — ocenění odměn ke dni připsání.
     *
     * @return array{date:string,close:string,currency:string,source:string}|null
     */
    public function onOrBefore(int $instrumentId, string $date, string $notBefore): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT date, close, currency, source FROM prices
              WHERE instrument_id = ? AND date <= ? AND date >= ? ORDER BY date DESC LIMIT 1'
        );
        $stmt->execute([$instrumentId, $date, $notBefore]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<array{date:string,close:string,currency:string,source:string}> */
    public function history(int $instrumentId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT date, close, currency, source FROM prices WHERE instrument_id = ? AND date BETWEEN ? AND ? ORDER BY date'
        );
        $stmt->execute([$instrumentId, $from, $to]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Mezery uvnitř historie ceny od `$from`: dvojice po sobě jdoucích dní s cenou vzdálené víc než `$maxDays`.
     *
     * @return list<array{from:string,to:string}> chybějící dny (včetně)
     */
    public function gaps(int $instrumentId, string $from, int $maxDays): array
    {
        $stmt = $this->db->pdo()->prepare(
            'WITH d AS (SELECT date, LAG(date) OVER (ORDER BY date) AS prev FROM prices WHERE instrument_id = ? AND date >= ?)
             SELECT prev + INTERVAL 1 DAY AS gap_from, date - INTERVAL 1 DAY AS gap_to FROM d
              WHERE prev IS NOT NULL AND DATEDIFF(date, prev) > ? ORDER BY date'
        );
        $stmt->execute([$instrumentId, $from, $maxDays]);
        return array_map(static fn (array $r) => ['from' => (string) $r['gap_from'], 'to' => (string) $r['gap_to']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{min:?string,max:?string,count:int} */
    public function coverage(int $instrumentId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT MIN(date), MAX(date), COUNT(*) FROM prices WHERE instrument_id = ?');
        $stmt->execute([$instrumentId]);
        [$min, $max, $count] = $stmt->fetch(PDO::FETCH_NUM);
        return ['min' => $min, 'max' => $max, 'count' => (int) $count];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{date:string,close:string,currency:string,source:string}
     */
    private static function hydrate(array $row): array
    {
        return [
            'date'     => (string) $row['date'],
            'close'    => (string) Decimal::out((string) $row['close']),
            'currency' => (string) $row['currency'],
            'source'   => (string) $row['source'],
        ];
    }
}
