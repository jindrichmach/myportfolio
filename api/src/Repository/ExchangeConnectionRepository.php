<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * Napojení na API burz. Scopované na user_id; zašifrované
 * přihlašovací údaje vrací jen `withSecret`, nikdy seznam pro API.
 */
final class ExchangeConnectionRepository
{
    private const COLUMNS = 'c.id, c.provider, c.account_id, c.portfolio_id, c.key_hint, c.enabled, c.sync_from, c.synced_until,
        c.pending_token, c.pending_until, c.last_sync_at, c.last_status, c.last_message, c.created_at,
        a.name AS account_name, i.name AS institution_name, p.name AS portfolio_name';
    private const JOINS = 'FROM exchange_connections c
        JOIN accounts a ON a.id = c.account_id JOIN institutions i ON i.id = a.institution_id JOIN portfolios p ON p.id = c.portfolio_id';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE c.user_id = ? ORDER BY c.provider, c.id');
        $stmt->execute([$userId]);
        return array_map(self::hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE c.user_id = ? AND c.id = ?');
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return array<string,mixed>|null napojení včetně `credentials_enc` (pro synchronizaci) */
    public function withSecret(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ', c.credentials_enc ' . self::JOINS . ' WHERE c.user_id = ? AND c.id = ?');
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<int> zapnutá napojení uživatele (cron) */
    public function enabledIds(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM exchange_connections WHERE user_id = ? AND enabled = 1 ORDER BY id');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array{provider:string,account_id:int,portfolio_id:int,credentials_enc:string,key_hint:?string,enabled:bool,sync_from:?string} $d */
    public function create(int $userId, array $d): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_connections (user_id, provider, account_id, portfolio_id, credentials_enc, key_hint, enabled, sync_from)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $d['provider'], $d['account_id'], $d['portfolio_id'], $d['credentials_enc'], $d['key_hint'], $d['enabled'] ? 1 : 0, $d['sync_from']]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Úprava; `credentials_enc` null = beze změny. @param array{account_id:int,portfolio_id:int,credentials_enc:?string,key_hint:?string,enabled:bool} $d */
    public function update(int $userId, int $id, array $d): void
    {
        $this->db->pdo()->prepare(
            'UPDATE exchange_connections SET account_id = ?, portfolio_id = ?, enabled = ?,
                    credentials_enc = COALESCE(?, credentials_enc), key_hint = COALESCE(?, key_hint)
              WHERE user_id = ? AND id = ?'
        )->execute([$d['account_id'], $d['portfolio_id'], $d['enabled'] ? 1 : 0, $d['credentials_enc'], $d['key_hint'], $userId, $id]);
    }

    public function delete(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM exchange_connections WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
    }

    /** Výsledek synchronizace; `$syncedUntil` null = kurzor beze změny; `$pendingUntil` = konec čekajícího období. */
    public function recordSync(int $userId, int $id, string $status, ?string $message, ?string $syncedUntil, ?string $pendingToken, ?string $pendingUntil = null): void
    {
        $this->db->pdo()->prepare(
            'UPDATE exchange_connections SET last_sync_at = NOW(), last_status = ?, last_message = ?,
                    synced_until = COALESCE(?, synced_until), pending_token = ?, pending_until = ?
              WHERE user_id = ? AND id = ?'
        )->execute([$status, $message !== null ? mb_substr($message, 0, 500) : null, $syncedUntil, $pendingToken, $pendingUntil, $userId, $id]);
    }

    /** Napojení, jehož náhled čeká na potvrzení. @return array<string,mixed>|null */
    public function findByPendingToken(int $userId, string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE c.user_id = ? AND c.pending_token = ?');
        $stmt->execute([$userId, $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Z daných external_id ta, která uživatel u napojení dřív vynechal.
     *
     * @param list<string> $externalIds
     * @return list<string>
     */
    public function skipped(int $userId, int $connectionId, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($externalIds)), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare('SELECT external_id FROM exchange_skipped WHERE user_id = ? AND connection_id = ? AND external_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $stmt->execute([$userId, $connectionId, ...$chunk]);
            array_push($out, ...$stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        return array_map('strval', $out);
    }

    /** @param list<string> $externalIds pohyby vynechané v náhledu */
    public function addSkipped(int $userId, int $connectionId, array $externalIds): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT IGNORE INTO exchange_skipped (user_id, connection_id, external_id) VALUES (?, ?, ?)');
        foreach (array_unique($externalIds) as $id) {
            $stmt->execute([$userId, $connectionId, $id]);
        }
    }

    /** Po potvrzení čekajícího náhledu: kurzor na konec jeho období, stav ok. */
    public function confirmPending(int $userId, string $token): void
    {
        $this->db->pdo()->prepare(
            "UPDATE exchange_connections SET synced_until = COALESCE(pending_until, synced_until), pending_token = NULL, pending_until = NULL,
                    last_status = 'ok', last_message = 'Náhled potvrzen.'
              WHERE user_id = ? AND pending_token = ?"
        )->execute([$userId, $token]);
    }

    /** @param array<string,mixed> $r */
    private static function hydrate(array $r): array
    {
        foreach (['id', 'account_id', 'portfolio_id'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['enabled'] = (bool) $r['enabled'];
        return $r;
    }
}
