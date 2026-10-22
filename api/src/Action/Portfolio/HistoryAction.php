<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Portfolio;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\History\HistoryService;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/history?scope=total|portfolio|position|class&id=&range=1M|3M|YTD|1Y|ALL
 * — denní hodnota a investováno v měně zobrazení + TWR, XIRR, jednoduchý výnos.
 */
final class HistoryAction
{
    public function __construct(
        private readonly HistoryService $history,
        private readonly UserPreferenceRepository $preferences,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = CurrentUser::owner($request);
        $q = $request->getQueryParams();
        $scope = (string) ($q['scope'] ?? 'total');
        $range = strtoupper((string) ($q['range'] ?? '1Y'));
        $id = isset($q['id']) && $q['id'] !== '' ? (string) $q['id'] : null;

        $errors = [];
        if (!in_array($scope, HistoryService::SCOPES, true)) {
            $errors['scope'][] = 'Neplatná úroveň.';
        }
        if (!in_array($range, HistoryService::RANGES, true)) {
            $errors['range'][] = 'Neplatné období.';
        }
        if ($scope !== 'total' && $id === null) {
            $errors['id'][] = 'Povinné pole.';
        }
        try {
            if ($errors !== []) {
                throw DomainError::validation($errors);
            }
            return Json::ok($response, $this->history->history($userId, $this->preferences->displayCurrency(CurrentUser::id($request)), $scope, $id, $range, CurrentUser::id($request)));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }
}
