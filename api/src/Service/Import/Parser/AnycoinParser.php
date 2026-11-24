<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\Import\Draft;

/**
 * Anycoin (anycoin.cz) — Transakce → Všechny měny → Export (CSV). Deník pohybů zůstatků:
 *   Date,Type,Amount,Currency,Order ID[,anycoin TX ID[,Description]]
 * Obchod = řádky se stejným Order ID: `trade payment` (−, co jsem zaplatil), `trade fill` (+, co jsem dostal),
 * případně `trade refund` (+, vrácení části platby). Poplatek je v kurzu. Stakované zůstatky mají příponu `.S`.
 *
 * Druhý formát — Objednávky (anycoin.cz/cs/orders) → Export: jeden řádek = jedna objednávka, čas v místní zóně:
 *   UID,DATE,SYMBOL,ACTION,QUANTY,PRICE,FEE,FEE_CURRENCY,REBATE,REBATE_CURRENCY,ADDRESS_FROM,ADDRESS_TO
 * UID odpovídá Order ID deníku, takže se oba exporty navzájem neduplikují.
 */
final class AnycoinParser extends AbstractParser
{
    private const ALIASES = ['XDG' => 'DOGE', 'XBT' => 'BTC'];
    private const LEDGER = ['Date', 'Type', 'Amount', 'Currency', 'Order ID'];
    private const ORDERS = ['UID', 'DATE', 'SYMBOL', 'ACTION', 'PRICE'];

    public function key(): string
    {
        return 'anycoin';
    }

    public function label(): string
    {
        return 'Anycoin';
    }

    public function detect(array $tables): bool
    {
        return $this->hasHeader($tables, self::LEDGER) || $this->hasHeader($tables, self::ORDERS);
    }

    public function parse(array $tables): array
    {
        $orderRows = $this->rows($tables, self::ORDERS);
        if ($orderRows !== null) {
            return array_map(fn (array $row) => $this->order($row), $orderRows);
        }
        $rows = $this->rows($tables, self::LEDGER)
            ?? throw new \InvalidArgumentException('Nenalezena hlavička Anycoin exportu.');
        $out = [];
        $orders = [];
        foreach ($rows as $row) {
            $type = strtolower($this->col($row, 'Type'));
            $orderId = $this->col($row, 'Order ID');
            if (str_starts_with($type, 'trade') && $orderId !== '') {
                $orders[$orderId][] = $row;
                continue;
            }
            $out[] = $this->movement($row, $type);
        }
        foreach ($orders as $orderId => $legs) {
            $out[] = $this->trade((string) $orderId, $legs);
        }
        return $out;
    }

    private function movement(array $row, string $type): Draft
    {
        $at = $this->dateTime($this->col($row, 'Date'));
        $line = $this->line($row);
        $id = $this->col($row, 'anycoin TX ID', 'dase TX ID') ?: $this->rowHash($row);
        $symbol = $this->symbol($this->col($row, 'Currency'));
        $qty = $this->num($this->col($row, 'Amount'));
        return match (true) {
            $type === 'stake_reward'                        => new Draft('reward', $at, $symbol, quantity: $qty, externalId: $id, note: 'Staking', line: $line),
            $this->isFiat($symbol)                          => Draft::skip($at, 'Pohyb fiat měny (vklad / výběr)', $line, $id, $symbol),
            $type === 'deposit'                             => new Draft('receive', $at, $symbol, quantity: $qty, externalId: $id, line: $line),
            $type === 'withdrawal'                          => new Draft('send', $at, $symbol, quantity: $qty, externalId: $id, line: $line),
            in_array($type, ['stake', 'unstake'], true)     => Draft::skip($at, 'Přesun do / ze stakingu (interní)', $line, $id, $symbol),
            str_contains($type, 'block')                    => Draft::skip($at, 'Dočasná blokace zůstatku', $line, $id, $symbol),
            default                                         => Draft::skip($at, "Nepodporovaný typ „{$type}\" — zadej ručně", $line, $id, $symbol),
        };
    }

    /** @param list<array<string,string>> $legs */
    private function trade(string $orderId, array $legs): Draft
    {
        $net = [];
        $at = '';
        $line = 0;
        foreach ($legs as $leg) {
            $code = $this->symbol($this->col($leg, 'Currency'));
            $net[$code] = ($net[$code] ?? BigDecimal::zero())->plus(BigDecimal::of($this->num($this->col($leg, 'Amount'), false) ?? '0'));
            $at = max($at, $this->dateTime($this->col($leg, 'Date')));
            $line = $line === 0 ? $this->line($leg) : min($line, $this->line($leg));
        }
        $net = array_filter($net, static fn (BigDecimal $v) => !$v->isZero());
        $fiat = array_filter($net, fn ($v, $c) => $this->isFiat($c), ARRAY_FILTER_USE_BOTH);
        $crypto = array_diff_key($net, $fiat);
        if (count($fiat) !== 1 || count($crypto) !== 1) {
            return Draft::skip($at, 'Obchod krypto za krypto nebo neúplná objednávka — zadej ručně', $line, 'order:' . $orderId);
        }
        $fiatCode = (string) array_key_first($fiat);
        $symbol = (string) array_key_first($crypto);
        $buy = $crypto[$symbol]->isPositive();
        return new Draft($buy ? 'buy' : 'sell', $at, $symbol, quantity: (string) $crypto[$symbol]->abs(),
            amount: (string) $fiat[$fiatCode]->abs(), currency: $fiatCode, externalId: 'order:' . $orderId,
            note: 'Objednávka ' . $orderId, line: $line);
    }

    /** Řádek exportu objednávek. @param array<string,string> $row */
    private function order(array $row): Draft
    {
        $at = $this->dateTime($this->col($row, 'DATE'), 'Europe/Prague');
        $line = $this->line($row);
        $uid = $this->col($row, 'UID');
        $id = $uid !== '' ? 'order:' . $uid : $this->rowHash($row);
        [$base, $quote] = array_pad(explode('/', $this->col($row, 'SYMBOL'), 2), 2, '');
        $symbol = $this->symbol($base);
        $quote = $this->symbol($quote);
        $action = strtolower($this->col($row, 'ACTION'));
        if (!in_array($action, ['buy', 'sell'], true)) {
            return Draft::skip($at, "Nepodporovaný typ „{$action}\" — zadej ručně", $line, $id, $symbol);
        }
        if (!$this->isFiat($quote)) {
            return Draft::skip($at, 'Obchod krypto za krypto — zadej ručně', $line, $id, $symbol);
        }
        $qty = $this->num($this->col($row, 'QUANTY', 'QUANTITY'), decimal: '.');
        $price = $this->num($this->col($row, 'PRICE'), decimal: '.');
        if ($qty === null || $price === null) {
            return Draft::skip($at, 'Chybí množství nebo cena', $line, $id, $symbol);
        }
        $fee = $this->num($this->col($row, 'FEE'), decimal: '.');
        $feeCurrency = $fee !== null ? ($this->symbol($this->col($row, 'FEE_CURRENCY')) ?: $quote) : null;
        return new Draft($action, $at, $symbol, quantity: $qty, unitPrice: $price,
            amount: (string) BigDecimal::of($qty)->multipliedBy($price)->strippedOfTrailingZeros(), currency: $quote,
            fee: $fee, feeCurrency: $feeCurrency, externalId: $id, note: 'Objednávka ' . $uid, line: $line);
    }

    private function symbol(string $code): string
    {
        $code = strtoupper(preg_replace('/\.S$/i', '', trim($code)) ?? '');
        return self::ALIASES[$code] ?? $code;
    }
}
