<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Summary;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Service\Fx\FxService;

/**
 * Dividendy, úroky a poplatky v měně zobrazení (kurz ČNB ke dni platby) — pro přehled příjmů
 * a měsíční / roční souhrny.
 *
 *   dividend / interest → hrubá částka, sražená daň = poplatek u platby, čistá = hrubá − daň
 *   fee                 → poplatek (transakce typu poplatek i poplatky u obchodů ve fiat měně)
 * Poplatek v měně instrumentu (např. síťový poplatek v BTC) se nepočítá — snižuje množství, ne peníze.
 */
final class IncomeService
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly FxService $fx,
    ) {}

    /**
     * @return array{rows:list<array<string,mixed>>,missing_fx:list<string>}
     */
    public function rows(int $userId, string $currency): array
    {
        $rows = [];
        $missing = [];
        foreach ($this->transactions->incomeAndFees($userId) as $t) {
            $date = substr((string) $t['executed_at'], 0, 10);
            $base = [
                'date' => $date, 'transaction_id' => (int) $t['id'], 'position_id' => (int) $t['position_id'],
                'portfolio_id' => (int) $t['portfolio_id'], 'account_id' => (int) $t['account_id'],
                'position' => $t['position_name'] ?? $t['instrument_name'] ?? $t['symbol'] ?? '', 'symbol' => $t['symbol'], 'isin' => $t['isin'],
                'account' => $t['institution_name'] . ' · ' . $t['account_name'],
            ];
            try {
                if (in_array($t['type'], ['dividend', 'interest'], true) && $t['amount'] !== null) {
                    $gross = $this->convert((string) $t['amount'], (string) $t['price_currency'], $currency, $date);
                    $withheld = $this->fiatFee($t) ? $this->convert((string) $t['fee'], (string) $t['fee_currency'], $currency, $date) : BigDecimal::zero();
                    $rows[] = $base + ['type' => $t['type'], 'gross' => $gross, 'withheld' => $withheld, 'net' => $gross->minus($withheld),
                        'amount' => (string) $t['amount'], 'amount_currency' => $t['price_currency']];
                } elseif ($t['type'] === 'fee' && $t['amount'] !== null) {
                    $fee = $this->convert((string) $t['amount'], (string) $t['price_currency'], $currency, $date);
                    $rows[] = $base + ['type' => 'fee', 'gross' => $fee, 'withheld' => BigDecimal::zero(), 'net' => $fee,
                        'amount' => (string) $t['amount'], 'amount_currency' => $t['price_currency']];
                } elseif ($this->fiatFee($t) && !in_array($t['type'], ['dividend', 'interest'], true)) {
                    $fee = $this->convert((string) $t['fee'], (string) $t['fee_currency'], $currency, $date);
                    $rows[] = $base + ['type' => 'fee', 'gross' => $fee, 'withheld' => BigDecimal::zero(), 'net' => $fee,
                        'amount' => (string) $t['fee'], 'amount_currency' => $t['fee_currency']];
                }
            } catch (FxRateMissing $e) {
                $missing[] = $e->currency . ' ' . $e->date;
            }
        }
        return ['rows' => $rows, 'missing_fx' => array_values(array_unique($missing))];
    }

    /** Poplatek v penězích (ne v kusech instrumentu). @param array<string,mixed> $t */
    private function fiatFee(array $t): bool
    {
        return $t['fee'] !== null && $t['fee_currency'] !== null && $t['fee_currency'] !== $t['symbol']
            && !BigDecimal::of((string) $t['fee'])->isZero();
    }

    private function convert(string $amount, string $from, string $to, string $date): BigDecimal
    {
        return $this->fx->convert(BigDecimal::of($amount), $from, $to, $date)->toScale(4, RoundingMode::HalfUp);
    }
}
