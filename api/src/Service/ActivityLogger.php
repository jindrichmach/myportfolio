<?php

declare(strict_types=1);

namespace MyPortfolio\Service;

use MyPortfolio\Infrastructure\Database\Connection;

/**
 * Loguje akce do tabulky activity_log. Citlivá pole se redaktují
 * (LoggerSanitizer). Pro auth/security události samostatný kanál.
 */
final class ActivityLogger
{
    private const REDACT_KEYS = [
        'password', 'password_confirm', 'current_password', 'new_password',
        'token', 'csrf_token', 'cf_turnstile_response', 'secret_key',
        'private_key', 'pass',
        'flow_token', 'step_up_token', 'challenge', 'credential', 'rawid',
        'raw_id', 'signature', 'authenticator_data', 'client_data_json',
        'attestation_object', 'public_key',
    ];

    public function __construct(private readonly Connection $db) {}

    /** @param array<array-key,mixed>|null $payload */
    public function log(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $payload = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $sql = 'INSERT INTO activity_log
                (user_id, action, entity_type, entity_id, payload, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $payload === null ? null : json_encode($this->redact($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $ip !== null ? (@inet_pton($ip) ?: null) : null,
            $userAgent !== null ? substr($userAgent, 0, 255) : null,
        ]);
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    public function redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT_KEYS, true)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->redact($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
