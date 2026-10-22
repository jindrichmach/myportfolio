<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * IMAP schránky pro bankovní avíza. Scopované na user_id; zašifrované heslo vrací jen
 * `withSecret*` metody (pro skener), nikdy seznam pro API.
 */
final class ImapAccountRepository
{
    private const COLUMNS = 'id, name, enabled, host, port, encryption, validate_cert, username, folder,
                             max_messages_per_run, require_email_auth, auth_serv_id,
                             last_scan_at, last_scan_status, last_scan_message, created_at';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM imap_accounts WHERE user_id = ? ORDER BY name, id');
        $stmt->execute([$userId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM imap_accounts WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Zapnuté schránky včetně `password_enc` (pro skener).
     *
     * @return list<array<string,mixed>>
     */
    public function withSecretsEnabled(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ', password_enc FROM imap_accounts WHERE user_id = ? AND enabled = 1 ORDER BY id');
        $stmt->execute([$userId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null schránka včetně `password_enc` */
    public function withSecret(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ', password_enc FROM imap_accounts WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** @param array<string,mixed> $data včetně `password_enc` */
    public function create(int $userId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO imap_accounts (user_id, name, enabled, host, port, encryption, validate_cert, username, password_enc,
                                        folder, max_messages_per_run, require_email_auth, auth_serv_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $data['name'], $data['enabled'] ? 1 : 0, $data['host'], $data['port'], $data['encryption'],
            $data['validate_cert'] ? 1 : 0, $data['username'], $data['password_enc'], $data['folder'],
            $data['max_messages_per_run'], $data['require_email_auth'] ? 1 : 0, $data['auth_serv_id'],
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data `password_enc` null = heslo beze změny */
    public function update(int $userId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE imap_accounts SET name = ?, enabled = ?, host = ?, port = ?, encryption = ?, validate_cert = ?, username = ?,
                    password_enc = COALESCE(?, password_enc), folder = ?, max_messages_per_run = ?, require_email_auth = ?, auth_serv_id = ?
              WHERE user_id = ? AND id = ?'
        )->execute([
            $data['name'], $data['enabled'] ? 1 : 0, $data['host'], $data['port'], $data['encryption'],
            $data['validate_cert'] ? 1 : 0, $data['username'], $data['password_enc'], $data['folder'],
            $data['max_messages_per_run'], $data['require_email_auth'] ? 1 : 0, $data['auth_serv_id'], $userId, $id,
        ]);
    }

    public function delete(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM imap_accounts WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }

    public function updateScanStatus(int $userId, int $id, string $status, ?string $message): void
    {
        $this->db->pdo()->prepare(
            'UPDATE imap_accounts SET last_scan_at = CURRENT_TIMESTAMP, last_scan_status = ?, last_scan_message = ? WHERE user_id = ? AND id = ?'
        )->execute([$status, $message !== null ? mb_substr($message, 0, 500) : null, $userId, $id]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        foreach (['id', 'port', 'max_messages_per_run'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        foreach (['enabled', 'validate_cert', 'require_email_auth'] as $k) {
            $row[$k] = (bool) $row[$k];
        }
        return $row;
    }
}
