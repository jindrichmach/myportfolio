<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Portfolio;

use Brick\Math\BigDecimal;

/**
 * Držené množství pozice v čase — přehraje transakce chronologicky.
 *
 *   market: buy / reward / transfer_in přičítají quantity, sell / transfer_out odečítají,
 *           split násobí stav poměrem
 *   manual: deposit přičítá amount, withdrawal odečítá (= čisté vklady)
 *
 * Poplatek v měně samotného instrumentu (fee_currency = symbol, např. 0.00005 BTC)
 * množství snižuje — stejně jako v LotEngine.
 *
 * Ostatní typy (dividend, interest, fee) množství nemění. Při shodném čase se
 * přírůstky započítají před úbytky, aby nákup a prodej ve stejné minutě nevyhodil
 * falešné „nedostatečné množství".
 */
final class HoldingCalculator
{
    private const INFLOW  = ['buy', 'reward', 'transfer_in', 'deposit'];
    private const OUTFLOW = ['sell', 'transfer_out', 'withdrawal'];

    /**
     * @param list<array{id?:int|string|null,type:string,executed_at:string,quantity?:?string,amount?:?string,split_ratio?:?string,fee?:?string,fee_currency?:?string}> $transactions
     */
    public function evaluate(array $transactions, string $valuationMode, ?string $instrumentSymbol = null): HoldingResult
    {
        usort($transactions, static function (array $a, array $b): int {
            return [$a['executed_at'], self::order($a['type']), (int) ($a['id'] ?? PHP_INT_MAX)]
               <=> [$b['executed_at'], self::order($b['type']), (int) ($b['id'] ?? PHP_INT_MAX)];
        });

        $holding = BigDecimal::zero();
        $firstNegative = null;
        foreach ($transactions as $tx) {
            $type = $tx['type'];
            if ($valuationMode === 'market') {
                $qty = BigDecimal::of($tx['quantity'] ?? '0');
                if (in_array($type, ['buy', 'reward', 'transfer_in'], true)) {
                    $holding = $holding->plus($qty);
                } elseif (in_array($type, ['sell', 'transfer_out'], true)) {
                    $holding = $holding->minus($qty);
                } elseif ($type === 'split' && ($tx['split_ratio'] ?? null) !== null) {
                    $holding = $holding->multipliedBy($tx['split_ratio']);
                }
                if ($instrumentSymbol !== null && ($tx['fee'] ?? null) !== null && $type !== 'split'
                    && strtoupper((string) ($tx['fee_currency'] ?? '')) === strtoupper($instrumentSymbol)
                ) {
                    $holding = $holding->minus($tx['fee']);
                }
            } else {
                $amount = BigDecimal::of($tx['amount'] ?? '0');
                if ($type === 'deposit') {
                    $holding = $holding->plus($amount);
                } elseif ($type === 'withdrawal') {
                    $holding = $holding->minus($amount);
                }
            }
            if ($firstNegative === null && $holding->isNegative()) {
                $firstNegative = $tx;
            }
        }

        return new HoldingResult($holding, $firstNegative);
    }

    private static function order(string $type): int
    {
        return match (true) {
            in_array($type, self::INFLOW, true)  => 0,
            in_array($type, self::OUTFLOW, true) => 2,
            default                              => 1,
        };
    }
}
