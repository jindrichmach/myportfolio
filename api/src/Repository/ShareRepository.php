<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * Sdílení dat vlastníka s partnerem. Vlastník spravuje svá sdílení
 * (`owner_user_id`), partner vidí jen přijatá (`grantee_user_id`).
 */
final class ShareRepository
{
    private const SELECT = 'SELECT s.id, s.owner_user_id, s.grantee_user_id, s.invite_email, s.invite_expires_at, s.permission, s.scope,
            s.sections, s.accepted_at, s.created_at, o.name AS owner_name, o.email AS owner_email, g.name AS grantee_name
          FROM shares s
          JOIN users o ON o.id = s.owner_user_id
          LEFT JOIN users g ON g.id = s.grantee_user_id';

    public function __construct(private readonly Connection $db) {}

    /** Sdílení, která vlastník udělil. @return list<array<string,mixed>> */
    public function granted(int $userId): array
    {
        return $this->select(' WHERE s.owner_user_id = ? ORDER BY s.created_at', [$userId]);
    }

    /** Přijatá sdílení (partner = přihlášený), jen aktivní. @return list<array<string,mixed>> */
    public function received(int $userId): array
    {
        return $this->select(' WHERE s.grantee_user_id = ? AND s.accepted_at IS NOT NULL AND o.is_active = 1 ORDER BY o.name', [$userId]);
    }

    /** Aktivní sdílení vlastník → partner (kontext vlastníka). @return array<string,mixed>|null */
    public function active(int $ownerId, int $granteeId): ?array
    {
        return $this->select(' WHERE s.owner_user_id = ? AND s.grantee_user_id = ? AND s.accepted_at IS NOT NULL AND o.is_active = 1', [$ownerId, $granteeId])[0] ?? null;
    }

    /** Sdílení, které vlastník udělil, nebo které partner přijal (oba ho smí smazat). @return array<string,mixed>|null */
    public function findForUser(int $userId, int $id): ?array
    {
        return $this->select(' WHERE s.id = ? AND (s.owner_user_id = ? OR s.grantee_user_id = ?)', [$id, $userId, $userId])[0] ?? null;
    }

    /** Pozvánka podle tokenu (veřejný odkaz). @return array<string,mixed>|null */
    public function byToken(string $tokenHash): ?array
    {
        return $this->select(' WHERE s.invite_token_hash = ? AND s.grantee_user_id IS NULL', [$tokenHash])[0] ?? null;
    }

    /** @param array{owner:int,grantee:?int,email:string,token_hash:?string,expires_at:?string,permission:string,scope:string,sections:list<string>,portfolios:list<int>} $d */
    public function create(array $d): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO shares (owner_user_id, grantee_user_id, invite_email, invite_token_hash, invite_expires_at, permission, scope, sections, accepted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$d['owner'], $d['grantee'], $d['email'], $d['token_hash'], $d['expires_at'], $d['permission'], $d['scope'],
                implode(',', $d['sections']), $d['grantee'] !== null ? date('Y-m-d H:i:s') : null]);
            $id = (int) $pdo->lastInsertId();
            $this->savePortfolios($id, $d['portfolios']);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array{permission:string,scope:string,sections:list<string>,portfolios:list<int>} $d */
    public function update(int $ownerId, int $id, array $d): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE shares SET permission = ?, scope = ?, sections = ? WHERE owner_user_id = ? AND id = ?');
            $stmt->execute([$d['permission'], $d['scope'], implode(',', $d['sections']), $ownerId, $id]);
            $pdo->prepare('DELETE FROM share_portfolios WHERE share_id = ?')->execute([$id]);
            $this->savePortfolios($id, $d['portfolios']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $userId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM shares WHERE id = ? AND (owner_user_id = ? OR grantee_user_id = ?)')->execute([$id, $userId, $userId]);
    }

    /** Přijetí pozvánky novým uživatelem. */
    public function accept(int $id, int $granteeId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE shares SET grantee_user_id = ?, accepted_at = NOW(), invite_token_hash = NULL, invite_expires_at = NULL WHERE id = ? AND grantee_user_id IS NULL'
        )->execute([$granteeId, $id]);
    }

    /** Nová pozvánka (znovu poslat). */
    public function renewInvite(int $ownerId, int $id, string $tokenHash, string $expiresAt): void
    {
        $this->db->pdo()->prepare('UPDATE shares SET invite_token_hash = ?, invite_expires_at = ? WHERE owner_user_id = ? AND id = ? AND grantee_user_id IS NULL')
            ->execute([$tokenHash, $expiresAt, $ownerId, $id]);
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    private function select(string $where, array $params): array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . $where);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);
        $map = [];
        foreach ($this->db->pdo()->query('SELECT sp.share_id, sp.portfolio_id, p.name FROM share_portfolios sp JOIN portfolios p ON p.id = sp.portfolio_id
                                           WHERE sp.share_id IN (' . implode(',', $ids) . ') ORDER BY p.name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int) $r['share_id']][] = ['id' => (int) $r['portfolio_id'], 'name' => (string) $r['name']];
        }
        return array_map(static fn (array $r) => [
            'id'              => (int) $r['id'],
            'owner_user_id'   => (int) $r['owner_user_id'],
            'grantee_user_id' => $r['grantee_user_id'] !== null ? (int) $r['grantee_user_id'] : null,
            'sections'        => $r['sections'] !== '' ? explode(',', (string) $r['sections']) : [],
            'portfolios'      => $map[(int) $r['id']] ?? [],
        ] + $r, $rows);
    }

    /** @param list<int> $portfolioIds */
    private function savePortfolios(int $shareId, array $portfolioIds): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO share_portfolios (share_id, portfolio_id) VALUES (?, ?)');
        foreach (array_unique($portfolioIds) as $pid) {
            $stmt->execute([$shareId, $pid]);
        }
    }
}
