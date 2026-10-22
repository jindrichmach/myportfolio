<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\Import\Draft;

/**
 * Binance — „Transaction History" (Assets → Asset History → Export Transaction Records):
 *   User_ID,UTC_Time,Account,Operation,Coin,Change,Remark   (User_ID někdy chybí)
 * Deník změn zůstatků v UTC (rok i dvoumístný „25-03-20"). Obchod nemá ID — řádky téhož obchodu mají
 * stejný čas a účet (Transaction Buy/Spend/Revenue/Sold/Fee, starší Buy/Sell/Fee/Transaction Related,
 * Binance Convert, Small Assets Exchange BNB …) → sečtou se změny po coinech.
 * Withdraw má poplatek už v Change. Futures/margin se neimportují.
 */
final class BinanceParser extends AbstractParser
{
    private const TRADE = ['transaction buy', 'transaction spend', 'transaction revenue', 'transaction sold', 'transaction related',
        'buy', 'sell', 'binance convert', 'small assets exchange bnb', 'large otc trading', 'stablecoins auto-conversion',
        'eth 2.0 staking', 'liquid swap sell', 'instant order settlement'];
    private const FEE = ['transaction fee', 'fee'];
    private const REWARD_PREFIXES = ['simple earn flexible interest', 'simple earn locked rewards', 'staking rewards', 'eth 2.0 staking rewards',
        'bnb vault rewards', 'pos savings interest', 'savings interest', 'launchpool interest', 'launchpool airdrop', 'hodler airdrops distribution',
        'megadrop rewards', 'airdrop assets', 'distribution', 'token swap - distribution', 'cash voucher distribution', 'mission reward distribution',
        'campaign related reward', 'simple earn flexible airdrop', 'referral commission', 'referral kickback', 'commission fee shared with you',
        'commission rebate', 'commission history', 'referrer rebates', 'swap farming rewards', 'liquid swap rewards', 'dot slot auction rewards',
        'super bnb mining', 'pool distribution', 'savings distribution', 'binance card cashback'];

    public function key(): string
    {
        return 'binance';
    }

    public function label(): string
    {
        return 'Binance';
    }

    public function detect(array $tables): bool
    {
        return $this->table($tables) !== null;
    }

    private function table(array $tables): ?array
    {
        return $this->rows($tables, ['UTC_Time', 'Account', 'Operation', 'Coin', 'Change'])
            ?? $this->rows($tables, ['Time', 'Account', 'Operation', 'Coin', 'Change']);
    }

    public function parse(array $tables): array
    {
        $rows = $this->table($tables) ?? throw new \InvalidArgumentException('Nenalezena hlavička Binance Transaction History.');
        $out = [];
        $groups = [];
        $seen = [];
        foreach ($rows as $row) {
            $op = strtolower($this->col($row, 'Operation'));
            $account = strtolower($this->col($row, 'Account'));
            $time = $this->time($this->col($row, 'UTC_Time', 'Time'));
            if (in_array($op, self::TRADE, true) || (in_array($op, self::FEE, true) && !$this->derivative($account))) {
                $groups[$time . '|' . $account][] = $row;
                continue;
            }
            // Stejné řádky jsou legitimní (dva stejné fill-y) → ID = hash + pořadí výskytu.
            $hash = $this->rowHash($row);
            $id = $hash . ':' . ($seen[$hash] = ($seen[$hash] ?? 0) + 1);
            $out[] = $this->single($row, $op, $account, $time, $id);
        }
        foreach ($groups as $key => $legs) {
            $out = [...$out, ...$this->trade($legs, explode('|', $key)[0])];
        }
        return $out;
    }

    private function single(array $row, string $op, string $account, string $at, string $id): Draft
    {
        $line = $this->line($row);
        $coin = strtoupper($this->col($row, 'Coin'));
        $qty = $this->num($this->col($row, 'Change'));
        $note = $this->col($row, 'Remark') ?: null;
        if ($this->derivative($account) || str_contains($op, 'margin') || str_contains($op, 'profit and loss') || $op === 'funding fee') {
            return Draft::skip($at, 'Futures / margin — neimportuje se', $line, $id, $coin);
        }
        if ($this->isFiat($coin)) {
            return Draft::skip($at, 'Pohyb fiat měny (vklad / výběr)', $line, $id, $coin);
        }
        if (str_starts_with($coin, 'LD') || str_contains($op, 'subscription') || str_contains($op, 'redemption') || str_contains($op, 'purchase')
            || str_starts_with($op, 'transfer') || $op === 'launchpad subscribe') {
            return Draft::skip($at, 'Interní přesun (Earn, peněženky)', $line, $id, $coin);
        }
        foreach (self::REWARD_PREFIXES as $p) {
            if ($op === $p) {
                return new Draft('reward', $at, $coin, quantity: $qty, externalId: $id, note: $this->col($row, 'Operation'), line: $line);
            }
        }
        return match ($op) {
            'deposit'  => new Draft('receive', $at, $coin, quantity: $qty, externalId: $id, note: $note, line: $line),
            'withdraw' => new Draft('send', $at, $coin, quantity: $qty, externalId: $id, note: $note, line: $line),
            default    => Draft::skip($at, "Nepodporovaná operace „{$this->col($row, 'Operation')}\" — zadej ručně", $line, $id, $coin),
        };
    }

    /** @param list<array<string,string>> $legs @return list<Draft> */
    private function trade(array $legs, string $at): array
    {
        $net = [];
        $fees = [];
        $ids = [];
        foreach ($legs as $leg) {
            $coin = strtoupper($this->col($leg, 'Coin'));
            $change = BigDecimal::of($this->num($this->col($leg, 'Change'), false) ?? '0');
            if (in_array(strtolower($this->col($leg, 'Operation')), self::FEE, true)) {
                $fees[$coin] = ($fees[$coin] ?? BigDecimal::zero())->plus($change->abs());
            } else {
                $net[$coin] = ($net[$coin] ?? BigDecimal::zero())->plus($change);
            }
            $ids[] = $this->rowHash($leg);
        }
        sort($ids);
        $id = 'g' . substr(hash('sha256', implode(',', $ids)), 0, 31);
        $op = $this->col($legs[0], 'Operation');
        return $this->tradeFromNet($net, $fees, $at, $id, min(array_map([$this, 'line'], $legs)), $op);
    }

    private function derivative(string $account): bool
    {
        return str_contains($account, 'futures') || str_contains($account, 'margin');
    }

    /** „25-03-20 10:00:00" → 2025-03-20 (dvoumístný rok), čas v UTC. */
    private function time(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{2}-\d{2}-\d{2} /', $value) === 1) {
            $value = '20' . $value;
        }
        return $this->dateTime($value);
    }
}
