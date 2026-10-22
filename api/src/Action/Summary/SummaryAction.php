<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Summary;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Summary\IncomeOverviewService;
use MyPortfolio\Service\Summary\PeriodSummaryService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Souhrny v měně zobrazení:
 *   GET /api/summary?portfolio_id=&account_id=  — měsíční a roční souhrn
 *   GET /api/income?year=                       — přijaté dividendy a úroky (bez roku = celá historie)
 */
final class SummaryAction
{
    public function __construct(
        private readonly PeriodSummaryService $summary,
        private readonly IncomeOverviewService $income,
        private readonly UserPreferenceRepository $preferences,
    ) {}

    public function summary(Request $request, Response $response): Response
    {
        $userId = CurrentUser::owner($request);
        $q = $request->getQueryParams();
        try {
            return Json::ok($response, $this->summary->build($userId, $this->preferences->displayCurrency(CurrentUser::id($request)), [
                'portfolio_id' => isset($q['portfolio_id']) && $q['portfolio_id'] !== '' ? (int) $q['portfolio_id'] : null,
                'account_id'   => isset($q['account_id']) && $q['account_id'] !== '' ? (int) $q['account_id'] : null,
            ]));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function income(Request $request, Response $response): Response
    {
        $userId = CurrentUser::owner($request);
        $year = $request->getQueryParams()['year'] ?? null;
        return Json::ok($response, $this->income->overview($userId, $this->preferences->displayCurrency(CurrentUser::id($request)),
            $year !== null && $year !== '' ? (int) $year : null));
    }
}
