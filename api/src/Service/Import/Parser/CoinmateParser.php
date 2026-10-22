<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use MyPortfolio\Service\Import\Draft;

/**
 * Coinmate (coinmate.io) — Historie → CSV All. Středník, hlavička podle jazyka UI (EN / CZ):
 *   ID;Date;Account;Type;Amount;Amount Currency;Price;Price Currency;Fee;Fee Currency;Total;Total Currency;Description;Status;…
 * Starší export je bez sloupce Account. Čas je místní (Praha) — formát „2024-01-15 10:00:00" nebo „16.08.2021 9:42".
 * Obchody s kryptoměnou jako kotací (ETH/BTC) se neimportují — cenu nejde převést kurzem ČNB.
 */
final class CoinmateParser extends AbstractParser
{
    private const BUY = ['BUY', 'QUICK_BUY', 'MARKET_BUY', 'INSTANT_BUY'];
    private const SELL = ['SELL', 'QUICK_SELL', 'MARKET_SELL', 'INSTANT_SELL'];
    private const REWARD = ['REFERRAL', 'AFFILIATE', 'NEW_USER_REWARD', ''];

    public function key(): string
    {
        return 'coinmate';
    }

    public function label(): string
    {
        return 'Coinmate';
    }

    public function detect(array $tables): bool
    {
        return $this->rowsAny($tables) !== null;
    }

    public function parse(array $tables): array
    {
        $rows = $this->rowsAny($tables) ?? throw new \InvalidArgumentException('Nenalezena hlavička Coinmate exportu.');
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->row($row);
        }
        return $out;
    }

    private function rowsAny(array $tables): ?array
    {
        return $this->rows($tables, ['ID', 'Amount Currency', 'Price Currency', 'Fee Currency'])
            ?? $this->rows($tables, ['ID', 'Částka měny', 'Cena měny', 'Poplatek měny']);
    }

    private function row(array $row): Draft
    {
        $line = $this->line($row);
        $at = $this->dateTime($this->col($row, 'Date', 'Datum'), 'Europe/Prague');
        $type = strtoupper($this->col($row, 'Type', 'Typ'));
        $id = ($this->col($row, 'ID') ?: $this->rowHash($row)) . ':' . $type;
        $symbol = strtoupper($this->col($row, 'Amount Currency', 'Částka měny'));
        $qty = $this->num($this->col($row, 'Amount', 'Částka'));
        $price = $this->num($this->col($row, 'Price', 'Cena'), true, '.');
        $priceCurrency = strtoupper($this->col($row, 'Price Currency', 'Cena měny'));
        $fee = $this->num($this->col($row, 'Fee', 'Poplatek'), true, '.');
        $fee = $fee !== null && $fee !== '0' ? $fee : null;
        $feeCurrency = strtoupper($this->col($row, 'Fee Currency', 'Poplatek měny')) ?: null;
        $status = strtoupper($this->col($row, 'Status'));
        $note = $this->col($row, 'Description', 'Popisek') ?: null;

        if (!in_array($status, ['OK', 'COMPLETED', ''], true)) {
            return Draft::skip($at, "Stav „{$status}\" — nedokončený pohyb", $line, $id, $symbol);
        }
        if (in_array($type, self::BUY, true) || in_array($type, self::SELL, true)) {
            if (!$this->isFiat($priceCurrency)) {
                return Draft::skip($at, "Obchod za kryptoměnu ({$symbol}/{$priceCurrency}) — zadej ručně", $line, $id, $symbol);
            }
            return new Draft(in_array($type, self::BUY, true) ? 'buy' : 'sell', $at, $symbol, quantity: $qty, unitPrice: $price,
                currency: $priceCurrency, fee: $fee, feeCurrency: $fee !== null ? $feeCurrency : null, externalId: $id, note: $note, line: $line);
        }
        if ($this->isFiat($symbol)) {
            return Draft::skip($at, 'Pohyb fiat měny (vklad / výběr / bonus v penězích)', $line, $id, $symbol);
        }
        return match (true) {
            $type === 'DEPOSIT'                   => new Draft('receive', $at, $symbol, quantity: $qty, externalId: $id, note: $note, line: $line),
            $type === 'WITHDRAWAL'                => new Draft('send', $at, $symbol, quantity: $qty, fee: $fee, feeCurrency: $fee !== null ? $feeCurrency : null,
                externalId: $id, note: $note, line: $line),
            in_array($type, self::REWARD, true)   => new Draft('reward', $at, $symbol, quantity: $qty, externalId: $id, note: $note, line: $line),
            str_starts_with($type, 'BALANCE_MOVE') => Draft::skip($at, 'Přesun mezi vlastními podúčty', $line, $id, $symbol),
            default                               => Draft::skip($at, "Nepodporovaný typ „{$type}\" — zadej ručně", $line, $id, $symbol),
        };
    }
}
