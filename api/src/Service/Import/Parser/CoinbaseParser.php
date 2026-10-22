<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\Import\Draft;

/**
 * Coinbase — „Transaction history" CSV (Profil → Reports / Statements → Generate report → CSV).
 * Varianty: v4 (2024+, sloupec ID, „Price Currency"), v3/v2 („Spot Price Currency"), v1 (měna v názvu sloupce).
 * Nad hlavičkou bývá preambule. Časy v UTC, peněžní sloupce s symbolem měny („€81000.00", "$2,116.18").
 * Znaménka množství nejsou konzistentní → absolutní hodnota, směr podle typu.
 * Convert a Advanced Trade s kryptoměnou jako kotací mají druhou stranu jen v Notes.
 */
final class CoinbaseParser extends AbstractParser
{
    private const BUY = ['buy', 'advanced trade buy', 'advance trade buy', 'credit', 'retail mgx dex buy'];
    private const SELL = ['sell', 'advanced trade sell', 'advance trade sell', 'retail simple dust', 'card spend', 'subscription', 'donation', 'admin debit'];
    private const REWARD = ['staking income', 'reward income', 'rewards income', 'inflation reward', 'learning reward', 'coinbase earn',
        'interest payout', 'subscription rebate', 'subscription rebates (24 hours)'];
    private const INTERNAL = ['retail staking transfer', 'retail unstaking transfer', 'vault withdrawal', 'cash to savings', 'savings to cash',
        'transfer', 'exchange deposit', 'pro deposit', 'prime deposit', 'exchange withdrawal', 'pro withdrawal'];
    private const AMOUNT = '([\d,]+(?:\.\d+)?)';

    public function key(): string
    {
        return 'coinbase';
    }

    public function label(): string
    {
        return 'Coinbase';
    }

    public function detect(array $tables): bool
    {
        return $this->hasHeader($tables, ['Timestamp', 'Transaction Type', 'Asset', 'Quantity Transacted']);
    }

    public function parse(array $tables): array
    {
        $rows = $this->rows($tables, ['Timestamp', 'Transaction Type', 'Asset', 'Quantity Transacted'])
            ?? throw new \InvalidArgumentException('Nenalezena hlavička Coinbase exportu.');
        $out = [];
        foreach ($rows as $row) {
            foreach ($this->row($row) as $draft) {
                $out[] = $draft;
            }
        }
        return $out;
    }

    /** @return list<Draft> */
    private function row(array $row): array
    {
        $line = $this->line($row);
        $at = $this->dateTime($this->col($row, 'Timestamp'));
        $type = strtolower($this->col($row, 'Transaction Type'));
        $asset = strtoupper($this->col($row, 'Asset'));
        $id = $this->col($row, 'ID') ?: $this->rowHash($row);
        $qty = $this->num($this->col($row, 'Quantity Transacted'));
        $currency = $this->currency($row);
        $subtotal = $this->num($this->money($row, 'Subtotal'), true, '.');
        $total = $this->num($this->money($row, 'Total (inclusive of fees and/or spread)', 'Total (inclusive of fees)'), true, '.');
        $fee = $this->num($this->money($row, 'Fees and/or Spread', 'Fees'), true, '.');
        $fee = $fee !== null && BigDecimal::of($fee)->isPositive() ? $fee : null;
        $notes = $this->col($row, 'Notes');

        if ($this->isFiat($asset)) {
            return [Draft::skip($at, 'Pohyb fiat měny (vklad / výběr)', $line, $id, $asset)];
        }
        if (in_array($type, self::INTERNAL, true)) {
            return [Draft::skip($at, 'Interní přesun v rámci Coinbase', $line, $id, $asset)];
        }
        $base = static fn (string $kind, array $extra = []) => new Draft(...['kind' => $kind, 'executedAt' => $at, 'symbol' => $asset,
            'quantity' => $qty, 'currency' => $currency, 'externalId' => $id, 'note' => $notes !== '' ? $notes : null, 'line' => $line] + $extra);

        if ($type === 'convert') {
            if (preg_match('/^Converted ' . self::AMOUNT . ' (\w+) to ' . self::AMOUNT . ' (\w+)/', $notes, $m) !== 1) {
                return [Draft::skip($at, 'Convert bez rozpoznatelné poznámky — zadej ručně', $line, $id, $asset)];
            }
            $value = $subtotal ?? $total;
            return [
                $base('sell', ['amount' => $value, 'fee' => $fee, 'feeCurrency' => $currency]),
                new Draft('buy', $at, strtoupper($m[4]), quantity: $this->num($m[3], true, '.'), amount: $value, currency: $currency,
                    externalId: $id . ':to', note: $notes, line: $line),
            ];
        }
        if (in_array($type, self::BUY, true) || in_array($type, self::SELL, true)) {
            $buy = in_array($type, self::BUY, true);
            $drafts = [$base($buy ? 'buy' : 'sell', ['amount' => $subtotal ?? $total, 'unitPrice' => $subtotal === null && $total === null
                ? $this->num($this->money($row, 'Price at Transaction', 'Spot Price at Transaction'), true, '.') : null,
                'fee' => $fee, 'feeCurrency' => $fee !== null ? $currency : null])];
            // Advanced Trade s kryptoměnou jako kotací („… for 1290 USDC on SOL-USDC") — druhá strana obchodu.
            if (preg_match('/ for [£€$]?' . self::AMOUNT . ' (\w+) on \w+-(\w+)/', $notes, $m) === 1 && !$this->isFiat($m[3])) {
                $drafts[] = new Draft($buy ? 'sell' : 'buy', $at, strtoupper($m[3]), quantity: $this->num($m[1], true, '.'),
                    amount: $subtotal ?? $total, currency: $currency, externalId: $id . ':quote', note: $notes, line: $line);
            }
            return $drafts;
        }
        if (in_array($type, self::REWARD, true)) {
            return [$base('reward', ['amount' => $subtotal ?? $total])];
        }
        if ($type === 'receive') {
            return preg_match('/coinbase (earn|rewards|referral)/i', $notes) === 1
                ? [$base('reward', ['amount' => $subtotal ?? $total])]
                : [$base('receive')];
        }
        if (in_array($type, ['send', 'retail mgx dex send'], true)) {
            return [$base('send')];
        }
        return [Draft::skip($at, "Nepodporovaný typ „{$this->col($row, 'Transaction Type')}\" — zadej ručně", $line, $id, $asset)];
    }

    /** Měna peněžních sloupců: sloupec Price/Spot Price Currency, ve v1 prefix názvu sloupce. */
    private function currency(array $row): string
    {
        $c = strtoupper($this->col($row, 'Price Currency', 'Spot Price Currency'));
        if ($c !== '') {
            return $c;
        }
        foreach (array_keys($row) as $k) {
            if (preg_match('/^([A-Z]{3}) Spot Price at Transaction$/', (string) $k, $m) === 1) {
                return $m[1];
            }
        }
        return 'USD';
    }

    /** Hodnota peněžního sloupce včetně v1 varianty s prefixem měny („EUR Subtotal"). */
    private function money(array $row, string ...$names): string
    {
        $v = $this->col($row, ...$names);
        if ($v !== '') {
            return $v;
        }
        foreach ($names as $name) {
            foreach ($row as $k => $val) {
                if (preg_match('/^[A-Z]{3} ' . preg_quote($name, '/') . '$/', (string) $k) === 1) {
                    return trim((string) $val);
                }
            }
        }
        return '';
    }
}
