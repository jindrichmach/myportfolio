<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Cron;

use PDO;
use Throwable;

/**
 * Heartbeat pro api/bin/cron-*.php — zapíše do tabulky `cron_runs` start
 * běhu, po dokončení update s exit code + JSON reportem.
 *
 * Použití na začátku cron skriptu:
 *
 *   $run = CronRun::start($pdo, 'cron-send-reminders');
 *   // ... práce ...
 *   $run->finish('ok', ['sent' => 5, 'errors' => 0]);
 *
 * Pokud skript skončí výjimkou nebo `exit(1)` před voláním finish(),
 * shutdown handler dopíše status='error'. Tím se rozpozná i případ
 * "cron běží, ale failuje" (oproti "cron vůbec není nastavený").
 */
final class CronRun
{
    private int $id;
    private float $startedAt;
    private bool $finished = false;

    private function __construct(private readonly PDO $pdo, int $id, float $startedAt)
    {
        $this->id = $id;
        $this->startedAt = $startedAt;
    }

    public static function start(PDO $pdo, string $script): self
    {
        $startedAt = microtime(true);
        $host = (string) (gethostname() ?: '');
        $stmt = $pdo->prepare(
            "INSERT INTO cron_runs (script, started_at, status, host) VALUES (?, NOW(), 'running', ?)"
        );
        $stmt->execute([$script, $host !== '' ? substr($host, 0, 100) : null]);
        $id = (int) $pdo->lastInsertId();

        $run = new self($pdo, $id, $startedAt);

        // Pokud skript skončí výjimkou / exit() / fatal error bez explicitního finish(),
        // tenhle handler dopíše error stav, aby v UI nezůstal "running" navždy.
        register_shutdown_function(function () use ($run) {
            if ($run->isFinished()) return;
            $err = error_get_last();
            $msg = null;
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                $msg = sprintf('%s in %s:%d', (string) $err['message'], (string) $err['file'], (int) $err['line']);
            }
            $run->finish('error', null, $msg, 1);
        });

        return $run;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * @param 'ok'|'error' $status
     * @param array<string,mixed>|null $report
     */
    public function finish(string $status, ?array $report = null, ?string $message = null, ?int $exitCode = null): void
    {
        if ($this->finished) return;
        $this->finished = true;

        $durationMs = (int) ((microtime(true) - $this->startedAt) * 1000);
        if ($exitCode === null) {
            $exitCode = $status === 'ok' ? 0 : 1;
        }

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE cron_runs
                    SET finished_at = NOW(),
                        status      = ?,
                        duration_ms = ?,
                        exit_code   = ?,
                        message     = ?,
                        report      = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $status,
                $durationMs,
                $exitCode,
                $message !== null ? mb_substr($message, 0, 2000) : null,
                $report !== null ? json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $this->id,
            ]);
        } catch (Throwable $e) {
            // Nesmí přetížit error v cronu — diagnostika je best-effort.
            // STDERR nemusí existovat mimo CLI SAPI (spawn z UI pod php-cgi/FastCGI).
            $stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
            if ($stderr !== false) {
                fwrite($stderr, "CronRun::finish failed: " . $e->getMessage() . "\n");
            }
        }
    }

    /**
     * Smaže staré záznamy: drží max `$keep` posledních běhů na skript.
     * Volá se z cron-cleanup.
     */
    public static function purgeOld(PDO $pdo, int $keep = 500): int
    {
        // MariaDB neumí LIMIT v DELETE … IN, takže přes virtuální subquery.
        $stmt = $pdo->prepare(
            "DELETE r FROM cron_runs r
              JOIN (
                SELECT id FROM (
                  SELECT id, script,
                         ROW_NUMBER() OVER (PARTITION BY script ORDER BY id DESC) AS rn
                    FROM cron_runs
                ) ranked
                WHERE rn > ?
              ) old ON old.id = r.id"
        );
        $stmt->execute([$keep]);
        return $stmt->rowCount();
    }
}
