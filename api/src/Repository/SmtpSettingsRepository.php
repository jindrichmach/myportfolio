<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/** Globální nastavení SMTP z administrace — jediný řádek `id = 1`, heslo zašifrované. */
final class SmtpSettingsRepository
{
    private const COLUMNS = 'enabled, host, port, encryption, verify_peer, auth_enabled, username, password_enc, from_email, from_name,
                             reply_to_email, reply_to_name, last_test_at, last_test_status, last_test_message, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null včetně `password_enc` */
    public function get(): ?array
    {
        try {
            $row = $this->db->pdo()->query('SELECT ' . self::COLUMNS . ' FROM smtp_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null; // tabulka ještě neexistuje (před migrací) → platí cfg.php
        }
        if ($row === false) {
            return null;
        }
        foreach (['enabled', 'verify_peer', 'auth_enabled'] as $k) {
            $row[$k] = (bool) $row[$k];
        }
        $row['port'] = (int) $row['port'];
        return $row;
    }

    /** @param array<string,mixed> $data `password_enc` null = heslo beze změny */
    public function save(array $data, int $userId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO smtp_settings (id, enabled, host, port, encryption, verify_peer, auth_enabled, username, password_enc,
                                        from_email, from_name, reply_to_email, reply_to_name, updated_by_user_id)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), host = VALUES(host), port = VALUES(port), encryption = VALUES(encryption),
                    verify_peer = VALUES(verify_peer), auth_enabled = VALUES(auth_enabled), username = VALUES(username),
                    password_enc = COALESCE(VALUES(password_enc), password_enc), from_email = VALUES(from_email),
                    from_name = VALUES(from_name), reply_to_email = VALUES(reply_to_email), reply_to_name = VALUES(reply_to_name),
                    updated_by_user_id = VALUES(updated_by_user_id)'
        )->execute([
            $data['enabled'] ? 1 : 0, $data['host'], $data['port'], $data['encryption'], $data['verify_peer'] ? 1 : 0,
            $data['auth_enabled'] ? 1 : 0, $data['username'], $data['password_enc'], $data['from_email'], $data['from_name'],
            $data['reply_to_email'], $data['reply_to_name'], $userId,
        ]);
    }

    public function recordTest(string $status, string $message): void
    {
        $this->db->pdo()->prepare(
            'UPDATE smtp_settings SET last_test_at = CURRENT_TIMESTAMP, last_test_status = ?, last_test_message = ? WHERE id = 1'
        )->execute([$status, mb_substr($message, 0, 500)]);
    }
}
