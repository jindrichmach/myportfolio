<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use MyPortfolio\Bootstrap;
use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\PhpCliLocator;
use MyPortfolio\Service\Update\UpdateChecker;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/update[?refresh=1] — aktuální a nejnovější vydaná verze + údaje pro instrukce
 * k aktualizaci (typ instalace, cesta k aplikaci, CLI php). Aktualizace sama se spouští ručně na serveru.
 */
final class UpdateStatusAction
{
    public function __construct(
        private readonly UpdateChecker $checker,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $refresh = ($request->getQueryParams()['refresh'] ?? '') === '1';

        return Json::ok($response, $this->checker->check($refresh) + [
            // Docker image nastavuje MYPORTFOLIO_DATA_DIR (data mimo kód); nativní instalace ho nemá
            'install'  => $this->config->dataDir() !== null ? 'docker' : 'native',
            'app_dir'  => Bootstrap::rootDir(),
            'php'      => PhpCliLocator::resolve() ?? 'php',
        ]);
    }
}
