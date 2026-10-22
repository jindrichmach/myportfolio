<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\Cron\CronCatalog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/cron-jobs
 *
 * Vrací katalog doporučených plánovaných úloh + stav posledního běhu
 * z `cron_runs` (poslední běh, poslední úspěšný běh, ok? / overdue? / failing?).
 * Admin only.
 */
final class CronJobsAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $pdo = $this->db->pdo();
        $catalog = CronCatalog::all();

        // Načti pro každý katalogový skript poslední běh + poslední úspěšný běh.
        // Jeden subquery na skript je o.k. (max 8 položek, indexované).
        $sql = "
            SELECT cr.script,
                   cr.id           AS last_id,
                   cr.started_at   AS last_started_at,
                   cr.finished_at  AS last_finished_at,
                   cr.status       AS last_status,
                   cr.duration_ms  AS last_duration_ms,
                   cr.exit_code    AS last_exit_code,
                   cr.host         AS last_host,
                   cr.message      AS last_message,
                   cr.report       AS last_report,
                   ok.started_at   AS last_ok_started_at,
                   ok.finished_at  AS last_ok_finished_at
              FROM (SELECT script, MAX(id) AS max_id FROM cron_runs WHERE script = ? GROUP BY script) latest
              JOIN cron_runs cr ON cr.id = latest.max_id
         LEFT JOIN (SELECT script, MAX(id) AS ok_id FROM cron_runs WHERE script = ? AND status = 'ok' GROUP BY script) lok
                ON lok.script = cr.script
         LEFT JOIN cron_runs ok ON ok.id = lok.ok_id
        ";
        $stmt = $pdo->prepare($sql);

        // Také spočítej za posledních 24h pro každý skript
        $countStmt = $pdo->prepare(
            "SELECT
                SUM(status = 'ok')    AS ok_24h,
                SUM(status = 'error') AS err_24h,
                COUNT(*)              AS total_24h
              FROM cron_runs
             WHERE script = ?
               AND started_at >= NOW() - INTERVAL 24 HOUR"
        );

        $now = time();
        $rows = [];
        foreach ($catalog as $job) {
            // Podmíněné úlohy (bank scan, scan inbox) skryj, dokud není nastaven
            // jejich adresář v cfg — bez něj scan jen tiše skipuje, takže nemá smysl
            // je v přehledu hlásit jako "nikdy neběžela".
            if (!$this->jobConfigured($job)) {
                continue;
            }
            $script = (string) $job['script'];
            $stmt->execute([$script, $script]);
            $last = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            $countStmt->execute([$script]);
            $counts = $countStmt->fetch(\PDO::FETCH_ASSOC) ?: ['ok_24h' => 0, 'err_24h' => 0, 'total_24h' => 0];

            $lastOkAt = $last !== false && $last !== null ? ($last['last_ok_started_at'] ?? null) : null;
            $maxAgeSec = (int) $job['max_age_hours'] * 3600;
            $health = 'never_ran';
            $ageSec = null;
            if ($lastOkAt !== null) {
                $ageSec = $now - (int) strtotime((string) $lastOkAt);
                $health = ($ageSec > $maxAgeSec) ? 'overdue' : 'ok';
            }
            if (($last['last_status'] ?? null) === 'error') {
                $health = ($health === 'ok') ? 'failing' : ($health === 'never_ran' ? 'failing' : 'overdue_and_failing');
            }

            $report = null;
            if ($last !== null && !empty($last['last_report'])) {
                $report = json_decode((string) $last['last_report'], true);
            }

            $rows[] = [
                'script'              => $script,
                'recommended'         => $job['recommended'],
                'linux_cron'          => $job['linux_cron'],
                'weekdays_only'       => (bool) $job['weekdays_only'],
                'critical'            => (bool) $job['critical'],
                'max_age_hours'       => (int) $job['max_age_hours'],
                'health'              => $health,                          // ok | overdue | failing | overdue_and_failing | never_ran
                'last_started_at'     => $last['last_started_at']    ?? null,
                'last_finished_at'    => $last['last_finished_at']   ?? null,
                'last_status'         => $last['last_status']        ?? null,
                'last_duration_ms'    => $last !== null && $last['last_duration_ms'] !== null ? (int) $last['last_duration_ms'] : null,
                'last_exit_code'      => $last !== null && $last['last_exit_code']   !== null ? (int) $last['last_exit_code']   : null,
                'last_host'           => $last['last_host']          ?? null,
                'last_message'        => $last['last_message']       ?? null,
                'last_report'         => $report,
                'last_ok_started_at'  => $lastOkAt,
                'last_ok_finished_at' => $last['last_ok_finished_at'] ?? null,
                'age_sec_since_ok'    => $ageSec,
                'counts_24h'          => [
                    'ok'    => (int) ($counts['ok_24h']  ?? 0),
                    'error' => (int) ($counts['err_24h'] ?? 0),
                    'total' => (int) ($counts['total_24h'] ?? 0),
                ],
            ];
        }

        // Server time pro UI (klient může mít jinou TZ než server).
        return Json::ok($response, [
            'jobs'        => $rows,
            'server_time' => date('c'),
        ]);
    }

    /**
     * Úloha s `requires_config` je "konfigurovaná" jen když je cfg klíč nastaven
     * na existující adresář (stejná podmínka jako cron skript, který scan jinak
     * tiše přeskočí). Úlohy bez `requires_config` jsou vždy konfigurované.
     *
     * @param array<string,mixed> $job
     */
    private function jobConfigured(array $job): bool
    {
        $key = $job['requires_config'] ?? null;
        if ($key === null) {
            return true;
        }
        $path = trim((string) $this->config->get((string) $key, ''));
        return $path !== '' && is_dir($path);
    }
}
