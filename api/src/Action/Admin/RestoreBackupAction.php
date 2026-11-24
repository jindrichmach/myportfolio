<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use Closure;
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
use MyPortfolio\Service\BackgroundProcess;
use MyPortfolio\Service\Cron\BackupEncryption;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/backups/restore — spustí na pozadí obnovu instance ze zálohy (`api/bin/restore-backup.php --web`).
 *
 * Body: { file, confirm, totp_code? | step_up_token? }. `file` je název zálohy ze seznamu (žádné cesty),
 * `confirm` musí být jeho přesná kopie. Runner si stav přepíše sám; API před startem zapíše `running`.
 */
final class RestoreBackupAction
{
    /** @var Closure(string $script, list<string> $args, string $logPath, string $cwd): bool */
    private readonly Closure $spawner;

    /**
     * @param (callable(string $script, list<string> $args, string $logPath, string $cwd): bool)|null $spawner
     */
    public function __construct(
        private readonly Config $config,
        private readonly RestorePreflight $preflight,
        private readonly RestoreState $state,
        private readonly StepUpGate $stepUp,
        ?callable $spawner = null,
    ) {
        $this->spawner = Closure::fromCallable($spawner ?? static function (string $script, array $args, string $logPath, string $cwd): bool {
            return BackgroundProcess::spawnPhp($script, $args, $logPath, $cwd);
        });
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $userId = (int) ($user['id'] ?? 0);

        $body = (array) ($request->getParsedBody() ?? []);
        $file = (string) ($body['file'] ?? '');
        $catalog = new BackupCatalog(BackupDirectory::resolve($this->config));
        $path = $catalog->find($file);
        if ($path === null) {
            return Json::error($response, 'not_found', 'Záloha nenalezena.', 404);
        }
        if ((string) ($body['confirm'] ?? '') !== $file) {
            return Json::error($response, 'confirm_mismatch', 'Potvrzení neodpovídá názvu zálohy.', 422);
        }

        $reasons = $this->preflight->check();
        if (BackupEncryption::passwordFromConfig($this->config) === '' && $this->isEncrypted($catalog, $file)) {
            $reasons[] = 'no_password';
        }
        if ($reasons !== []) {
            return Json::error($response, 'restore_not_possible', 'Obnovu z webu nelze spustit.', 409, ['reasons' => $reasons]);
        }

        $denied = $this->stepUp->check($request, $response, $userId, $body, MfaStepUpService::OPERATION_BACKUP_RESTORE);
        if ($denied !== null) {
            return $denied;
        }

        $startedAt = date(\DateTimeInterface::ATOM);
        $this->state->write([
            'status' => 'running', 'file' => $file, 'step' => 'starting',
            'started_at' => $startedAt, 'finished_at' => null, 'error_step' => null,
        ]);
        $script = Bootstrap::rootDir() . '/api/bin/restore-backup.php';
        $spawned = ($this->spawner)(
            $script,
            [$file, '--yes', '--web', '--actor=' . $userId],
            RuntimePaths::log('restore-spawn.log'),
            Bootstrap::rootDir(),
        );
        if (!$spawned) {
            $this->state->write([
                'status' => 'failed', 'file' => $file, 'step' => 'starting',
                'started_at' => $startedAt, 'finished_at' => date(\DateTimeInterface::ATOM), 'error_step' => 'spawn',
            ]);
            return Json::error($response, 'spawn_failed', 'Nepodařilo se spustit obnovu na pozadí.', 500);
        }

        return Json::ok($response, ['started' => true, 'file' => $file], 202);
    }

    private function isEncrypted(BackupCatalog $catalog, string $file): bool
    {
        foreach ($catalog->list() as $b) {
            if ($b['name'] === $file) {
                return $b['encrypted'];
            }
        }

        return false;
    }
}
