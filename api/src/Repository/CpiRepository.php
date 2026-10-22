<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/** Měsíční index spotřebitelských cen (globální číselník, plní cron-cpi). */
final class CpiRepository
{
    public function __construct(private readonly Connection $db) {}

    public function upsert(string $month, string $value, string $source): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO cpi_index (month, value, source) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), source = VALUES(source)'
        );
        $stmt->execute([$month, $value, $source]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,string> měsíc → index */
    public function all(): array
    {
        return array_map('strval', $this->db->pdo()->query('SELECT month, value FROM cpi_index ORDER BY month')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public function latestMonth(): ?string
    {
        $v = $this->db->pdo()->query('SELECT MAX(month) FROM cpi_index')->fetchColumn();
        return $v !== null && $v !== false ? (string) $v : null;
    }
}
