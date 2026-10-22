<?php

declare(strict_types=1);

namespace MyPortfolio\Action\System;

use MyPortfolio\Http\Json;
use MyPortfolio\Service\Update\VersionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/version — public endpoint pro footer / about / health probe.
 */
final class VersionAction
{
    public function __construct(private readonly VersionService $version) {}

    public function __invoke(Request $request, Response $response): Response
    {
        return Json::ok($response, [
            'current' => $this->version->getCurrentVersion(),
        ]);
    }
}
