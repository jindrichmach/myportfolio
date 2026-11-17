<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use MyPortfolio\Http\Json;
use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Config\RuntimePaths;
use MyPortfolio\Service\PhpCliLocator;
use MyPortfolio\Service\Update\UpdateChecker;
use MyPortfolio\Service\Update\UpdateState;
use MyPortfolio\Service\Update\WebUpdatePreflight;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/update[?refresh=1] — aktuální a nejnovější vydaná verze + údaje pro instrukce
 * k aktualizaci (typ instalace, CLI php). Cestu k aplikaci neposílá — neprozrazuje se layout serveru. Dále `web_update.reasons` (důvody, proč nejde aktualizovat z webu) a `last_run` (stav posledního běhu + konec logu).
 */
final class UpdateStatusAction
{
    private const STALE_AFTER_SECONDS = 60;

    public function __construct(
        private readonly UpdateChecker $checker,
        private readonly Config $config,
        private readonly WebUpdatePreflight $preflight,
        private readonly UpdateState $state,
        private readonly ?string $logFile = null,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $refresh = ($request->getQueryParams()['refresh'] ?? '') === '1';

        return Json::ok($response, $this->checker->check($refresh) + [
            // Docker image nastavuje MYPORTFOLIO_DATA_DIR (data mimo kód); nativní instalace ho nemá
            'install'  => $this->config->dataDir() !== null ? 'docker' : 'native',
            'php'      => PhpCliLocator::resolve() ?? 'php',
            'web_update' => ['reasons' => $this->preflight->check()],
            'last_run' => $this->lastRun(),
        ]);
    }

    /** @return array<string,mixed> */
    private function lastRun(): array
    {
        $state = $this->state->read();
        unset($state['pid']);
        $status = $state['status'] ?? 'idle';
        $state['log_tail'] = $status === 'idle' ? [] : $this->logTail();
        // runner spadl / byl zabit dřív, než převzal stav: nikdo nedrží zámek a start je dávno pryč
        $startedAt = strtotime((string) ($state['started_at'] ?? ''));
        $state['stale'] = $status === 'running'
            && !in_array('running', $this->preflight->check(), true)
            && ($startedAt === false || $startedAt < time() - self::STALE_AFTER_SECONDS);
        return $state;
    }

    /** @return list<string> */
    private function logTail(): array
    {
        $raw = @file_get_contents($this->logFile ?? RuntimePaths::log('update.log'));
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $lines = array_slice(explode("\n", rtrim($raw)), -50);
        return array_map(static fn (string $l) => str_replace(Bootstrap::rootDir(), '<app>', $l), $lines);
    }
}
