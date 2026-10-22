<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use MyPortfolio\Service\Import\Draft;

/**
 * Revolut — Invest → Dokumenty → Akcie → Výpis účtu (CSV):
 *   Date,Ticker,Type,Quantity,Price per share,Total Amount,Currency,FX Rate
 * Datum ISO v UTC, částky se symbolem nebo kódem měny („$85.11", "USD 134.50"). Žádné ID → hash + pořadí.
 * Dividenda je ČISTÁ (srážková daň ve výpisu není). Split uvádí jen přidané kusy → poměr zadej ručně.
 */
final class RevolutParser extends AbstractParser
{
    public function key(): string
    {
        return 'revolut';
    }

    public function label(): string
    {
        return 'Revolut (akcie)';
    }

    public function detect(array $tables): bool
    {
        return $this->hasHeader($tables, ['Date', 'Ticker', 'Type', 'Quantity', 'Price per share', 'Total Amount']);
    }

    public function parse(array $tables): array
    {
        $rows = $this->rows($tables, ['Date', 'Ticker', 'Type', 'Quantity', 'Price per share', 'Total Amount'])
            ?? throw new \InvalidArgumentException('Nenalezena hlavička výpisu Revolut.');
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $type = strtoupper($this->col($row, 'Type'));
            $at = $this->dateTime($this->col($row, 'Date'));
            $line = $this->line($row);
            $hash = $this->rowHash($row);
            $id = $hash . ':' . ($seen[$hash] = ($seen[$hash] ?? 0) + 1);
            $ticker = strtoupper($this->col($row, 'Ticker'));
            $currency = strtoupper($this->col($row, 'Currency')) ?: null;
            $total = $this->col($row, 'Total Amount');
            $amount = $this->num($total, true, '.');
            $qty = $this->num($this->col($row, 'Quantity'), true, '.');
            $asset = ['symbol' => $ticker, 'assetClass' => 'security', 'currency' => $currency, 'externalId' => $id, 'line' => $line];

            $out[] = match (true) {
                str_starts_with($type, 'BUY')  => new Draft(...$asset + ['kind' => 'buy', 'executedAt' => $at, 'quantity' => $qty, 'amount' => $amount]),
                str_starts_with($type, 'SELL') => new Draft(...$asset + ['kind' => 'sell', 'executedAt' => $at, 'quantity' => $qty, 'amount' => $amount]),
                $type === 'DIVIDEND'           => new Draft(...$asset + ['kind' => 'dividend', 'executedAt' => $at, 'amount' => $amount,
                    'note' => 'Čistá dividenda (Revolut srážkovou daň neuvádí)']),
                str_starts_with($type, 'DIVIDEND TAX') && $this->negative($total) && $ticker !== ''
                                               => new Draft(...$asset + ['kind' => 'fee', 'executedAt' => $at, 'amount' => $amount, 'note' => 'Daň z dividendy (oprava)']),
                $type === 'STOCK SPLIT'        => Draft::skip($at, 'Split — výpis uvádí jen přidané kusy, zadej poměr ručně', $line, $id, $ticker),
                default                        => Draft::skip($at, str_starts_with($type, 'CASH') || str_starts_with($type, 'TRANSFER') || str_starts_with($type, 'CUSTODY')
                    ? 'Hotovost / poplatek účtu' : "Nepodporovaný typ „{$type}\" — zadej ručně", $line, $id, $ticker),
            };
        }
        return $out;
    }
}
