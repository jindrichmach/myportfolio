<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Fx;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;

/**
 * Denní kurzový lístek ČNB (`denni_kurz.txt?date=DD.MM.YYYY`).
 *
 * Pro víkend a svátek ČNB vrací lístek posledního pracovního dne — skutečné datum
 * platnosti je v hlavičce souboru („25.09.2026 #186"), ne v požadavku.
 */
final class CnbFxProvider
{
    public function __construct(
        private readonly HttpFetcher $http,
        private readonly Config $config,
    ) {}

    /**
     * @return array{date:string,rates:list<array{currency:string,amount:int,rate:string}>}
     */
    public function fetch(\DateTimeImmutable $date): array
    {
        $url = (string) $this->config->get('prices.cnb.daily_url') . '?date=' . $date->format('d.m.Y');
        return self::parse($this->http->get($url));
    }

    /**
     * Roční soubor (`rok.txt?rok=YYYY`) — všechny lístky roku jedním požadavkem (backfill).
     *
     * @return list<array{date:string,rates:list<array{currency:string,amount:int,rate:string}>}>
     */
    public function fetchYear(int $year): array
    {
        $url = str_replace('denni_kurz.txt', 'rok.txt', (string) $this->config->get('prices.cnb.daily_url')) . '?rok=' . $year;
        return self::parseYear($this->http->get($url));
    }

    /**
     * Hlavička `Datum|1 AUD|100 HUF|…` se může v průběhu roku opakovat (změna seznamu měn).
     *
     * @return list<array{date:string,rates:list<array{currency:string,amount:int,rate:string}>}>
     */
    public static function parseYear(string $body): array
    {
        $columns = [];
        $sheets = [];
        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            $cols = explode('|', trim($line));
            if ($cols[0] === 'Datum') {
                $columns = [];
                foreach (array_slice($cols, 1) as $i => $head) {
                    if (preg_match('/^(\d+) ([A-Z]{3})$/', trim($head), $m) === 1) {
                        $columns[$i + 1] = ['amount' => (int) $m[1], 'currency' => $m[2]];
                    }
                }
                continue;
            }
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $cols[0], $d) !== 1 || $columns === []) {
                continue;
            }
            $rates = [];
            foreach ($columns as $i => $col) {
                $rate = str_replace([',', ' '], ['.', ''], (string) ($cols[$i] ?? ''));
                if (preg_match('/^\d+(\.\d+)?$/', $rate) === 1) {
                    $rates[] = ['currency' => $col['currency'], 'amount' => $col['amount'], 'rate' => $rate];
                }
            }
            if ($rates !== []) {
                $sheets[] = ['date' => sprintf('%s-%s-%s', $d[3], $d[2], $d[1]), 'rates' => $rates];
            }
        }
        if ($sheets === []) {
            throw new MarketDataException('ČNB: roční soubor neobsahuje žádné kurzy.');
        }
        return $sheets;
    }

    /**
     * @return array{date:string,rates:list<array{currency:string,amount:int,rate:string}>}
     */
    public static function parse(string $body): array
    {
        $lines = preg_split('/\r?\n/', trim($body)) ?: [];
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $lines[0] ?? '', $m) !== 1) {
            throw new MarketDataException('ČNB: neočekávaná hlavička kurzového lístku.');
        }
        $date = sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);

        $rates = [];
        foreach (array_slice($lines, 2) as $line) {
            $cols = explode('|', trim($line));
            if (count($cols) !== 5 || preg_match('/^[A-Z]{3}$/', $cols[3]) !== 1) {
                continue;
            }
            $rate = str_replace([',', ' '], ['.', ''], $cols[4]);
            if (preg_match('/^\d+(\.\d+)?$/', $rate) !== 1 || !ctype_digit($cols[2])) {
                continue;
            }
            $rates[] = ['currency' => $cols[3], 'amount' => (int) $cols[2], 'rate' => $rate];
        }
        if ($rates === []) {
            throw new MarketDataException('ČNB: kurzový lístek neobsahuje žádné kurzy.');
        }
        return ['date' => $date, 'rates' => $rates];
    }
}
