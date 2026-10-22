<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Settings;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Report\WeeklyReportService;
use MyPortfolio\Service\Summary\PeriodReportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Týdenní e-mailový report:
 *   GET|PUT /api/settings/weekly-report  — zapnutí a příjemci
 *   POST    /api/settings/weekly-report/send    — odeslat hned
 *   GET     /api/settings/weekly-report/preview — HTML náhled e-mailu
 *   GET     /api/settings/period-report/preview?period=YYYY-MM|YYYY — náhled měsíčního / ročního souhrnu
 *           (bez období = minulý měsíc)
 */
final class WeeklyReportAction
{
    private const MAX_RECIPIENTS = 5;

    public function __construct(
        private readonly UserPreferenceRepository $preferences,
        private readonly WeeklyReportService $reports,
        private readonly PeriodReportService $periodReports,
        private readonly LoggerInterface $logger,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->settings(CurrentUser::id($request)));
    }

    public function update(Request $request, Response $response): Response
    {
        try {
            $userId = CurrentUser::id($request);
            $body = (array) ($request->getParsedBody() ?? []);
            $raw = $body['recipients'] ?? [];
            $recipients = [];
            foreach (is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw) as $email) {
                $email = strtolower(trim((string) $email));
                if ($email === '') {
                    continue;
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
                    throw DomainError::validation(['recipients' => ["Neplatný e-mail: $email"]]);
                }
                $recipients[$email] = $email;
            }
            if (count($recipients) > self::MAX_RECIPIENTS) {
                throw DomainError::validation(['recipients' => ['Nejvýše ' . self::MAX_RECIPIENTS . ' příjemců.']]);
            }
            $this->preferences->setWeeklyReport($userId, (bool) ($body['enabled'] ?? false), array_values($recipients), (bool) ($body['detailed'] ?? true),
                (bool) ($body['monthly'] ?? false), (bool) ($body['yearly'] ?? false));
            return Json::ok($response, $this->settings($userId));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function send(Request $request, Response $response): Response
    {
        $userId = CurrentUser::id($request);
        try {
            return Json::ok($response, ['sent_to' => $this->reports->send($userId)]);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        } catch (\Throwable $e) {
            $this->logger->error('weekly_report.send_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return Json::error($response, 'mail_failed', 'E-mail se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }
    }

    public function preview(Request $request, Response $response): Response
    {
        $response->getBody()->write($this->reports->render(CurrentUser::id($request))['html']);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return array{enabled:bool,detailed:bool,recipients:list<string>,default_email:string,sent_at:?string} */
    private function settings(int $userId): array
    {
        $s = $this->preferences->weeklyReport($userId)
            ?? ['enabled' => false, 'detailed' => true, 'monthly' => false, 'yearly' => false, 'recipients' => [], 'email' => '', 'sent_at' => null];
        return ['enabled' => $s['enabled'], 'detailed' => $s['detailed'], 'monthly' => $s['monthly'], 'yearly' => $s['yearly'],
            'recipients' => $s['recipients'], 'default_email' => $s['email'], 'sent_at' => $s['sent_at']];
    }

    public function periodPreview(Request $request, Response $response): Response
    {
        $period = (string) ($request->getQueryParams()['period'] ?? PeriodReportService::previous('monthly', new \DateTimeImmutable('today')));
        try {
            $response->getBody()->write($this->periodReports->render(CurrentUser::id($request), $period)['html']);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
