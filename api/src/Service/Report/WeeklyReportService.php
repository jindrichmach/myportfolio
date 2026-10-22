<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Report;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\History\HistoryService;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Service\Portfolio\DomainError;

/**
 * Týdenní e-mailový report: dopočítá snapshoty, sestaví data (WeeklyReportBuilder), naformátuje je
 * podle jazyka uživatele a vyrenderuje / odešle šablonu `weekly_report`.
 */
final class WeeklyReportService
{
    private const TX_TYPES = [
        'cs' => ['buy' => 'Nákup', 'sell' => 'Prodej', 'reward' => 'Odměna', 'transfer_in' => 'Převod — příjem',
            'transfer_out' => 'Převod — odchod', 'deposit' => 'Vklad', 'withdrawal' => 'Výběr', 'dividend' => 'Dividenda',
            'interest' => 'Úrok', 'fee' => 'Poplatek', 'split' => 'Split'],
        'en' => ['buy' => 'Buy', 'sell' => 'Sell', 'reward' => 'Reward', 'transfer_in' => 'Transfer in',
            'transfer_out' => 'Transfer out', 'deposit' => 'Deposit', 'withdrawal' => 'Withdrawal', 'dividend' => 'Dividend',
            'interest' => 'Interest', 'fee' => 'Fee', 'split' => 'Split'],
    ];

    public function __construct(
        private readonly UserPreferenceRepository $preferences,
        private readonly WeeklyReportBuilder $builder,
        private readonly HistoryService $history,
        private readonly FxService $fx,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {}

    /** @return array{subject:string,html:string,text:string} */
    public function render(int $userId): array
    {
        [$settings, $vars] = $this->prepare($userId);
        return $this->mailer->render('weekly_report', $settings['locale'], $vars);
    }

    /**
     * @return list<string> příjemci
     */
    public function send(int $userId): array
    {
        [$settings, $vars] = $this->prepare($userId);
        $recipients = $settings['recipients'] !== [] ? $settings['recipients'] : [$settings['email']];
        $this->mailer->sendTemplate('weekly_report', $settings['locale'], $recipients, $vars);
        $this->preferences->markWeeklyReportSent($userId);
        return $recipients;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function prepare(int $userId): array
    {
        $settings = $this->preferences->weeklyReport($userId) ?? throw DomainError::notFound('Uživatel nenalezen.');
        $locale = in_array($settings['locale'], ['cs', 'en'], true) ? $settings['locale'] : 'cs';
        $settings['locale'] = $locale;
        $this->history->refresh($userId);
        $report = $this->builder->build($userId, $settings['display_currency'], $this->fx->today());

        $f = new ReportFormat($locale);
        $cur = $report['currency'];
        $decorate = static fn (array $m): array => $m + [
            'value_from_f' => $f->money($m['value_from'], $cur),
            'value_to_f'   => $f->money($m['value_to'], $cur),
            'change_f'     => $f->money($m['change'], $cur, true),
            'change_pct_f' => $f->pct($m['change_pct']),
            'change_color' => $f->color($m['change']),
            'flow_f'       => $f->money($m['flow'], $cur, true),
            'gain_f'       => $f->money($m['gain'], $cur, true),
            'gain_pct_f'   => $f->pct($m['gain_pct']),
            'gain_color'   => $f->color($m['gain']),
        ];

        $portfolios = [];
        foreach ($report['portfolios'] as $pf) {
            $accounts = [];
            foreach ($pf['accounts'] as $acc) {
                $accounts[] = ['positions' => array_map($decorate, $acc['positions'])] + $decorate($acc);
            }
            $portfolios[] = ['accounts' => $accounts] + $decorate($pf);
        }
        $transactions = array_map(static fn (array $t) => $t + [
            'date_f'     => $f->date($t['date'], false),
            'type_f'     => self::TX_TYPES[$locale][$t['type']] ?? $t['type'],
            'quantity_f' => $t['quantity'] !== null ? $f->quantity($t['quantity']) : '',
            'amount_f'   => $t['amount'] !== null ? $f->money($t['amount'], $t['currency']) : '',
        ], $report['transactions']);

        $period = $f->date($report['from'], false) . ' – ' . $f->date($report['to']);
        $vars = [
            'name'         => $settings['name'],
            'detailed'     => $settings['detailed'],
            'period'       => $period,
            'total'        => $decorate($report['total']),
            'portfolios'   => $portfolios,
            'transactions' => $transactions,
            'app_url'      => rtrim((string) $this->config->get('app.url', ''), '/'),
            'subject'      => ($locale === 'cs' ? 'Týdenní přehled portfolia ' : 'Weekly portfolio summary ') . $period,
        ];
        return [$settings, $vars];
    }
}
