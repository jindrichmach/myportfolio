<?php

declare(strict_types=1);

namespace MyPortfolio\Service\DataQuality;

use Brick\Math\BigDecimal;
use MyPortfolio\Repository\BankNoticeRepository;
use MyPortfolio\Repository\FxRateRepository;
use MyPortfolio\Repository\ImapAccountRepository;
use MyPortfolio\Repository\LotRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Service\Price\PriceService;

/**
 * Problémy s daty uživatele: chybějící / zastaralá cena drženého instrumentu, staré kurzy ČNB, neúplná
 * nabývací cena lotu (upozornění FIFO enginu), chyby bankovních avíz a IMAP schránek. Stejné kontroly
 * posílají e-mailová upozornění (AlertService) i stránka Kvalita dat.
 */
final class DataQualityService
{
    private const NOTICE_PROBLEMS = ['unmapped', 'parse_failed', 'security_rejected', 'import_failed'];
    private const FX_MAX_AGE_DAYS = 4;
    private const PAGE_NOTICE_LOOKBACK_DAYS = 30;

    public function __construct(
        private readonly PositionRepository $positions,
        private readonly LotRepository $lots,
        private readonly PriceService $prices,
        private readonly FxRateRepository $fxRates,
        private readonly BankNoticeRepository $notices,
        private readonly ImapAccountRepository $imap,
    ) {}

    /**
     * Stránka Kvalita dat: problémy po oddílech.
     *
     * @return array{prices:list<array<string,mixed>>,fx:list<array<string,mixed>>,costs:list<array<string,mixed>>,notices:list<array<string,mixed>>,total:int}
     */
    public function report(int $userId, \DateTimeImmutable $today): array
    {
        $positions = [];
        foreach ($this->positions->list($userId) as $p) {
            $positions[(int) $p['id']] = $p;
        }
        $held = [];
        foreach ($this->lots->lots($userId) as $lot) {
            if (isset($positions[$lot['position_id']]) && BigDecimal::of((string) $lot['remaining_quantity'])->isPositive()) {
                $held[$lot['position_id']][] = $lot;
            }
        }

        $out = ['prices' => [], 'fx' => [], 'costs' => $this->lots->warnings($userId), 'notices' => []];
        foreach ($this->issues($userId, $held, $positions, $today, self::PAGE_NOTICE_LOOKBACK_DAYS) as $item) {
            $section = match (true) {
                in_array($item['kind'], ['missing_price', 'stale_price'], true) => 'prices',
                $item['kind'] === 'stale_fx' => 'fx',
                default => 'notices',
            };
            $out[$section][] = $item;
        }
        return $out + ['total' => count($out['prices']) + count($out['fx']) + count($out['costs']) + count($out['notices'])];
    }

    /**
     * Problémy jako položky upozornění; klíč = identita do alert_log (stejný problém se neohlásí dvakrát).
     *
     * @param array<int,list<array<string,mixed>>> $held otevřené loty po pozicích
     * @param array<int,array<string,mixed>> $positions
     * @return array<string,array<string,mixed>>
     */
    public function issues(int $userId, array $held, array $positions, \DateTimeImmutable $today, int $noticeLookbackDays): array
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

        $since = $today->modify('-' . $noticeLookbackDays . ' days')->format('Y-m-d');
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
}
