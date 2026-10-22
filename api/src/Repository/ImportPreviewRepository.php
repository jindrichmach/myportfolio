<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/** Rozparsovaný soubor importu mezi náhledem a potvrzením (platnost 24 h). Scopované na user_id. */
final class ImportPreviewRepository
{
    public const TTL_HOURS = 24;

    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $payload */
    public function save(int $userId, string $token, string $source, string $filename, int $accountId, int $portfolioId, array $payload): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO import_previews (token, user_id, source, filename, account_id, portfolio_id, payload) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$token, $userId, $source, mb_substr($filename, 0, 255), $accountId, $portfolioId,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT token, source, filename, account_id, portfolio_id, payload, created_at FROM import_previews
              WHERE user_id = ? AND token = ? AND created_at > NOW() - INTERVAL ' . self::TTL_HOURS . ' HOUR'
        );
        $stmt->execute([$userId, $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['account_id'] = (int) $row['account_id'];
        $row['portfolio_id'] = (int) $row['portfolio_id'];
        $row['payload'] = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }

    public function delete(int $userId, string $token): void
    {
        $this->db->pdo()->prepare('DELETE FROM import_previews WHERE user_id = ? AND token = ?')->execute([$userId, $token]);
    }
}
