<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange\Client;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\Exchange\ExchangeException;
use MyPortfolio\Service\Exchange\ExchangeJson;
use MyPortfolio\Service\Import\Parser\BinanceParser;

/**
 * Binance (binance.com) — API klíč s oprávněním jen „Enable Reading" (HMAC). Pohyby se skládají z více endpointů
 * a převedou se na řádky exportu „Transaction History" (UTC_Time, Account, Operation, Coin, Change, Remark),
 * které zpracuje BinanceParser:
 *   obchody      GET /api/v3/myTrades (po párech; páry = aktiva ze zůstatků a historie × exchangeInfo)
 *   vklady       GET /sapi/v1/capital/deposit/hisrec   (okna < 90 dní)
 *   výběry       GET /sapi/v1/capital/withdraw/history (okna < 90 dní)
 *   Convert      GET /sapi/v1/convert/tradeFlow        (okna ≤ 30 dní)
 *   odměny       GET /sapi/v1/asset/assetDividend (≤ 180 dní), Simple Earn rewardsRecord (≤ 30 dní)
 *   nákup kartou GET /sapi/v1/fiat/payments
 * Stránkování: vklady / výběry `offset`, fiat platby `page`, Convert (`moreData`) a dividendy bez stránek → plné okno se
 * rozpůlí. Obchody: běžná synchronizace (≤ 30 dní) po 24h oknech startTime+endTime, delší rozsah od fromId = 0.
 * Páry se skládají z aktiv ze zůstatků, historie a z aktiv, která už na účtu v aplikaci jsou (uzavřené pozice).
 * Obchod v páru, jehož aktivum se na účtu nikdy neobjevilo (koupeno i prodáno před napojením), API nenajde — doplň CSV.
 */
final class BinanceClient extends AbstractExchangeClient
{
    private const BASE = 'https://api.binance.com';
    private const EPOCH = '2017-07-01';
    private const QUOTES = ['USDT', 'USDC', 'FDUSD', 'BUSD', 'TUSD', 'BTC', 'ETH', 'BNB', 'EUR', 'USD', 'GBP', 'TRY', 'BRL', 'PLN', 'CZK', 'DAI'];
    private const DANGEROUS = ['enableWithdrawals' => 'výběry', 'enableSpotAndMarginTrading' => 'obchodování', 'enableMargin' => 'margin',
        'enableFutures' => 'futures', 'enableInternalTransfer' => 'interní převody', 'permitsUniversalTransfer' => 'univerzální převody'];
    private const DAY = 86400;
    private const TRADE_WINDOW_SYNC_DAYS = 30;
    private const MIN_SPLIT_MS = 1000;

    public function provider(): string
    {
        return 'binance';
    }

    public function label(): string
    {
        return 'Binance';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'api_key', 'label' => ['API klíč', 'API key'], 'secret' => false],
            ['key' => 'api_secret', 'label' => ['Secret key', 'Secret key'], 'secret' => true],
        ];
    }

    public function test(array $credentials): array
    {
        $r = (array) $this->signed($credentials, '/sapi/v1/account/apiRestrictions');
        if (($r['enableReading'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'Klíč nemá oprávnění „Enable Reading".', 'warnings' => []];
        }
        $extra = array_values(array_intersect_key(self::DANGEROUS, array_filter($r, static fn ($v) => $v === true)));
        return ['ok' => true, 'message' => 'Klíč funguje — historie jde číst.',
            'warnings' => $extra !== [] ? ['Klíč má povolené i ' . implode(', ', $extra) . ' — pro synchronizaci stačí jen čtení, vytvoř raději klíč jen pro čtení.'] : []];
    }

    public function fetch(array $credentials, ?\DateTimeImmutable $from, \DateTimeImmutable $to, array $knownAssets = []): array
    {
        $start = $from ?? new \DateTimeImmutable(self::EPOCH . ' 00:00:00 UTC');
        $rows = [];
        $assets = [];
        $add = static function (int $ms, string $account, string $operation, string $coin, BigDecimal $change, string $remark) use (&$rows, &$assets): void {
            if ($change->isZero()) {
                return;
            }
            $rows[] = ['', gmdate('Y-m-d H:i:s', intdiv($ms, 1000)), $account, $operation, strtoupper($coin), (string) $change, $remark];
            $assets[strtoupper($coin)] = true;
        };

        foreach ($knownAssets as $asset) {
            $assets[strtoupper($asset)] = true;
        }
        foreach ($this->windows($start, $to, 89) as [$a, $b]) {
            foreach ($this->offsetPages($credentials, '/sapi/v1/capital/deposit/hisrec', ['startTime' => $a, 'endTime' => $b]) as $d) {
                if ((string) ($d['status'] ?? '') === '1') {
                    $add((int) ($d['completeTime'] ?? $d['insertTime']), 'Spot', 'Deposit', (string) $d['coin'], BigDecimal::of((string) $d['amount']), 'deposit:' . $d['id']);
                }
            }
            foreach ($this->offsetPages($credentials, '/sapi/v1/capital/withdraw/history', ['startTime' => $a, 'endTime' => $b]) as $w) {
                if ((string) ($w['status'] ?? '') === '6') {
                    $time = (new \DateTimeImmutable((string) ($w['completeTime'] ?: $w['applyTime']) . ' UTC'))->getTimestamp() * 1000;
                    $total = BigDecimal::of((string) $w['amount'])->plus((string) ($w['transactionFee'] ?? '0'));
                    $add($time, 'Spot', 'Withdraw', (string) $w['coin'], $total->negated(), 'withdraw:' . $w['id']);
                }
            }
        }
        foreach ($this->windows($start, $to, 30) as [$a, $b]) {
            $converts = $this->split($a, $b, function (int $x, int $y) use ($credentials): array {
                $r = (array) $this->signed($credentials, '/sapi/v1/convert/tradeFlow', ['startTime' => $x, 'endTime' => $y, 'limit' => 1000]);
                return [(array) ($r['list'] ?? []), ($r['moreData'] ?? false) === true];
            }, 'Convert');
            foreach ($converts as $c) {
                if (($c['orderStatus'] ?? '') === 'SUCCESS') {
                    $account = 'Spot convert ' . $c['quoteId'];
                    $add((int) $c['createTime'], $account, 'Binance Convert', (string) $c['fromAsset'], BigDecimal::of((string) $c['fromAmount'])->negated(), '');
                    $add((int) $c['createTime'], $account, 'Binance Convert', (string) $c['toAsset'], BigDecimal::of((string) $c['toAmount']), '');
                }
            }
            foreach (['flexible' => 'Simple Earn Flexible Interest', 'locked' => 'Simple Earn Locked Rewards'] as $kind => $operation) {
                for ($current = 1; $current < self::MAX_PAGES; $current++) {
                    $page = (array) $this->signed($credentials, "/sapi/v1/simple-earn/$kind/history/rewardsRecord",
                        ['startTime' => $a, 'endTime' => $b, 'current' => $current, 'size' => 100]);
                    foreach ((array) ($page['rows'] ?? []) as $r) {
                        $add((int) $r['time'], 'Earn', $operation, (string) $r['asset'], BigDecimal::of((string) ($r['rewards'] ?? $r['amount'] ?? '0')), (string) ($r['type'] ?? ''));
                    }
                    if ($current * 100 >= (int) ($page['total'] ?? 0)) {
                        break;
                    }
                }
            }
            foreach (['0' => 1, '1' => -1] as $type => $sign) {
                $payments = [];
                for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                    $batch = (array) ($this->signed($credentials, '/sapi/v1/fiat/payments',
                        ['transactionType' => $type, 'beginTime' => $a, 'endTime' => $b, 'page' => $page, 'rows' => 500])['data'] ?? []);
                    array_push($payments, ...$batch);
                    if (count($batch) < 500) {
                        break;
                    }
                }
                foreach ($payments as $f) {
                    if (($f['status'] ?? '') !== 'Completed') {
                        continue;
                    }
                    $account = 'Spot fiat ' . $f['orderNo'];
                    $fee = BigDecimal::of((string) ($f['totalFee'] ?? '0'));
                    $fiat = BigDecimal::of((string) $f['sourceAmount'])->minus($sign === 1 ? $fee : BigDecimal::zero());
                    $add((int) $f['createTime'], $account, $sign === 1 ? 'Transaction Buy' : 'Transaction Sold', (string) $f['cryptoCurrency'],
                        BigDecimal::of((string) $f['obtainAmount'])->multipliedBy($sign), '');
                    $add((int) $f['createTime'], $account, $sign === 1 ? 'Transaction Spend' : 'Transaction Revenue', (string) $f['fiatCurrency'], $fiat->multipliedBy(-$sign), '');
                    $add((int) $f['createTime'], $account, 'Transaction Fee', (string) $f['fiatCurrency'], $fee->negated(), '');
                }
            }
        }
        foreach ($this->windows($start, $to, 180) as [$a, $b]) {
            $dividends = $this->split($a, $b, function (int $x, int $y) use ($credentials): array {
                $rows = (array) ($this->signed($credentials, '/sapi/v1/asset/assetDividend', ['startTime' => $x, 'endTime' => $y, 'limit' => 500])['rows'] ?? []);
                return [$rows, count($rows) >= 500];
            }, 'odměny');
            foreach ($dividends as $d) {
                $add((int) $d['divTime'], 'Spot', 'Distribution', (string) $d['asset'], BigDecimal::of((string) $d['amount']), (string) ($d['enInfo'] ?? ''));
            }
        }

        foreach ((array) ($this->signed($credentials, '/api/v3/account', ['omitZeroBalances' => 'true'])['balances'] ?? []) as $b) {
            $assets[strtoupper((string) $b['asset'])] = true;
        }
        foreach ($this->symbols(array_keys($assets)) as $s) {
            foreach ($this->trades($credentials, $s['symbol'], $from, $to) as $t) {
                if ((int) $t['time'] > $to->getTimestamp() * 1000) {
                    continue;
                }
                $buy = $t['isBuyer'] === true || $t['isBuyer'] === 'true';
                $account = 'Spot ' . $s['symbol'];
                $remark = 'trade:' . $s['symbol'] . ':' . $t['id'];
                $qty = BigDecimal::of((string) $t['qty']);
                $quote = BigDecimal::of((string) $t['quoteQty']);
                $add((int) $t['time'], $account, $buy ? 'Transaction Buy' : 'Transaction Sold', $s['base'], $buy ? $qty : $qty->negated(), $remark);
                $add((int) $t['time'], $account, $buy ? 'Transaction Spend' : 'Transaction Revenue', $s['quote'], $buy ? $quote->negated() : $quote, $remark);
                $add((int) $t['time'], $account, 'Transaction Fee', (string) $t['commissionAsset'], BigDecimal::of((string) $t['commission'])->negated(), $remark);
            }
        }

        if ($rows === []) {
            return [];
        }
        usort($rows, static fn (array $a, array $b) => $a[1] <=> $b[1]);
        return (new BinanceParser())->parse([self::table(['User_ID', 'UTC_Time', 'Account', 'Operation', 'Coin', 'Change', 'Remark'], $rows)]);
    }

    /**
     * Páry ke stažení obchodů: základ i kotace mezi známými aktivy (nebo kotace běžná — USDT, EUR…).
     *
     * @param list<string> $assets
     * @return list<array{symbol:string,base:string,quote:string}>
     */
    private function symbols(array $assets): array
    {
        $known = array_flip($assets);
        $info = (array) $this->public('/api/v3/exchangeInfo', ['permissions' => 'SPOT']);
        $out = [];
        foreach ((array) ($info['symbols'] ?? []) as $s) {
            $base = (string) $s['baseAsset'];
            $quote = (string) $s['quoteAsset'];
            if (isset($known[$base]) && (isset($known[$quote]) || in_array($quote, self::QUOTES, true))) {
                $out[] = ['symbol' => (string) $s['symbol'], 'base' => $base, 'quote' => $quote];
            }
        }
        return $out;
    }

    /**
     * Obchody páru v [$from, $to]. myTrades bez fromId vrací nejnovější obchody, startTime+endTime smí mít rozsah
     * nejvýš 24 h a s fromId se nekombinuje. Běžná synchronizace (≤ 30 dní): po 24h oknech, plné okno se půlí.
     * Delší rozsah / celá historie: od fromId = 0 vzestupně a filtr podle času.
     *
     * @param array<string,string> $credentials
     * @return list<array<string,mixed>>
     */
    private function trades(array $credentials, string $symbol, ?\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $toMs = $to->getTimestamp() * 1000;
        if ($from === null || $to->getTimestamp() - $from->getTimestamp() > self::TRADE_WINDOW_SYNC_DAYS * self::DAY) {
            $fromMs = $from !== null ? $from->getTimestamp() * 1000 : 0;
            return array_values(array_filter($this->fromIdPages($credentials, $symbol, 0, $toMs),
                static fn (array $t) => (int) $t['time'] >= $fromMs));
        }
        $out = [];
        foreach ($this->windows($from, $to, 1) as [$a, $b]) {
            // Plné okno vrací nejnovějších 1000 obchodů — starší by chyběly, proto se okno půlí (ne fromId výš)
            array_push($out, ...$this->split($a, $b, function (int $x, int $y) use ($credentials, $symbol): array {
                $batch = (array) $this->signed($credentials, '/api/v3/myTrades', ['symbol' => $symbol, 'startTime' => $x, 'endTime' => $y, 'limit' => 1000]);
                return [$batch, count($batch) >= 1000];
            }, "obchody $symbol"));
        }
        return $out;
    }

    /**
     * Obchody od `$fromId` vzestupně až do času `$untilMs`.
     *
     * @param array<string,string> $credentials
     * @return list<array<string,mixed>>
     */
    private function fromIdPages(array $credentials, string $symbol, int $fromId, int $untilMs): array
    {
        $out = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = (array) $this->signed($credentials, '/api/v3/myTrades', ['symbol' => $symbol, 'fromId' => $fromId, 'limit' => 1000]);
            foreach ($batch as $t) {
                if ((int) $t['time'] > $untilMs) {
                    return $out;
                }
                $out[] = $t;
            }
            if (count($batch) < 1000) {
                break;
            }
            $fromId = (int) end($batch)['id'] + 1;
        }
        return $out;
    }

    /**
     * Endpoint se stránkováním `offset` (vklady, výběry — po 1000).
     *
     * @param array<string,string> $credentials
     * @param array<string,scalar> $params
     * @return list<array<string,mixed>>
     */
    private function offsetPages(array $credentials, string $path, array $params): array
    {
        $out = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = (array) $this->signed($credentials, $path, $params + ['limit' => 1000, 'offset' => $page * 1000]);
            array_push($out, ...$batch);
            if (count($batch) < 1000) {
                break;
            }
        }
        return $out;
    }

    /**
     * Endpoint bez stránkování: když vrátí plné okno (`$fetch` → [řádky, plné?]), okno se rozpůlí a stáhne znovu.
     * Plné i nejmenší okno = data by chyběla → chyba (synchronizace se nezapíše a kurzor se neposune), nikdy tiché zahození.
     *
     * @param callable(int,int):array{0:list<array<string,mixed>>,1:bool} $fetch
     * @return list<array<string,mixed>>
     */
    private function split(int $a, int $b, callable $fetch, string $what): array
    {
        [$rows, $full] = $fetch($a, $b);
        if (!$full) {
            return $rows;
        }
        if ($b - $a <= self::MIN_SPLIT_MS) {
            throw new ExchangeException(sprintf('Binance: %s — víc záznamů v okně %s, než API vrátí; doplň je importem CSV.',
                $what, gmdate('Y-m-d H:i:s', intdiv($a, 1000)) . ' UTC'));
        }
        $mid = intdiv($a + $b, 2);
        return [...$this->split($a, $mid, $fetch, $what), ...$this->split($mid + 1, $b, $fetch, $what)];
    }

    /**
     * Časová okna [od, do] v ms po nejvýš `$days` dnech (limity historických endpointů).
     *
     * @return list<array{0:int,1:int}>
     */
    private function windows(\DateTimeImmutable $from, \DateTimeImmutable $to, int $days): array
    {
        $out = [];
        for ($a = $from->getTimestamp(); $a < $to->getTimestamp(); $a += $days * self::DAY) {
            $out[] = [$a * 1000, min(($a + $days * self::DAY) * 1000 - 1, $to->getTimestamp() * 1000)];
        }
        return $out;
    }

    /**
     * @param array<string,string> $credentials
     * @param array<string,scalar> $params
     */
    private function signed(array $credentials, string $path, array $params = []): mixed
    {
        $query = http_build_query($params + ['recvWindow' => 10000, 'timestamp' => self::nonce()]);
        $query .= '&signature=' . hash_hmac('sha256', $query, self::credential($credentials, 'api_secret'));
        return $this->send($path . '?' . $query, ['X-MBX-APIKEY' => self::credential($credentials, 'api_key')]);
    }

    /** @param array<string,scalar> $params */
    private function public(string $path, array $params): mixed
    {
        return $this->send($path . '?' . http_build_query($params), []);
    }

    /** @param array<string,string> $headers */
    private function send(string $pathWithQuery, array $headers): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            $r = $this->http->request('GET', self::BASE . $pathWithQuery, $headers);
            if (in_array($r['status'], [429, 418], true) && $attempt < 3) {
                $this->sleep(max(1, (int) ($r['headers']['retry-after'] ?? 60)));
                continue;
            }
            $json = ExchangeJson::decode($r['body'] !== '' ? $r['body'] : '{}');
            if ($r['status'] >= 200 && $r['status'] < 300) {
                if ((int) ($r['headers']['x-mbx-used-weight-1m'] ?? 0) > 5000) {
                    $this->sleep(60);  // sdílený limit 6000 / min na IP
                }
                return $json;
            }
            $code = (string) ($json['code'] ?? '');
            throw new ExchangeException('Binance: ' . match ($code) {
                '-2014', '-2015' => 'neplatný API klíč, IP nebo oprávnění.',
                '-1022'          => 'neplatný podpis — zkontroluj Secret key.',
                '-1021'          => 'čas serveru se rozchází s Binance — zkontroluj hodiny serveru.',
                default          => (string) ($json['msg'] ?? ('HTTP ' . $r['status'])),
            });
        }
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
