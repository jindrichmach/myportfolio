<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\SecurityTime;
use MyPortfolio\Service\Auth\StoredPasskeyCredential;
use PDO;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyCredentialRepository
{
    public function __construct(private readonly Connection $db) {}

    public function userHandle(int $userId): string
    {
        $pdo = $this->db->pdo();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $pdo->beginTransaction();
            try {
                $handle = $this->lockedUserHandle($userId);
                if ($handle === null) {
                    $handle = random_bytes(32);
                    $update = $pdo->prepare(
                        'UPDATE users SET webauthn_user_handle = ? WHERE id = ? AND webauthn_user_handle IS NULL'
                    );
                    $update->execute([$handle, $userId]);
                    if ($update->rowCount() !== 1) {
                        throw new \RuntimeException('WebAuthn user handle se nepodařilo uložit.');
                    }
                }

                $pdo->commit();
                return $handle;
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getCode() !== '23000' || $attempt === 2) {
                    throw $e;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        throw new \RuntimeException('WebAuthn user handle se nepodařilo vytvořit.');
    }

    public function save(
        int $userId,
        CredentialRecord $record,
        string $label,
        bool $requireNoActivePasskey = false,
    ): int {
        $label = self::normalizeLabel($label);
        if ($record->type !== PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY) {
            throw new \InvalidArgumentException('Nepodporovaný typ WebAuthn credential.');
        }
        if (strlen($record->publicKeyCredentialId) < 1 || strlen($record->publicKeyCredentialId) > 1024) {
            throw new \InvalidArgumentException('Credential ID musí mít 1 až 1024 bajtů.');
        }
        if ($record->credentialPublicKey === '' || strlen($record->aaguid->toBinary()) !== 16) {
            throw new \InvalidArgumentException('Credential obsahuje neplatná kryptografická metadata.');
        }

        $normalizedTransports = self::normalizeTransports($record->transports);
        $transports = json_encode($normalizedTransports, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $expectedHandle = $this->lockedUserHandle($userId);
            if ($expectedHandle === null || !hash_equals($expectedHandle, $record->userHandle)) {
                throw new \InvalidArgumentException('Credential nepatří uvedenému uživateli.');
            }

            $count = $pdo->prepare(
                'SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ? AND revoked_at IS NULL'
            );
            $count->execute([$userId]);
            $activeCount = (int) $count->fetchColumn();
            if ($requireNoActivePasskey && $activeCount !== 0) {
                throw new \DomainException(
                    'Registrační flow je platné pouze pro první passkey uživatele.',
                );
            }
            if ($activeCount >= 10) {
                throw new \DomainException('Uživatel může mít nejvýše 10 aktivních passkeys.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO webauthn_credentials
                    (user_id, credential_id, credential_id_hash, public_key, sign_count,
                     transports_json, aaguid, label, backup_eligible, backup_state, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))'
            );
            $stmt->execute([
                $userId,
                $record->publicKeyCredentialId,
                hash('sha256', $record->publicKeyCredentialId, true),
                $record->credentialPublicKey,
                $record->counter,
                $transports,
                $record->aaguid->toBinary(),
                $label,
                $record->backupEligible === true ? 1 : 0,
                $record->backupStatus === true ? 1 : 0,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function findActiveByCredentialId(string $credentialId): ?StoredPasskeyCredential
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, u.webauthn_user_handle
               FROM webauthn_credentials c
               JOIN users u ON u.id = c.user_id
              WHERE c.credential_id_hash = ?
                AND c.revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([hash('sha256', $credentialId, true)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !hash_equals((string) $row['credential_id'], $credentialId)) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findActiveByCredentialIdForUpdate(
        PDO $pdo,
        string $credentialId,
    ): ?StoredPasskeyCredential {
        $stmt = $pdo->prepare(
            'SELECT c.*, u.webauthn_user_handle
               FROM webauthn_credentials c
               JOIN users u ON u.id = c.user_id
              WHERE c.credential_id_hash = ?
                AND c.revoked_at IS NULL
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([hash('sha256', $credentialId, true)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !hash_equals((string) $row['credential_id'], $credentialId)) {
            return null;
        }
        return self::hydrate($row);
    }

    public function findActiveForUserById(int $userId, int $credentialId): ?StoredPasskeyCredential
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, u.webauthn_user_handle
               FROM webauthn_credentials c
               JOIN users u ON u.id = c.user_id
              WHERE c.id = ?
                AND c.user_id = ?
                AND c.revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$credentialId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::hydrate($row) : null;
    }

    public function findActiveForUserByIdForUpdate(
        PDO $pdo,
        int $userId,
        int $credentialId,
    ): ?StoredPasskeyCredential {
        $stmt = $pdo->prepare(
            'SELECT c.*, u.webauthn_user_handle
               FROM webauthn_credentials c
               JOIN users u ON u.id = c.user_id
              WHERE c.id = ?
                AND c.user_id = ?
                AND c.revoked_at IS NULL
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$credentialId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::hydrate($row) : null;
    }

    /**
     * @return list<StoredPasskeyCredential>
     */
    public function lockAllActiveForUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT c.*, u.webauthn_user_handle
               FROM webauthn_credentials c
               JOIN users u ON u.id = c.user_id
              WHERE c.user_id = ? AND c.revoked_at IS NULL
              ORDER BY c.id
              FOR UPDATE'
        );
        $stmt->execute([$userId]);
        return array_values(array_map(
            self::hydrate(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ));
    }

    /**
     * @return list<StoredPasskeyCredential>
     */
    public function findAllForUser(int $userId, bool $includeRevoked = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, u.webauthn_user_handle
               FROM webauthn_credentials c
               JOIN users u ON u.id = c.user_id
              WHERE c.user_id = ?'
            . ($includeRevoked ? '' : ' AND c.revoked_at IS NULL')
            . ' ORDER BY c.created_at, c.id'
        );
        $stmt->execute([$userId]);

        return array_values(array_map(
            self::hydrate(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ));
    }

    public function rename(int $userId, int $credentialId, string $label): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE webauthn_credentials
                SET label = ?
              WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([self::normalizeLabel($label), $credentialId, $userId]);
        return $stmt->rowCount() === 1;
    }

    public function revoke(int $userId, int $credentialId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE webauthn_credentials
                SET revoked_at = UTC_TIMESTAMP(6)
              WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$credentialId, $userId]);
        return $stmt->rowCount() === 1;
    }

    public function countActiveForUser(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function updateAfterAssertion(
        StoredPasskeyCredential $stored,
        CredentialRecord $record,
    ): bool {
        if (!hash_equals($stored->record->publicKeyCredentialId, $record->publicKeyCredentialId)
            || !hash_equals($stored->record->userHandle, $record->userHandle)
        ) {
            throw new \InvalidArgumentException('Výsledek assertion nepatří uložené credential.');
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE webauthn_credentials
                SET sign_count = ?,
                    backup_eligible = ?,
                    backup_state = ?,
                    last_used_at = UTC_TIMESTAMP(6)
              WHERE id = ?
                AND revoked_at IS NULL
                AND sign_count = ?'
        );
        $stmt->execute([
            $record->counter,
            $record->backupEligible === true ? 1 : 0,
            $record->backupStatus === true ? 1 : 0,
            $stored->id,
            $stored->record->counter,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function updateAfterAssertionInTransaction(
        PDO $pdo,
        StoredPasskeyCredential $stored,
        CredentialRecord $record,
        SecurityTime $cutoff,
    ): bool {
        if (!hash_equals($stored->record->publicKeyCredentialId, $record->publicKeyCredentialId)
            || !hash_equals($stored->record->userHandle, $record->userHandle)
        ) {
            throw new \InvalidArgumentException('Výsledek assertion nepatří uložené credential.');
        }
        $stmt = $pdo->prepare(
            'UPDATE webauthn_credentials
                SET sign_count = ?,
                    backup_eligible = ?,
                    backup_state = ?,
                    last_used_at = ?
              WHERE id = ?
                AND revoked_at IS NULL
                AND sign_count = ?'
        );
        $stmt->execute([
            $record->counter,
            $record->backupEligible === true ? 1 : 0,
            $record->backupStatus === true ? 1 : 0,
            $cutoff->utcSql,
            $stored->id,
            $stored->record->counter,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function revokeInTransaction(
        PDO $pdo,
        int $userId,
        int $credentialId,
        SecurityTime $cutoff,
    ): bool {
        $stmt = $pdo->prepare(
            'UPDATE webauthn_credentials
                SET revoked_at = ?
              WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$cutoff->utcSql, $credentialId, $userId]);
        return $stmt->rowCount() === 1;
    }

    private function lockedUserHandle(int $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT webauthn_user_handle FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $handle = $stmt->fetchColumn();
        if ($handle === false) {
            throw new \InvalidArgumentException('Uživatel neexistuje.');
        }
        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    private static function normalizeLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 100) {
            throw new \InvalidArgumentException('Název passkey musí mít 1 až 100 znaků.');
        }
        return $label;
    }

    /**
     * @param array<mixed> $transports
     * @return list<string>
     */
    private static function normalizeTransports(array $transports): array
    {
        $normalized = [];
        foreach ($transports as $transport) {
            if (!is_string($transport)
                || !in_array($transport, PublicKeyCredentialDescriptor::AUTHENTICATOR_TRANSPORTS, true)
            ) {
                throw new \InvalidArgumentException('Credential obsahuje neplatný transport.');
            }
            if (!in_array($transport, $normalized, true)) {
                $normalized[] = $transport;
            }
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function hydrate(array $row): StoredPasskeyCredential
    {
        $transports = json_decode((string) ($row['transports_json'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($transports)) {
            throw new \UnexpectedValueException('Credential obsahuje neplatný seznam transports.');
        }
        try {
            $transports = self::normalizeTransports($transports);
        } catch (\InvalidArgumentException) {
            throw new \UnexpectedValueException('Credential obsahuje neplatný seznam transports.');
        }

        $aaguid = (string) $row['aaguid'];
        $userHandle = (string) $row['webauthn_user_handle'];
        if (strlen($aaguid) !== 16 || $userHandle === '') {
            throw new \UnexpectedValueException('Credential obsahuje neplatná WebAuthn metadata.');
        }

        $record = CredentialRecord::create(
            (string) $row['credential_id'],
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $transports,
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary($aaguid),
            (string) $row['public_key'],
            $userHandle,
            (int) $row['sign_count'],
            null,
            (int) $row['backup_eligible'] === 1,
            (int) $row['backup_state'] === 1,
            true,
        );

        return new StoredPasskeyCredential(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['label'],
            $record,
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
        );
    }
}
