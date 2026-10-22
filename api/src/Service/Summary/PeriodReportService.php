<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Summary;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Report\ReportFormat;

/**
 * Měsíční a roční souhrn e-mailem — data z PeriodSummaryService,
 * šablona `period_report`, příjemci společní s týdenním reportem.
 */
final class PeriodReportService
{
    private const MONTHS = [
        'cs' => ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    public function __construct(
        private readonly UserPreferenceRepository $preferences,
        private readonly PeriodSummaryService $summary,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {}

    /** Poslední uzavřený měsíc (`YYYY-MM`) nebo rok (`YYYY`) k dnešku. */
    public static function previous(string $kind, \DateTimeImmutable $today): string
    {
        return $kind === 'yearly' ? (string) ((int) $today->format('Y') - 1) : $today->modify('first day of last month')->format('Y-m');
    }

    /** @return array{subject:string,html:string,text:string} */
    public function render(int $userId, string $period): array
    {
        [$settings, $vars] = $this->prepare($userId, $period);
        return $this->mailer->render('period_report', $settings['locale'], $vars);
    }

    /** @return list<string> příjemci */
    public function send(int $userId, string $period): array
    {
        [$settings, $vars] = $this->prepare($userId, $period);
        $recipients = $settings['recipients'] !== [] ? $settings['recipients'] : [$settings['email']];
        $this->mailer->sendTemplate('period_report', $settings['locale'], $recipients, $vars);
        return $recipients;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function prepare(int $userId, string $period): array
    {
        if (preg_match('/^\d{4}(-(0[1-9]|1[0-2]))?$/', $period) !== 1) {
            throw DomainError::validation(['period' => ['Období musí být YYYY nebo YYYY-MM.']]);
        }
        $settings = $this->preferences->weeklyReport($userId) ?? throw DomainError::notFound('Uživatel nenalezen.');
        $locale = in_array($settings['locale'], ['cs', 'en'], true) ? $settings['locale'] : 'cs';
        $settings['locale'] = $locale;
        $currency = $settings['display_currency'];
        $data = $this->summary->period($userId, $currency, $period);
        if ($data['total'] === null) {
            throw DomainError::notFound('Za období nejsou žádná data.');
        }

        $f = new ReportFormat($locale);
        $decorate = fn (array $m): array => $m + [
            'label'        => $this->label($m['period'] ?? $period, $locale),
            'value_from_f' => $f->money($m['value_from'], $currency),
            'value_to_f'   => $f->money($m['value_to'], $currency),
            'flow_f'       => $f->money($m['flow'], $currency, true),
            'change_f'     => $f->money($m['change'], $currency, true),
            'gain_f'       => $f->money($m['gain'], $currency, true),
            'gain_pct_f'   => $f->pct($m['gain_pct']),
            'gain_color'   => $f->color($m['gain']),
            'dividends_f'  => $f->money($m['dividends'], $currency),
            'interest_f'   => $f->money($m['interest'], $currency),
            'fees_f'       => $f->money($m['fees'], $currency),
            'withheld_f'   => $f->money($m['withheld'], $currency),
        ];
        $label = $this->label($period, $locale);
        $yearly = strlen($period) === 4;
        return [$settings, [
            'yearly'     => $yearly,
            'period'     => $label,
            'total'      => $decorate($data['total']),
            'portfolios' => array_map($decorate, $data['portfolios']),
            'months'     => array_map($decorate, $data['months']),
            'app_url'    => rtrim((string) $this->config->get('app.url', ''), '/'),
            'subject'    => match (true) {
                $locale === 'cs' && $yearly => "Roční souhrn portfolia $label",
                $locale === 'cs'            => "Měsíční souhrn portfolia — $label",
                $yearly                     => "Yearly portfolio summary $label",
                default                     => "Monthly portfolio summary — $label",
            },
        ]];
    }

    private function label(string $period, string $locale): string
    {
        if (strlen($period) === 4) {
            return $period;
        }
        [$y, $m] = explode('-', $period);
        return self::MONTHS[$locale][(int) $m - 1] . ' ' . $y;
    }
}
