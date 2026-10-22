<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Portfolio;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\ManualValuationRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Portfolio\ValuationService;
use MyPortfolio\Support\Decimal;
use MyPortfolio\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ocenění a přehled (vše v měně zobrazení uživatele):
 *   GET /api/dashboard
 *   GET /api/positions/{id}/valuation            — ocenění pozice + loty a úbytky
 *   GET|POST /api/positions/{id}/valuations      — ruční ocenění (jen pozice valuation_mode=manual)
 *   DELETE /api/valuations/{id}
 */
final class ValuationAction
{
    public function __construct(
        private readonly ValuationService $valuation,
        private readonly PositionRepository $positions,
        private readonly ManualValuationRepository $valuations,
        private readonly FxService $fx,
        private readonly UserPreferenceRepository $preferences,
        private readonly SnapshotRepository $snapshots,
    ) {}

    public function dashboard(Request $request, Response $response): Response
    {
        $userId = CurrentUser::owner($request);
        return Json::ok($response, $this->valuation->dashboard($userId, $this->displayCurrency(CurrentUser::id($request))));
    }

    /** @param array{id:string} $args */
    public function position(Request $request, Response $response, array $args): Response
    {
        $userId = CurrentUser::owner($request);
        $id = (int) $args['id'];
        $currency = $this->displayCurrency(CurrentUser::id($request));
        $row = $this->valuation->positions($userId, $currency, $id)[0] ?? null;
        if ($row === null) {
            return Json::domainError($response, DomainError::notFound('Pozice nenalezena.'));
        }
        return Json::ok($response, [
            ...$row,
            ...$this->valuation->lotDetail($userId, $id, $currency),
        ]);
    }

    /** @param array{id:string} $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        $userId = CurrentUser::owner($request);
        $position = $this->positions->find($userId, (int) $args['id']);
        if ($position === null) {
            return Json::domainError($response, DomainError::notFound('Pozice nenalezena.'));
        }
        return Json::ok($response, $this->valuations->list($userId, (int) $position['id']));
    }

    /** @param array{id:string} $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = CurrentUser::owner($request);
            $position = $this->positions->find($userId, (int) $args['id']) ?? throw DomainError::notFound('Pozice nenalezena.');
            if ($position['valuation_mode'] !== 'manual') {
                throw new DomainError('not_manual', 'Ruční ocenění lze zadat jen u ručně oceňované pozice.', 422);
            }
            if ($position['is_cash']) {
                throw new DomainError('cash_balance', 'U hotovosti nastav zůstatek (POST /api/positions/{id}/cash-balance), ne ocenění.', 422);
            }
            $body = (array) ($request->getParsedBody() ?? []);
            $errors = [];

            $date = is_string($body['date'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $body['date']) : false;
            if ($date === false || $date->format('Y-m-d') !== $body['date']) {
                $errors['date'][] = 'Neplatné datum.';
            } elseif ($date > $this->fx->today()) {
                $errors['date'][] = 'Datum nesmí být v budoucnosti.';
            }
            $value = null;
            try {
                $value = Decimal::parse($body['value'] ?? null, Decimal::SCALE_AMOUNT);
            } catch (\InvalidArgumentException $e) {
                $errors['value'][] = $e->getMessage();
            }
            if ($value === null && !isset($errors['value'])) {
                $errors['value'][] = 'Zadej hodnotu.';
            } elseif ($value !== null && $value->isNegative()) {
                $errors['value'][] = 'Hodnota nesmí být záporná.';
            }
            $currency = Input::currency($body, 'currency', false, $errors, (string) $position['currency']);
            $note = Input::string($body, 'note', 500, false, $errors);
            Input::assertValid($errors);

            $this->valuations->upsert($userId, (int) $position['id'], (string) $body['date'],
                (string) Decimal::toDb($value, Decimal::SCALE_AMOUNT), (string) $currency, $note);
            $this->snapshots->markDirty($userId, (int) $position['id'], (string) $body['date']);
            return Json::ok($response, $this->valuations->list($userId, (int) $position['id']), 201);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = CurrentUser::owner($request);
        $row = $this->valuations->find($userId, (int) $args['id']);
        // pozice musí být viditelná (sdílení vybraných portfolií)
        if ($row === null || $this->positions->find($userId, (int) $row['position_id']) === null) {
            return Json::domainError($response, DomainError::notFound('Ocenění nenalezeno.'));
        }
        $this->valuations->delete($userId, (int) $row['id']);
        $this->snapshots->markDirty($userId, (int) $row['position_id'], (string) $row['date']);
        return $response->withStatus(204);
    }

    private function displayCurrency(int $userId): string
    {
        return $this->preferences->displayCurrency($userId);
    }
}
