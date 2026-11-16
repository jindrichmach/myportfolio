<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Infrastructure\Database\Connection;

/**
 * Přešifruje uložená tajemství aktuálním klíčem a formátem (SecretEncryption): převod `enc:v1:`
 * a nešifrovaných hodnot (běží v migrate.php) i rotace klíče (api/bin/rotate-secret-key.php).
 * Hodnoty, které už jsou v aktuálním formátu i klíči, nechá být.
 */
final class SecretRotator
{
    /** tabulka, sloupec, sloupec vlastníka pro AAD (null = globální nastavení) */
    private const COLUMNS = [
        ['users', 'totp_secret', 'id'],
        ['exchange_connections', 'credentials_enc', 'user_id'],
        ['imap_accounts', 'password_enc', 'user_id'],
        ['smtp_settings', 'password_enc', null],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $secrets,
    ) {}

    /** @return array<string,array{total:int,rotated:int,failed:list<int>}> po `tabulka.sloupec` */
    public function rotate(bool $dryRun = false): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        foreach (self::COLUMNS as [$table, $column, $owner]) {
            $stats = ['total' => 0, 'rotated' => 0, 'failed' => []];
            $rows = $pdo->query("SELECT id, " . ($owner ?? 'NULL') . " AS owner, `{$column}` AS value FROM `{$table}` WHERE `{$column}` IS NOT NULL")
                ->fetchAll(\PDO::FETCH_ASSOC);
            $update = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE id = ? AND `{$column}` = ?");
            foreach ($rows as $row) {
                $stats['total']++;
                $context = SecretEncryption::context("{$table}.{$column}", $row['owner'] !== null ? (int) $row['owner'] : null);
                try {
                    [$plain, $stale] = $this->secrets->decryptForRotation((string) $row['value'], $context);
                    if (!$stale) {
                        continue;
                    }
                    if (!$dryRun) {
                        $update->execute([$this->secrets->encrypt($plain, $context), $row['id'], $row['value']]);
                    }
                    $stats['rotated']++;
                } catch (\RuntimeException) {
                    $stats['failed'][] = (int) $row['id'];
                }
            }
            $out["{$table}.{$column}"] = $stats;
        }
        return $out;
    }
}
