<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Settings;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Price\PriceService;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/settings/benchmark {instrument_id|null} — instrument pro porovnání v grafu historie.
 * Rovnou doplní jeho historii cen od první transakce uživatele (dál ji udržuje cron-prices).
 */
final class BenchmarkAction
{
    public function __construct(
        private readonly UserPreferenceRepository $preferences,
        private readonly InstrumentRepository $instruments,
        private readonly TransactionRepository $transactions,
        private readonly PriceService $prices,
    ) {}

    public function update(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $body = (array) ($request->getParsedBody() ?? []);
            $errors = [];
            $id = Input::id($body, 'instrument_id', false, $errors);
            $instrument = $id !== null ? $this->instruments->find($id) : null;
            if ($id !== null && ($instrument === null || !$instrument['is_active'])) {
                $errors['instrument_id'][] = 'Instrument nenalezen.';
            }
            Input::assertValid($errors);
            $this->preferences->setBenchmark($userId, $id);

            $backfill = ['inserted' => 0, 'errors' => []];
            $first = $this->transactions->firstDate($userId);
            if ($instrument !== null && $first !== null) {
                $backfill = $this->prices->backfill($instrument, new \DateTimeImmutable($first), new \DateTimeImmutable('today'));
            }
            return Json::ok($response, [
                'instrument_id' => $id,
                'symbol'        => $instrument['symbol'] ?? null,
                'inserted'      => $backfill['inserted'],
                'errors'        => array_slice($backfill['errors'], 0, 5),
            ]);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }
}
