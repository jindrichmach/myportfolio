<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use MyPortfolio\Bootstrap;
use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Cron\CronCatalog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/cron-jobs/{script}/run
 *
 * Spustí daný cron skript z katalogu na pozadí (fire-and-forget).
 * Skript si sám zapíše start/finish do `cron_runs` přes CronRun, takže
 * UI se aktualizuje automaticky při refresh tabulky.
 *
 * Stdout/stderr spawnutého procesu se přesměruje do `log/cron-run-<script>.log`
 * pro diagnostiku (kdyby fail nastal před tím, než CronRun stihne otevřít DB).
 * Admin only.
 */
final class RunCronJobAction
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $script = (string) ($args['script'] ?? '');
        if (!in_array($script, CronCatalog::scripts(), true)) {
            return Json::error($response, 'not_found', 'Neznámý cron skript.', 404);
        }

        $rootDir = Bootstrap::rootDir();
        $scriptPath = $rootDir . '/api/bin/' . $script . '.php';
        if (!is_file($scriptPath)) {
            return Json::error($response, 'not_found', 'Soubor skriptu neexistuje: ' . $scriptPath, 404);
        }

        $phpBin = $this->resolveCliPhpBinary();
        if ($phpBin === null) {
            return Json::error(
                $response,
                'no_php_cli',
                'PHP CLI binárka nenalezena (PHP_BINARY=' . PHP_BINARY . '). Nastav prosím cestu k php.exe.',
                500
            );
        }

        $logPath = $this->logPath($script);
        $this->appendLog($logPath, sprintf(
            "[%s] spawn: php=%s script=%s sapi=%s\n",
            date('c'),
            $phpBin,
            $scriptPath,
            PHP_SAPI
        ));

        $spawned = $this->spawnBackground($phpBin, $scriptPath, $logPath, $rootDir, $diag);

        $this->logger->log(
            'admin.cron.run_now',
            (int) ($user['id'] ?? 0),
            null,
            null,
            [
                'script'   => $script,
                'php_bin'  => $phpBin,
                'spawned'  => $spawned,
                'log_file' => $logPath,
                'diag'     => $diag,
            ]
        );

        if (!$spawned) {
            return Json::error($response, 'spawn_failed', 'Nepodařilo se spustit skript na pozadí: ' . $diag, 500);
        }

        return Json::ok($response, [
            'script'   => $script,
            'started'  => true,
            'php_bin'  => $phpBin,
            'log_file' => $logPath,
        ], 202);
    }

    /**
     * Pod IIS FastCGI je PHP_BINARY = php-cgi.exe. CLI skripty v takovém prostředí
     * tiše končí. Sdílená logika ve PhpCliLocator (používá i import worker spawn).
     */
    private function resolveCliPhpBinary(): ?string
    {
        return \MyPortfolio\Service\PhpCliLocator::resolve();
    }

    private function logPath(string $script): string
    {
        $dataDir = $this->config->dataDir() ?? Bootstrap::rootDir();
        $logDir = rtrim($dataDir, "\\/") . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0o775, true);
        }
        return $logDir . DIRECTORY_SEPARATOR . 'cron-run-' . $script . '.log';
    }

    private function appendLog(string $path, string $text): void
    {
        @file_put_contents($path, $text, FILE_APPEND | LOCK_EX);
    }

    /**
     * Spustí PHP CLI skript na pozadí, fire-and-forget.
     * `$diag` dostane krátký popis (pro activity_log).
     */
    private function spawnBackground(string $phpBin, string $scriptPath, string $logPath, string $cwd, ?string &$diag): bool
    {
        // Sdílený launcher (stejný i pro import workery). $phpBin už je resolved
        // přes PhpCliLocator; helper si ho dohledá stejně, takže zůstává konzistentní.
        return \MyPortfolio\Service\BackgroundProcess::spawnPhp($scriptPath, [], $logPath, $cwd, $diag);
    }
}
