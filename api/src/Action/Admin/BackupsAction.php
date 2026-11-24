<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use MyPortfolio\Bootstrap;
use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\Auth\MfaStepUpService;
use MyPortfolio\Service\Auth\StepUpGate;
use MyPortfolio\Service\Backup\BackupCatalog;
use MyPortfolio\Service\Backup\BackupDirectory;
use MyPortfolio\Service\Backup\RestorePreflight;
use MyPortfolio\Service\Backup\RestoreState;
use MyPortfolio\Service\Cron\BackupEncryption;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * GET /api/admin/backups — seznam záloh z cron-backup + `restore.reasons` (preflight obnovy z webu)
 * a `restore.last_run` (stav posledního běhu + konec logu).
 * POST /api/admin/backups/{name}/download — stažení ZIPu zálohy (MFA step-up). Soubor jen podle názvu ze seznamu.
 */
final class BackupsAction
{
    public function __construct(
        private readonly Config $config,
        private readonly RestorePreflight $preflight,
        private readonly RestoreState $state,
        private readonly StepUpGate $stepUp,
        private readonly ?string $logFile = null,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $hasPassword = BackupEncryption::passwordFromConfig($this->config) !== '';
        $backups = array_map(
            static fn (array $b): array => $b + ['restorable' => !$b['encrypted'] || $hasPassword],
            $this->catalog()->list(),
        );

        return Json::ok($response, [
            'backups' => $backups,
            'restore' => ['reasons' => $this->preflight->check(), 'last_run' => $this->lastRun()],
        ]);
    }

    /** @param array{name?: string} $args */
    public function download(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $path = $this->catalog()->find((string) ($args['name'] ?? ''));
        if ($path === null) {
            return Json::error($response, 'not_found', 'Záloha nenalezena.', 404);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $denied = $this->stepUp->check(
            $request,
            $response,
            (int) ($user['id'] ?? 0),
            (array) ($request->getParsedBody() ?? []),
            MfaStepUpService::OPERATION_BACKUP_DOWNLOAD,
        );
        if ($denied !== null) {
            return $denied;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return Json::error($response, 'server_error', 'Zálohu se nepodařilo otevřít.', 500);
        }

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', basename($path)))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function isAdmin(Request $request): bool
    {
        return (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['role'] ?? '') === 'admin';
    }

    private function catalog(): BackupCatalog
    {
        return new BackupCatalog(BackupDirectory::resolve($this->config));
    }

    /** @return array<string,mixed> */
    private function lastRun(): array
    {
        $state = $this->state->read();
        $status = $state['status'] ?? 'idle';
        $state['log_tail'] = $status === 'idle' ? [] : $this->logTail();
        // proces zabit / spadl: stav zůstal `running`, ale nikdo nedrží zámek a start je dávno pryč
        $state['interrupted'] = $status === 'running' && !$this->preflight->isRunning();

        return $state;
    }

    /** @return list<string> */
    private function logTail(): array
    {
        $raw = @file_get_contents($this->logFile ?? RuntimePaths::log('restore.log'));
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $lines = array_slice(explode("\n", rtrim($raw)), -50);

        return array_map(static fn (string $l) => str_replace(Bootstrap::rootDir(), '<app>', $l), $lines);
    }
}
