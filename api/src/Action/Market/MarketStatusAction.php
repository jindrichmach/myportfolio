<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Market;

use MyPortfolio\Http\Json;
use MyPortfolio\Repository\CpiRepository;
use MyPortfolio\Repository\FxRateRepository;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\PriceRepository;
use MyPortfolio\Service\Price\PriceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/market-status — stav cen per instrument (poslední cena, zdroj, pokrytí
 * historie, zastaralost), poslední kurzový lístek ČNB a poslední měsíc indexu cen (inflace).
 */
final class MarketStatusAction
{
    public function __construct(
        private readonly InstrumentRepository $instruments,
        private readonly PriceRepository $prices,
        private readonly PriceService $service,
        private readonly FxRateRepository $fx,
        private readonly CpiRepository $cpi,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $instruments = $this->instruments->list(false);
        $last = $this->service->lastPrices($instruments);
        $rows = [];
        foreach ($instruments as $instrument) {
            $coverage = $this->prices->coverage($instrument['id']);
            $rows[] = [
                'id'           => $instrument['id'],
                'symbol'       => $instrument['symbol'],
                'name'         => $instrument['name'],
                'asset_class'  => $instrument['asset_class'],
                'price_source' => $instrument['price_source'],
                'chain'        => $this->service->chainNames($instrument),
                'last_price'   => $last[$instrument['id']] ?? null,
                'history_from' => $coverage['min'],
                'history_days' => $coverage['count'],
            ];
        }
        $fxDate = $this->fx->latestDate();
        return Json::ok($response, [
            'instruments' => $rows,
            'fx'          => ['date' => $fxDate, 'rates' => $fxDate !== null ? $this->fx->ratesOn($fxDate) : []],
            'cpi'         => ['latest_month' => $this->cpi->latestMonth()],
        ]);
    }
}
