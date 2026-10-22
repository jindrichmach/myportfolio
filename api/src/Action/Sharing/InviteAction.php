<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Sharing;

use MyPortfolio\Http\Json;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Sharing\ShareService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Veřejná pozvánka ke sdílení (odkaz z e-mailu, bez přihlášení):
 *   GET  /api/public/invite/{token}  — kdo zve a co sdílí
 *   POST /api/public/invite/{token}  — založit účet (jméno, heslo) a přijmout sdílení
 */
final class InviteAction
{
    public function __construct(private readonly ShareService $service) {}

    /** @param array{token:string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            return Json::ok($response, $this->service->invite($args['token']));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{token:string} $args */
    public function accept(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->accept($args['token'], (array) ($request->getParsedBody() ?? []));
            return Json::ok($response, ['accepted' => true], 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }
}
