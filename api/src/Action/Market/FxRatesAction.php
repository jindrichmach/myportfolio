<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Market;

use MyPortfolio\Http\Json;
use MyPortfolio\Repository\FxRateRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/fx-rates?date=YYYY-MM-DD — kurzový lístek ČNB platný k datu (Kč za `amount` jednotek).
 */
final class FxRatesAction
{
    public function __construct(private readonly FxRateRepository $rates) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $requested = (string) ($request->getQueryParams()['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) !== 1) {
            $requested = (string) ($this->rates->latestDate() ?? date('Y-m-d'));
        }
        $eur = $this->rates->rateOnOrBefore('EUR', $requested, (new \DateTimeImmutable($requested))->modify('-7 days')->format('Y-m-d'));
        $date = $eur['date'] ?? null;
        return Json::ok($response, [
            'requested_date' => $requested,
            'date'           => $date,
            'rates'          => $date !== null ? $this->rates->ratesOn($date) : [],
        ]);
    }
}
