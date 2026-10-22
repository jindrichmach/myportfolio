<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Portfolio;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Support\Decimal;

/**
 * FIFO loty jednoho instrumentu napříč pozicemi uživatele — čistý výpočet bez DB.
 *
 * Vstupem jsou transakce instrumentu (všech pozic uživatele), výstupem loty, úbytky
 * a upozornění. Částky zůstávají v původní měně obchodu; přepočet poplatku v jiné
 * fiat měně dodá `$convert(amount, from, to, date): ?BigDecimal`.
 *
 *   buy / reward   → nový lot (poplatek ve fiat se přičte k nákupní ceně, poplatek
 *                    v měně instrumentu sníží množství lotu)
 *   sell           → úbytky z nejstarších lotů pozice (kind=sell); poplatek v měně
 *                    instrumentu spotřebuje další kusy (kind=fee, výnos 0)
 *   transfer_out   → spotřebuje kusy (+ poplatek v měně instrumentu) a na cílové pozici
 *   + transfer_in    vytvoří loty se stejným datem nabytí a celou nákupní cenou
 *   split          → zbývající množství otevřených lotů pozice × poměr
 */
final class LotEngine
{
    private const SCALE_QTY  = Decimal::SCALE_QUANTITY;
    private const SCALE_COST = Decimal::SCALE_PRICE;

    /** @var list<array<string,mixed>> */
    private array $lots = [];
    /** @var list<array<string,mixed>> */
    private array $disposals = [];
    /** @var list<string> */
    private array $warnings = [];
    private string $symbol = '';

    /**
     * @param list<array<string,mixed>> $transactions řádky transactions (id, position_id, type, executed_at,
     *        quantity, amount, unit_price, price_currency, fee, fee_currency, split_ratio, transfer_group_id)
     * @param callable(BigDecimal,string,string,string):?BigDecimal $convert
     * @param callable(array<string,mixed>):?array{0:BigDecimal,1:string} $rewardValue hodnota odměny bez `amount`
     * @return array{lots:list<array<string,mixed>>,disposals:list<array<string,mixed>>,warnings:list<string>}
     */
    public function run(array $transactions, string $instrumentSymbol, callable $convert, callable $rewardValue): array
    {
        $this->lots = $this->disposals = $this->warnings = [];
        $this->symbol = strtoupper($instrumentSymbol);

        $byGroup = [];
        foreach ($transactions as $tx) {
            if ($tx['transfer_group_id'] !== null) {
                $byGroup[$tx['transfer_group_id']][$tx['type']] = $tx;
            }
        }
        usort($transactions, static fn (array $a, array $b) =>
            [$a['executed_at'], self::order($a['type']), (int) $a['id']] <=> [$b['executed_at'], self::order($b['type']), (int) $b['id']]);

        foreach ($transactions as $tx) {
            $feeQty = $this->instrumentFee($tx, $this->symbol);
            match ($tx['type']) {
                'buy', 'reward' => $this->acquire($tx, $feeQty, $convert, $rewardValue),
                'sell'          => $this->sell($tx, $feeQty, $convert),
                'transfer_out'  => $this->transfer($tx, $byGroup[$tx['transfer_group_id']]['transfer_in'] ?? null, $feeQty),
                'transfer_in'   => isset($byGroup[$tx['transfer_group_id']]['transfer_out'])
                    ? null // zpracováno společně s odchodem
                    : $this->orphanTransferIn($tx),
                'split'         => $this->split($tx),
                default         => null,
            };
        }

        return ['lots' => $this->lots, 'disposals' => $this->disposals, 'warnings' => $this->warnings];
    }

    /** @param array<string,mixed> $tx */
    private function acquire(array $tx, BigDecimal $feeQty, callable $convert, callable $rewardValue): void
    {
        $qty = BigDecimal::of((string) $tx['quantity']);
        $currency = (string) ($tx['price_currency'] ?? '');
        if ($tx['amount'] !== null) {
            $cost = BigDecimal::of((string) $tx['amount']);
        } elseif ($tx['type'] === 'reward' && ($value = $rewardValue($tx)) !== null) {
            [$cost, $currency] = $value;
        } else {
            $cost = BigDecimal::zero();
            if ($tx['type'] === 'reward') {
                $this->warnings[] = "reward_without_value:{$tx['id']}";
            }
        }

        $fiatFee = $this->fiatFee($tx, $currency, $convert);
        if ($fiatFee !== null) {
            $cost = $cost->plus($fiatFee);
        }
        $lotQty = $qty->minus($feeQty);
        if (!$lotQty->isPositive()) {
            $this->warnings[] = "fee_exceeds_quantity:{$tx['id']}";
            return;
        }
        $this->lots[] = [
            'key'                   => count($this->lots),
            'position_id'           => (int) $tx['position_id'],
            'source_transaction_id' => (int) $tx['id'],
            'origin_key'            => null,
            'acquired_at'           => (string) $tx['executed_at'],
            'quantity'              => $lotQty,
            'remaining'             => $lotQty,
            'cost'                  => $cost->toScale(self::SCALE_COST, RoundingMode::HalfUp),
            'remaining_cost'        => $cost->toScale(self::SCALE_COST, RoundingMode::HalfUp),
            'cost_currency'         => $currency,
        ];
    }

    /** @param array<string,mixed> $tx */
    private function sell(array $tx, BigDecimal $feeQty, callable $convert): void
    {
        $qty = BigDecimal::of((string) $tx['quantity']);
        $currency = (string) $tx['price_currency'];
        $proceeds = BigDecimal::of((string) ($tx['amount'] ?? '0'));
        $fiatFee = $this->fiatFee($tx, $currency, $convert);
        if ($fiatFee !== null) {
            $proceeds = $proceeds->minus($fiatFee);
        }

        $allocated = BigDecimal::zero();
        $chunks = $this->consume((int) $tx['position_id'], $qty, $tx);
        foreach ($chunks as $i => $chunk) {
            // Výnos rozdělený podle množství; poslední kus dostane zbytek (žádný zaokrouhlovací drift).
            $share = $i === array_key_last($chunks)
                ? $proceeds->minus($allocated)
                : $proceeds->multipliedBy($chunk['qty'])->dividedBy($qty, self::SCALE_COST, RoundingMode::HalfUp);
            $allocated = $allocated->plus($share);
            $this->disposals[] = $this->disposal($chunk, $tx, 'sell', $share, $currency);
        }
        foreach ($this->consume((int) $tx['position_id'], $feeQty, $tx) as $chunk) {
            $this->disposals[] = $this->disposal($chunk, $tx, 'fee', BigDecimal::zero(), $currency);
        }
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed>|null $in
     */
    private function transfer(array $out, ?array $in, BigDecimal $feeQty): void
    {
        $qty = BigDecimal::of((string) $out['quantity']);
        $total = $qty->plus($feeQty);
        $chunks = $this->consume((int) $out['position_id'], $total, $out);
        $moved = BigDecimal::zero();
        foreach ($chunks as $i => $chunk) {
            if ($in === null) {
                continue;
            }
            // Cílový lot dostane podíl kusů bez poplatku, ale celou nákupní cenu spotřebovaných kusů;
            // poslední kus dorovná zbytek, aby součet přesně odpovídal převedenému množství.
            $movedQty = $i === array_key_last($chunks)
                ? $qty->minus($moved)
                : $chunk['qty']->multipliedBy($qty)->dividedBy($total, self::SCALE_QTY, RoundingMode::Down);
            $moved = $moved->plus($movedQty);
            $source = $this->lots[$chunk['key']];
            $this->lots[] = [
                'key'                   => count($this->lots),
                'position_id'           => (int) $in['position_id'],
                'source_transaction_id' => $source['source_transaction_id'],
                'origin_key'            => $chunk['key'],
                'acquired_at'           => $source['acquired_at'],
                'quantity'              => $movedQty,
                'remaining'             => $movedQty,
                'cost'                  => $chunk['cost'],
                'remaining_cost'        => $chunk['cost'],
                'cost_currency'         => $source['cost_currency'],
            ];
        }
        if ($in === null) {
            $this->warnings[] = "transfer_without_pair:{$out['id']}";
        }
    }

    /** @param array<string,mixed> $in */
    private function orphanTransferIn(array $in): void
    {
        $this->warnings[] = "transfer_without_pair:{$in['id']}";
        $qty = BigDecimal::of((string) $in['quantity']);
        $this->lots[] = [
            'key' => count($this->lots), 'position_id' => (int) $in['position_id'], 'source_transaction_id' => (int) $in['id'],
            'origin_key' => null, 'acquired_at' => (string) $in['executed_at'], 'quantity' => $qty, 'remaining' => $qty,
            'cost' => BigDecimal::zero(), 'remaining_cost' => BigDecimal::zero(), 'cost_currency' => (string) ($in['price_currency'] ?? 'CZK'),
        ];
    }

    /** @param array<string,mixed> $tx */
    private function split(array $tx): void
    {
        $ratio = BigDecimal::of((string) $tx['split_ratio']);
        foreach ($this->lots as $i => $lot) {
            if ($lot['position_id'] === (int) $tx['position_id'] && $lot['remaining']->isPositive()
                && $lot['acquired_at'] <= $tx['executed_at']
            ) {
                $this->lots[$i]['quantity'] = $lot['quantity']->multipliedBy($ratio)->toScale(self::SCALE_QTY, RoundingMode::Down);
                $this->lots[$i]['remaining'] = $lot['remaining']->multipliedBy($ratio)->toScale(self::SCALE_QTY, RoundingMode::Down);
            }
        }
    }

    /**
     * Odebere množství z nejstarších otevřených lotů pozice — podle data nabytí, ne podle pořadí,
     * v jakém lot na pozici přišel (převedený starší lot má přednost před novějším nákupem).
     *
     * @param array<string,mixed> $tx
     * @return list<array{key:int,qty:BigDecimal,cost:BigDecimal}>
     */
    private function consume(int $positionId, BigDecimal $qty, array $tx): array
    {
        $chunks = [];
        $left = $qty;
        $order = array_keys($this->lots);
        usort($order, fn (int $a, int $b) => [$this->lots[$a]['acquired_at'], $a] <=> [$this->lots[$b]['acquired_at'], $b]);
        foreach ($order as $i) {
            $lot = $this->lots[$i];
            if (!$left->isPositive()) {
                break;
            }
            if ($lot['position_id'] !== $positionId || !$lot['remaining']->isPositive()) {
                continue;
            }
            $take = $lot['remaining']->isLessThanOrEqualTo($left) ? $lot['remaining'] : $left;
            $cost = $take->isEqualTo($lot['remaining'])
                ? $lot['remaining_cost']
                : $lot['remaining_cost']->multipliedBy($take)->dividedBy($lot['remaining'], self::SCALE_COST, RoundingMode::HalfUp);
            $this->lots[$i]['remaining'] = $lot['remaining']->minus($take);
            $this->lots[$i]['remaining_cost'] = $lot['remaining_cost']->minus($cost);
            $chunks[] = ['key' => $i, 'qty' => $take, 'cost' => $cost];
            $left = $left->minus($take);
        }
        if ($left->isPositive()) {
            $this->warnings[] = "insufficient_quantity:{$tx['id']}";
        }
        return $chunks;
    }

    /**
     * @param array{key:int,qty:BigDecimal,cost:BigDecimal} $chunk
     * @param array<string,mixed> $tx
     * @return array<string,mixed>
     */
    private function disposal(array $chunk, array $tx, string $kind, BigDecimal $proceeds, string $proceedsCurrency): array
    {
        return [
            'lot_key'           => $chunk['key'],
            'transaction_id'    => (int) $tx['id'],
            'kind'              => $kind,
            'disposed_at'       => (string) $tx['executed_at'],
            'quantity'          => $chunk['qty'],
            'cost'              => $chunk['cost'],
            'cost_currency'     => $this->lots[$chunk['key']]['cost_currency'],
            'proceeds'          => $proceeds->toScale(self::SCALE_COST, RoundingMode::HalfUp),
            'proceeds_currency' => $proceedsCurrency,
        ];
    }

    /** Poplatek v měně samotného instrumentu (např. 0.00005 BTC) — snižuje množství. */
    private function instrumentFee(array $tx, string $symbol): BigDecimal
    {
        if ($tx['fee'] === null || strtoupper((string) $tx['fee_currency']) !== $symbol) {
            return BigDecimal::zero();
        }
        return BigDecimal::of((string) $tx['fee']);
    }

    /**
     * Poplatek ve fiat měně přepočtený do měny obchodu; poplatek v jiném kryptu → null + upozornění.
     *
     * @param array<string,mixed> $tx
     */
    private function fiatFee(array $tx, string $currency, callable $convert): ?BigDecimal
    {
        if ($tx['fee'] === null) {
            return null;
        }
        $fee = BigDecimal::of((string) $tx['fee']);
        $feeCurrency = strtoupper((string) ($tx['fee_currency'] ?? $currency));
        if ($feeCurrency === $this->symbol) {
            return null; // v měně instrumentu — řeší instrumentFee()
        }
        if ($feeCurrency === $currency || $fee->isZero()) {
            return $fee;
        }
        $converted = $convert($fee, $feeCurrency, $currency, substr((string) $tx['executed_at'], 0, 10));
        if ($converted === null) {
            $this->warnings[] = "fee_not_converted:{$tx['id']}";
        }
        return $converted;
    }

    private static function order(string $type): int
    {
        return match ($type) {
            'buy', 'reward', 'transfer_in' => 0,
            'split'                        => 1,
            'transfer_out'                 => 2,
            default                        => 3,
        };
    }
}
