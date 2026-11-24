<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use Closure;
use MyPortfolio\Bootstrap;
use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\MfaStepUpService;
use MyPortfolio\Service\Auth\StepUpGate;
use MyPortfolio\Service\BackgroundProcess;
use MyPortfolio\Service\IpMatcher;
use MyPortfolio\Service\Update\MaintenanceMode;
use MyPortfolio\Service\Update\UpdateChecker;
use MyPortfolio\Service\Update\UpdateState;
use MyPortfolio\Service\Update\WebUpdatePreflight;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/update/run — spustí na pozadí aktualizaci nativní instalace na nejnovější vydání.
 *
 * Body: { version, totp_code? | step_up_token? }. `version` musí být nejnovější vydání z UpdateChecker
 * (žádná libovolná ref). Na pozadí běží `api/bin/native-update.php` (balíček vydání, ne git). Stejný MFA step-up jako vytvoření API tokenu.
 */
final class RunUpdateAction
{
    /** @var Closure(string $script, list<string> $args, string $logPath, string $cwd): bool */
    private readonly Closure $spawner;

    /**
     * @param (callable(string $script, list<string> $args, string $logPath, string $cwd): bool)|null $spawner
     */
    public function __construct(
        private readonly UpdateChecker $checker,
        private readonly WebUpdatePreflight $preflight,
        private readonly UpdateState $state,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
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
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error($response, 'forbidden_via_token', 'Aktualizaci lze spustit jen z webového rozhraní.', 403);
        }
        $userId = (int) ($user['id'] ?? 0);

        $body = (array) ($request->getParsedBody() ?? []);
        $version = trim((string) ($body['version'] ?? ''));
        $check = $this->checker->check();
        if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/D', $version) !== 1
            || !$check['update_available'] || $check['latest'] === null || $check['latest']['version'] !== $version) {
            return Json::error($response, 'invalid_version', 'Aktualizovat lze jen na nejnovější vydanou verzi.', 422);
        }

        $reasons = $this->preflight->check();
        $current = $this->state->read();
        if (($current['status'] ?? '') === 'running'
            && strtotime((string) ($current['started_at'] ?? '')) > time() - MaintenanceMode::TTL_SECONDS
            && !in_array('running', $reasons, true)) {
            $reasons[] = 'running';
        }
        if ($reasons !== []) {
            return Json::error($response, 'update_not_possible', 'Aktualizaci z webu nelze spustit.', 409, ['reasons' => $reasons]);
        }

        $denied = $this->stepUp->check(
            $request,
            $response,
            $userId,
            $body,
            MfaStepUpService::OPERATION_UPDATE_RUN,
            'Pro spuštění aktualizace je vyžadováno nové ověření.',
        );
        if ($denied !== null) {
            return $denied;
        }

        $from = $check['current'];
        $startedAt = date(\DateTimeInterface::ATOM);
        $this->state->write([
            'status' => 'running', 'from' => $from, 'target' => $version, 'step' => 'starting',
            'started_at' => $startedAt, 'finished_at' => null, 'error_step' => null,
        ]);

        $logPath = RuntimePaths::log('update.log');
        $spawned = ($this->spawner)(Bootstrap::rootDir() . '/api/bin/native-update.php', ['--target=' . $version], $logPath, Bootstrap::rootDir());
        if (!$spawned) {
            $this->state->write([
                'status' => 'failed', 'from' => $from, 'target' => $version, 'step' => 'starting',
                'started_at' => $startedAt, 'finished_at' => date(\DateTimeInterface::ATOM), 'error_step' => 'spawn',
            ]);
            return Json::error($response, 'spawn_failed', 'Nepodařilo se spustit aktualizaci na pozadí.', 500);
        }

        $this->activity->log(
            'admin.update.run',
            $userId,
            null,
            null,
            ['from' => $from, 'target' => $version],
            $this->ipMatcher->clientIp($request->getServerParams(), [], 'X-Forwarded-For'),
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, ['started' => true, 'target' => $version], 202);
    }
}
