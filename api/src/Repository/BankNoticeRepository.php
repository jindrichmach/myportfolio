<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/** Protokol zpracovaných bankovních avíz (deduplikace + přehled v UI). Scopované na user_id. */
final class BankNoticeRepository
{
    public function __construct(private readonly Connection $db) {}

    public function isKnown(int $userId, int $imapAccountId, ?string $messageId, string $hash): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM bank_notice_messages
              WHERE user_id = ? AND imap_account_id = ? AND (fallback_hash = ? OR (message_id IS NOT NULL AND message_id = ?))
              LIMIT 1'
        );
        $stmt->execute([$userId, $imapAccountId, $hash, $messageId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $data */
    public function record(int $userId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO bank_notice_messages (user_id, imap_account_id, message_id, fallback_hash, message_date, sender, subject,
                                               parser, status, position_id, transaction_id, payload, error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $data['imap_account_id'], $data['message_id'], $data['fallback_hash'], $data['message_date'],
            mb_substr((string) $data['sender'], 0, 255), mb_substr((string) $data['subject'], 0, 500),
            $data['parser'] ?? null, $data['status'], $data['position_id'] ?? null, $data['transaction_id'] ?? null,
            isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            isset($data['error']) ? mb_substr((string) $data['error'], 0, 1000) : null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<array<string,mixed>> nejnovější první */
    public function list(int $userId, int $limit = 100): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT m.id, m.imap_account_id, a.name AS imap_account_name, m.message_date, m.sender, m.subject, m.parser, m.status,
                    m.position_id, COALESCE(p.name, CONCAT(\'#\', m.position_id)) AS position_name, m.transaction_id, m.payload, m.error, m.created_at
               FROM bank_notice_messages m
               JOIN imap_accounts a ON a.id = m.imap_account_id
          LEFT JOIN positions p ON p.id = m.position_id
              WHERE m.user_id = ?
              ORDER BY m.created_at DESC, m.id DESC
              LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$userId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['imap_account_id'] = (int) $row['imap_account_id'];
            $row['position_id'] = $row['position_id'] !== null ? (int) $row['position_id'] : null;
            $row['transaction_id'] = $row['transaction_id'] !== null ? (int) $row['transaction_id'] : null;
            $row['payload'] = $row['payload'] !== null ? json_decode((string) $row['payload'], true) : null;
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
