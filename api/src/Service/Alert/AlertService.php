<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Alert;

use Brick\Math\BigDecimal;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Repository\AlertRepository;
use MyPortfolio\Repository\AllocationTargetRepository;
use MyPortfolio\Repository\BankNoticeRepository;
use MyPortfolio\Repository\FxRateRepository;
use MyPortfolio\Repository\ImapAccountRepository;
use MyPortfolio\Repository\LotRepository;
use MyPortfolio\Repository\PortfolioRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\UserPreferenceRepository;
use MyPortfolio\Service\Allocation\RebalanceService;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\History\HistoryService;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Price\PriceService;
use MyPortfolio\Service\Report\ReportFormat;
use MyPortfolio\Service\Report\WeeklyReportBuilder;

/**
 * Denní souhrn e-mailových upozornění: cenové hranice, velký denní pohyb
 * (včerejšek, bez vkladů a výběrů), blížící se časový test lotů, alokace mimo toleranci a problémy s daty. Co už bylo ohlášeno,
 * hlídá `alert_log` (cenová hranice svým `triggered_at`) — prázdný souhrn se neposílá.
 */
final class AlertService
{
    private const TAXED_CLASSES = ['crypto', 'etf', 'stock', 'fund'];
    private const NOTICE_PROBLEMS = ['unmapped', 'parse_failed', 'security_rejected', 'import_failed'];
    private const FX_MAX_AGE_DAYS = 4;
    private const NOTICE_LOOKBACK_DAYS = 7;

    public function __construct(
        private readonly UserPreferenceRepository $preferences,
        private readonly AlertRepository $alerts,
        private readonly PositionRepository $positions,
        private readonly LotRepository $lots,
        private readonly BankNoticeRepository $notices,
        private readonly ImapAccountRepository $imap,
        private readonly FxRateRepository $fxRates,
        private readonly WeeklyReportBuilder $builder,
        private readonly HistoryService $history,
        private readonly PriceService $prices,
        private readonly FxService $fx,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly AllocationTargetRepository $allocationTargets,
        private readonly RebalanceService $rebalance,
        private readonly PortfolioRepository $portfolios,
    ) {}

    /**
     * Vyhodnotí upozornění a pošle souhrn, pokud je co hlásit.
     *
     * @return array{sent:bool,recipients:list<string>,counts:array<string,int>}
     */
    public function run(int $userId): array
    {
        $settings = $this->preferences->alerts($userId) ?? throw DomainError::notFound('Uživatel nenalezen.');
        $locale = in_array($settings['locale'], ['cs', 'en'], true) ? $settings['locale'] : 'cs';
        $digest = $this->collect($userId, $settings, $this->fx->today());
        $counts = array_map('count', $digest['sections']);
        if (array_sum($counts) === 0) {
            return ['sent' => false, 'recipients' => [], 'counts' => $counts];
        }

        $recipients = $settings['recipients'] !== [] ? $settings['recipients'] : [$settings['email']];
        $this->mailer->sendTemplate('alerts_digest', $locale, $recipients, $this->vars($digest, $locale, array_sum($counts)));
        $this->alerts->log($userId, $digest['keys']);
        foreach ($digest['sections']['price'] as $p) {
            $this->alerts->markTriggered($userId, $p['id'], $p['current']);
        }
        $this->preferences->markAlertsSent($userId);
        return ['sent' => true, 'recipients' => $recipients, 'counts' => $counts];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{sections:array<string,list<array<string,mixed>>>,keys:list<string>,currency:string,date:string}
     */
    public function collect(int $userId, array $settings, \DateTimeImmutable $today): array
    {
        $candidates = [];  // klíč alert_log => [sekce, položka]
        $positions = [];
        foreach ($this->positions->list($userId) as $p) {
            $positions[(int) $p['id']] = $p;
        }
        $held = $this->heldLots($userId, $positions);

        $price = $this->priceAlerts($userId);
        if ($settings['move_portfolio_pct'] !== null || $settings['move_position_pct'] !== null) {
            foreach ($this->moves($userId, $settings, $today) as $key => $item) {
                $candidates[self::key($key)] = ['moves', $item];
            }
        }
        if ($settings['holding_days'] !== null) {
            foreach ($this->holding($held, $positions, $today, $settings['holding_days']) as $key => $item) {
                $candidates[self::key($key)] = ['holding', $item];
            }
        }
        if ($settings['allocation']) {
            foreach ($this->allocation($userId, $settings['display_currency'], $today) as $key => $item) {
                $candidates[self::key($key)] = ['allocation', $item];
            }
        }
        if ($settings['data_issues']) {
            foreach ($this->dataIssues($userId, $held, $positions, $today) as $key => $item) {
                $candidates[self::key($key)] = ['data', $item];
            }
        }

        $logged = array_flip($this->alerts->loggedKeys($userId, array_map('strval', array_keys($candidates))));
        $sections = ['price' => $price, 'moves' => [], 'holding' => [], 'allocation' => [], 'data' => []];
        $keys = [];
        foreach ($candidates as $key => [$section, $item]) {
            if (!isset($logged[$key])) {
                $sections[$section][] = $item;
                $keys[] = (string) $key;
            }
        }
        return ['sections' => $sections, 'keys' => $keys, 'currency' => $settings['display_currency'], 'date' => $today->format('Y-m-d')];
    }

    /** Klíč do alert_log (varchar 190) — dlouhé názvy zkrátí hashem, aby zůstal jednoznačný. */
    private static function key(string $key): string
    {
        return strlen($key) <= 190 ? $key : mb_strcut($key, 0, 150, 'UTF-8') . ':' . md5($key);
    }

    /** Aktivní cenové hranice, které poslední cena překročila. @return list<array<string,mixed>> */
    private function priceAlerts(int $userId): array
    {
        $active = array_values(array_filter($this->alerts->priceAlerts($userId), static fn (array $a) => $a['triggered_at'] === null));
        if ($active === []) {
            return [];
        }
        $instruments = array_map(static fn (array $a) => ['id' => $a['instrument_id'], 'asset_class' => $a['asset_class']], $active);
        $last = $this->prices->lastPrices($instruments);
        $out = [];
        foreach ($active as $a) {
            $quote = $last[$a['instrument_id']] ?? null;
            if ($quote === null) {
                continue;
            }
            try {
                $current = $quote['currency'] === $a['currency'] ? BigDecimal::of($quote['close'])
                    : $this->fx->convert(BigDecimal::of($quote['close']), $quote['currency'], $a['currency'], $quote['date']);
            } catch (FxRateMissing) {
                continue;
            }
            $hit = $a['direction'] === 'above' ? $current->isGreaterThanOrEqualTo($a['price']) : $current->isLessThanOrEqualTo($a['price']);
            if ($hit) {
                $out[] = ['id' => $a['id'], 'symbol' => $a['symbol'], 'name' => $a['instrument_name'], 'direction' => $a['direction'],
                    'threshold' => $a['price'], 'currency' => $a['currency'], 'current' => (string) $current->strippedOfTrailingZeros(),
                    'date' => $quote['date'], 'note' => $a['note']];
            }
        }
        return $out;
    }

    /**
     * Včerejší výnos (změna bez vkladů a výběrů) celku, portfolií a pozic nad prahem.
     *
     * @param array<string,mixed> $settings
     * @return array<string,array<string,mixed>>
     */
    private function moves(int $userId, array $settings, \DateTimeImmutable $today): array
    {
        $this->history->refresh($userId);
        $day = $today->modify('-1 day');
        $report = $this->builder->build($userId, $settings['display_currency'], $day, 1);
        $date = $day->format('Y-m-d');
        $over = static fn (?string $pct, ?string $limit) => $pct !== null && $limit !== null
            && BigDecimal::of($pct)->abs()->isGreaterThanOrEqualTo($limit);
        $item = static fn (string $scope, string $name, array $m) => ['scope' => $scope, 'name' => $name,
            'gain' => $m['gain'], 'gain_pct' => $m['gain_pct'], 'value' => $m['value_to'], 'date' => $date];

        $out = [];
        if ($over($report['total']['gain_pct'], $settings['move_portfolio_pct']) && count($report['portfolios']) > 1) {
            $out["move:total:$date"] = $item('total', '', $report['total']);
        }
        foreach ($report['portfolios'] as $pf) {
            if ($over($pf['gain_pct'], $settings['move_portfolio_pct'])) {
                $out["move:portfolio:{$pf['name']}:$date"] = $item('portfolio', $pf['name'], $pf);
            }
            foreach ($pf['accounts'] as $acc) {
                foreach ($acc['positions'] as $p) {
                    if ($over($p['gain_pct'], $settings['move_position_pct'])) {
                        $out["move:position:{$acc['name']}:{$p['name']}:$date"] = $item('position', $p['name'] . ' · ' . $acc['name'], $p);
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Otevřené loty CP a krypta (bez odměn), které do `$days` dní splní časový test (prodej od data osvobození).
     *
     * @param array<int,list<array<string,mixed>>> $held
     * @param array<int,array<string,mixed>> $positions
     * @return array<string,array<string,mixed>>
     */
    private function holding(array $held, array $positions, \DateTimeImmutable $today, int $days): array
    {
        $out = [];
        $until = $today->modify("+$days days")->format('Y-m-d');
        foreach ($held as $positionId => $lots) {
            $p = $positions[$positionId];
            if (!in_array($p['asset_class'], self::TAXED_CLASSES, true)) {
                continue;
            }
            foreach ($lots as $lot) {
                if ($lot['source_type'] === 'reward') {
                    continue; // drobné denní odměny ze stakingu by souhrn zahltily
                }
                $exemptFrom = (new \DateTimeImmutable(substr((string) $lot['acquired_at'], 0, 10)))->modify('+3 years +1 day')->format('Y-m-d');
                if ($exemptFrom < $today->format('Y-m-d') || $exemptFrom > $until) {
                    continue;
                }
                $key = "holding:$positionId:$exemptFrom";
                $out[$key] ??= ['position' => $p['display_name'], 'account' => $p['institution_name'] . ' · ' . $p['account_name'],
                    'symbol' => $p['instrument_symbol'], 'quantity' => BigDecimal::zero(), 'acquired_at' => substr((string) $lot['acquired_at'], 0, 10),
                    'exempt_from' => $exemptFrom, 'days' => (int) $today->diff(new \DateTimeImmutable($exemptFrom))->format('%a')];
                $out[$key]['quantity'] = $out[$key]['quantity']->plus($lot['remaining_quantity']);
            }
        }
        foreach ($out as &$item) {
            $item['quantity'] = (string) $item['quantity']->strippedOfTrailingZeros();
        }
        unset($item);
        uasort($out, static fn (array $a, array $b) => $a['exempt_from'] <=> $b['exempt_from']);
        return $out;
    }

    /**
     * Třídy mimo toleranci cílové alokace (celek i portfolia). Klíč s měsícem → trvající odchylka nejvýš jednou za měsíc.
     *
     * @return array<string,array<string,mixed>>
     */
    private function allocation(int $userId, string $currency, \DateTimeImmutable $today): array
    {
        $out = [];
        foreach ($this->allocationTargets->scopes($userId) as $portfolioId) {
            $name = $portfolioId !== null ? (string) ($this->portfolios->find($userId, $portfolioId)['name'] ?? '') : '';
            $a = $this->rebalance->compute($userId, $currency, $portfolioId, null, $today);
            foreach ($a['classes'] as $c) {
                if ($c['out_of_band']) {
                    $out['allocation:' . ($portfolioId ?? 'total') . ":{$c['category']}:" . $today->format('Y-m')] = [
                        'scope' => $portfolioId !== null ? 'portfolio' : 'total', 'name' => $name, 'category' => $c['category'],
                        'pct' => $c['pct'], 'target_pct' => $c['target_pct'], 'diff_pct' => $c['diff_pct'], 'diff_amount' => $c['diff_amount'],
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Chybějící / zastaralé ceny držených instrumentů, zastaralé kurzy ČNB, chyby bankovních avíz a IMAP schránek.
     *
     * @param array<int,list<array<string,mixed>>> $held
     * @param array<int,array<string,mixed>> $positions
     * @return array<string,array<string,mixed>>
     */
    private function dataIssues(int $userId, array $held, array $positions, \DateTimeImmutable $today): array
    {
        $out = [];
        $instruments = [];
        $currencies = [];
        foreach (array_keys($held) as $positionId) {
            $p = $positions[$positionId];
            if ($p['valuation_mode'] === 'market' && $p['instrument_id'] !== null) {
                $instruments[(int) $p['instrument_id']] = ['id' => (int) $p['instrument_id'], 'asset_class' => $p['asset_class'],
                    'symbol' => $p['instrument_symbol'], 'name' => $p['instrument_name']];
                $currencies[(string) $p['quote_currency']] = true;
            }
        }
        foreach ($this->prices->lastPrices(array_values($instruments)) as $id => $quote) {
            $i = $instruments[$id];
            if ($quote === null) {
                $out["missing_price:$id"] = ['kind' => 'missing_price', 'symbol' => $i['symbol'], 'name' => $i['name'], 'date' => null];
            } elseif ($quote['stale']) {
                $out["stale_price:$id:{$quote['date']}"] = ['kind' => 'stale_price', 'symbol' => $i['symbol'], 'name' => $i['name'], 'date' => $quote['date']];
            }
        }

        unset($currencies['CZK']);
        $latestFx = $this->fxRates->latestDate();
        if ($currencies !== [] && $latestFx !== null
            && (int) (new \DateTimeImmutable($latestFx))->diff($today)->format('%r%a') > self::FX_MAX_AGE_DAYS) {
            $out["stale_fx:$latestFx"] = ['kind' => 'stale_fx', 'symbol' => implode(', ', array_keys($currencies)), 'name' => null, 'date' => $latestFx];
        }

        $since = $today->modify('-' . self::NOTICE_LOOKBACK_DAYS . ' days')->format('Y-m-d');
        foreach ($this->notices->list($userId, 100) as $n) {
            if (in_array($n['status'], self::NOTICE_PROBLEMS, true) && substr((string) $n['created_at'], 0, 10) >= $since) {
                $out["notice:{$n['id']}"] = ['kind' => 'notice_' . $n['status'], 'symbol' => (string) ($n['parser'] ?? ''),
                    'name' => $n['subject'] ?? $n['error'], 'date' => substr((string) ($n['message_date'] ?? $n['created_at']), 0, 10)];
            }
        }
        foreach ($this->imap->list($userId) as $a) {
            if ($a['enabled'] && $a['last_scan_status'] === 'error') {
                $out["imap:{$a['id']}:{$a['last_scan_at']}"] = ['kind' => 'imap_error', 'symbol' => $a['name'],
                    'name' => $a['last_scan_message'], 'date' => substr((string) $a['last_scan_at'], 0, 10)];
            }
        }
        return $out;
    }

    /**
     * Otevřené loty po pozicích (jen nenulový zbytek).
     *
     * @param array<int,array<string,mixed>> $positions
     * @return array<int,list<array<string,mixed>>>
     */
    private function heldLots(int $userId, array $positions): array
    {
        $out = [];
        foreach ($this->lots->lots($userId) as $lot) {
            if (isset($positions[$lot['position_id']]) && BigDecimal::of((string) $lot['remaining_quantity'])->isPositive()) {
                $out[$lot['position_id']][] = $lot;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $digest
     * @return array<string,mixed>
     */
    private function vars(array $digest, string $locale, int $count): array
    {
        $f = new ReportFormat($locale);
        $cur = $digest['currency'];
        $s = $digest['sections'];
        return [
            'date_f'  => $f->date($digest['date']),
            'price'   => array_map(static fn (array $p) => $p + ['threshold_f' => $f->price($p['threshold'], $p['currency']),
                'current_f' => $f->price($p['current'], $p['currency']), 'date_f' => $f->date($p['date'])], $s['price']),
            'moves'   => array_map(static fn (array $m) => $m + ['gain_f' => $f->money($m['gain'], $cur, true), 'gain_pct_f' => $f->pct($m['gain_pct']),
                'value_f' => $f->money($m['value'], $cur), 'color' => $f->color($m['gain']), 'date_f' => $f->date($m['date'])], $s['moves']),
            'holding' => array_map(static fn (array $h) => $h + ['quantity_f' => $f->quantity($h['quantity']),
                'acquired_f' => $f->date($h['acquired_at']), 'exempt_f' => $f->date($h['exempt_from'])], $s['holding']),
            'allocation' => array_map(static fn (array $a) => $a + ['pct_f' => $f->money($a['pct'], null) . ' %',
                'target_f' => $f->money($a['target_pct'], null) . ' %', 'diff_amount_f' => $f->money($a['diff_amount'], $cur, true)], $s['allocation']),
            'data'    => array_map(static fn (array $d) => $d + ['date_f' => $d['date'] !== null ? $f->date($d['date']) : null], $s['data']),
            'app_url' => rtrim((string) $this->config->get('app.url', ''), '/'),
            'subject' => ($locale === 'cs' ? 'Upozornění MyPortfolio: ' : 'MyPortfolio alerts: ') . $count
                . ($locale === 'cs' ? ($count === 1 ? ' položka' : ($count < 5 ? ' položky' : ' položek')) : ($count === 1 ? ' item' : ' items')),
        ];
    }
}
