<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Portfolio;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Service\DataQuality\DataQualityService;
use MyPortfolio\Service\Fx\FxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/data-quality — problémy s daty přihlášeného uživatele (ceny, kurzy, nabývací ceny, bankovní avíza).
 * Osobní cesta: v kontextu sdílení ukazuje vždy vlastní data.
 */
final class DataQualityAction
{
    public function __construct(
        private readonly DataQualityService $quality,
        private readonly FxService $fx,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->quality->report(CurrentUser::id($request), $this->fx->today()));
    }
}
