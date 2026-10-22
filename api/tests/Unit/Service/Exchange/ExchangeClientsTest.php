<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Exchange;

use MyPortfolio\Service\Exchange\Client\BinanceClient;
use MyPortfolio\Service\Exchange\Client\CoinbaseClient;
use MyPortfolio\Service\Exchange\Client\CoinmateClient;
use MyPortfolio\Service\Exchange\Client\KrakenClient;
use MyPortfolio\Service\Exchange\ExchangeException;
use MyPortfolio\Service\Exchange\ExchangeJson;
use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Tests\Support\FakeExchangeHttp;
use PHPUnit\Framework\TestCase;

/** Klienti API burz proti uloženým (syntetickým) odpovědím ve tvaru dokumentace — nikdy živé API. */
final class ExchangeClientsTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Prague');
    }

    /** @param list<Draft> $d @return list<array<int,mixed>> */
    private static function brief(array $d): array
    {
        return array_map(static fn (Draft $x) => [$x->kind, $x->symbol, $x->quantity, $x->amount, $x->currency, $x->fee], $d);
    }

    public function testJsonKeepsNumbersAsStrings(): void
    {
        self::assertSame(['a' => '0.02055184', 'b' => ['1', '-2.5e-8', 'x"1.5'], 'c' => null, 'd' => false],
            ExchangeJson::decode('{"a":0.02055184,"b":[1,-2.5e-8,"x\"1.5"],"c":null,"d":false}'));
    }

    public function testKrakenSignatureMatchesOfficialVector(): void
    {
        // Vzorové hodnoty z docs.kraken.com (Spot REST authentication) — veřejný příklad, ne skutečný klíč
        $secret = base64_decode('kQH5HW/8p1uGOVjbgWA7FunAmGO8lsSUXNsu3eow76sz84Q18fWxnyRzBHCd3pd5nE9qa99HAZtuZuj6F1huXg==');
        self::assertSame('4/dpxb3iT4tp/ZCVEwSnEsLxx0bqyhLpdfOpc6fn7OR8+UClSV5n9E6aSS8MPtnRfp32bAb0nmbRn6H8ndwLUQ==',
            KrakenClient::sign('/0/private/AddOrder', '1616492376594', 'nonce=1616492376594&ordertype=limit&pair=XBTUSD&price=37500&type=buy&volume=1.25', $secret));
    }

    public function testKrakenLedgerBecomesTradesAndRewards(): void
    {
        $http = new FakeExchangeHttp([
            '#/0/private/Ledgers#' => '{"error":[],"result":{"ledger":{
                "LZ9STK-RWD01-ABCDEF":{"refid":"STHFSYV-COKEV-2N3FK7","time":1688300000.0001,"type":"staking","subtype":"","aclass":"currency","asset":"DOT.S","amount":"0.0123000000","fee":"0.0000000000","balance":"12.0123000000"},
                "L4UESK-KG3EQ-UFO4T5":{"refid":"TJKLXF-PGMUI-4NTLXU","time":1688464484.1787,"type":"trade","subtype":"","aclass":"currency","asset":"ZEUR","amount":"-24.5000","fee":"0.0490","balance":"459.9171"},
                "LMKZCZ-Z3GVL-CXKK4H":{"refid":"TJKLXF-PGMUI-4NTLXU","time":1688464484.1787,"type":"trade","subtype":"","aclass":"currency","asset":"XXBT","amount":"0.0009000000","fee":"0.0000000000","balance":"0.0109000000"}},
                "cursor":{"next":null}}}',
            '#/0/private/GetApiKeyInfo#' => '{"error":[],"result":{"permissions":["query-funds","query-ledger","withdraw-funds"]}}',
        ]);
        $client = new KrakenClient($http);
        $creds = ['api_key' => 'SYNTHETICKEY', 'api_secret' => base64_encode('synthetic-secret')];
        $drafts = $client->fetch($creds, new \DateTimeImmutable('2023-07-01'), new \DateTimeImmutable('2023-07-31'));
        self::assertSame([['reward', 'DOT', '0.0123', null, null, null], ['buy', 'BTC', '0.0009', '24.5', 'EUR', '0.049']],
            array_map(static fn (array $b) => [$b[0], $b[1], $b[2], $b[3], $b[4], $b[5]], self::brief($drafts)));
        $req = $http->requests[0];
        self::assertSame(KrakenClient::sign('/0/private/Ledgers', (string) explode('&', (string) substr((string) $req['body'], 6))[0], (string) $req['body'], 'synthetic-secret'),
            $req['headers']['API-Sign']);
        self::assertStringContainsString('start=1688162399', (string) $req['body']);

        $test = $client->test($creds);
        self::assertTrue($test['ok']);
        self::assertStringContainsString('withdraw-funds', $test['warnings'][0]);
    }

    public function testCoinmateHistoryAndErrors(): void
    {
        $http = new FakeExchangeHttp(['#/api/transactionHistory#' => '{"error":false,"errorMessage":null,"data":[
            {"timestamp":1505823601032,"transactionId":1394384,"transactionType":"DEPOSIT","price":null,"priceCurrency":null,"amount":1000.00,"amountCurrency":"CZK","fee":0,"feeCurrency":"CZK","description":"BANK_WIRE","status":"OK","orderId":null},
            {"timestamp":1505823601032,"transactionId":1394384,"transactionType":"WITHDRAWAL","price":null,"priceCurrency":null,"amount":0.02055184,"amountCurrency":"BTC","fee":0.0003,"feeCurrency":"BTC","description":"BTC: synthetic","status":"PENDING","orderId":null},
            {"timestamp":1505823601902,"transactionId":1394385,"transactionType":"BUY","price":24243.86,"priceCurrency":"CZK","amount":0.02055184,"amountCurrency":"BTC","fee":1.74,"feeCurrency":"CZK","description":"Kč 499.99","status":"OK","orderId":null}]}']);
        $client = new CoinmateClient($http);
        $creds = ['client_id' => '6', 'public_key' => 'SYNTHPUB', 'private_key' => 'SYNTHPRIV'];
        $d = $client->fetch($creds, null, new \DateTimeImmutable('2017-10-01'));
        self::assertSame([['skip', 'CZK'], ['skip', 'BTC'], ['buy', 'BTC']], array_map(static fn (Draft $x) => [$x->kind, $x->symbol], $d));
        self::assertSame(['0.02055184', '24243.86', '1.74', 'CZK', '1394385:BUY'], [$d[2]->quantity, $d[2]->unitPrice, $d[2]->fee, $d[2]->currency, $d[2]->externalId]);
        parse_str((string) $http->requests[0]['body'], $sent);
        self::assertSame(strtoupper(hash_hmac('sha256', $sent['nonce'] . '6SYNTHPUB', 'SYNTHPRIV')), $sent['signature']);

        $this->expectException(ExchangeException::class);
        $this->expectExceptionMessage('přístup odepřen');
        (new CoinmateClient(new FakeExchangeHttp(['#/api/balances#' => '{"error":true,"errorMessage":"Access denied.","data":null}'])))->test($creds);
    }

    public function testBinanceCombinesEndpoints(): void
    {
        $http = new FakeExchangeHttp([
            '#/sapi/v1/capital/deposit/hisrec#' => '[{"id":"769800519366885376","amount":"0.5","coin":"ETH","status":1,"insertTime":1700000000000,"completeTime":1700000000000}]',
            '#/sapi/v1/capital/withdraw/history#' => '[]',
            '#/sapi/v1/convert/tradeFlow#' => '{"list":[{"quoteId":"q1","orderId":940708407462087200,"orderStatus":"SUCCESS","fromAsset":"ETH","fromAmount":"0.1","toAsset":"USDT","toAmount":"200","createTime":1700100000000}],"moreData":false}',
            '#/sapi/v1/simple-earn/#' => '{"rows":[],"total":0}',
            '#/sapi/v1/fiat/payments#' => '{"code":"000000","message":"success","data":[],"total":0,"success":true}',
            '#/sapi/v1/asset/assetDividend#' => '{"rows":[{"id":1,"amount":"0.001","asset":"ETH","divTime":1700200000000,"enInfo":"ETH 2.0 Staking","tranId":2968885920,"direction":1}],"total":1}',
            '#/api/v3/account#' => '{"balances":[{"asset":"BTC","free":"0.01","locked":"0"}]}',
            '#/api/v3/exchangeInfo#' => '{"symbols":[{"symbol":"BTCEUR","baseAsset":"BTC","quoteAsset":"EUR"},{"symbol":"XRPEUR","baseAsset":"XRP","quoteAsset":"EUR"}]}',
            '#/api/v3/myTrades\?symbol=BTCEUR#' => self::trades('[{"symbol":"BTCEUR","id":28457,"orderId":100234,"price":"40000","qty":"0.01","quoteQty":"400","commission":"0.4","commissionAsset":"EUR","time":1700300000000,"isBuyer":true}]'),
            '#/api/v3/myTrades\?symbol=XRPEUR#' => '[]',
        ]);
        $client = new BinanceClient($http);
        $d = $client->fetch(['api_key' => 'SYNTHKEY', 'api_secret' => 'SYNTHSECRET'], new \DateTimeImmutable('2023-11-01'), new \DateTimeImmutable('2023-11-30'));
        $brief = self::brief($d);
        self::assertContains(['receive', 'ETH', '0.5', null, null, null], $brief);
        self::assertContains(['reward', 'ETH', '0.001', null, null, null], $brief);
        self::assertContains(['buy', 'BTC', '0.01', '400', 'EUR', '0.4'], $brief);
        self::assertContains(['sell', 'ETH', '0.1', '200', 'USD', null], $brief);   // ETH → USDT oceněno v USD
        $trades = array_values(array_filter($http->requests, static fn (array $r) => str_contains($r['url'], 'myTrades')));
        self::assertCount(29, $trades);   // 24h okna za 29 dní; XRP není mezi známými aktivy
        self::assertSame([], array_filter($trades, static fn (array $r) => !str_contains($r['url'], 'symbol=BTCEUR')));
        parse_str((string) parse_url($trades[0]['url'], PHP_URL_QUERY), $q);
        $unsigned = substr((string) parse_url($trades[0]['url'], PHP_URL_QUERY), 0, strpos((string) parse_url($trades[0]['url'], PHP_URL_QUERY), '&signature='));
        self::assertSame(hash_hmac('sha256', $unsigned, 'SYNTHSECRET'), $q['signature']);
        self::assertSame('SYNTHKEY', $trades[0]['headers']['X-MBX-APIKEY']);
    }

    public function testBinanceKnownAssetsAndLongRangeTrades(): void
    {
        $http = new FakeExchangeHttp([
            '#/sapi/#' => '[]',
            '#/api/v3/account#' => '{"balances":[]}',
            '#/api/v3/exchangeInfo#' => '{"symbols":[{"symbol":"XRPEUR","baseAsset":"XRP","quoteAsset":"EUR"}]}',
            '#/api/v3/myTrades#' => self::trades('[{"symbol":"XRPEUR","id":5,"price":"0.5","qty":"100","quoteQty":"50","commission":"0","commissionAsset":"EUR","time":1600000000000,"isBuyer":true},'
                . '{"symbol":"XRPEUR","id":6,"price":"0.6","qty":"100","quoteQty":"60","commission":"0","commissionAsset":"EUR","time":1700000000000,"isBuyer":false}]'),
        ]);
        // Uzavřená pozice XRP (nulový zůstatek) — pár se najde z aktiv účtu; rozsah > 30 dní → fromId = 0 a filtr podle času
        $d = (new BinanceClient($http))->fetch(['api_key' => 'K', 'api_secret' => 'S'], new \DateTimeImmutable('2021-01-01'), new \DateTimeImmutable('2024-01-01'), ['XRP']);
        self::assertSame([['sell', 'XRP', '100', '60', 'EUR', null]], self::brief($d));
        $trades = array_values(array_filter($http->requests, static fn (array $r) => str_contains($r['url'], 'myTrades')));
        self::assertStringContainsString('fromId=0', $trades[0]['url']);
        self::assertStringNotContainsString('startTime', $trades[0]['url']);
    }

    public function testCoinbaseStablecoinFillIsOneRow(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $fill = '"advanced_trade_fill":{"fill_price":"65000","product_id":"BTC-USDC","order_id":"o9","commission":"0.65","order_side":"BUY"}';
        $http = new FakeExchangeHttp([
            '#/v2/accounts\?#' => '{"pagination":{"next_uri":null},"data":[{"id":"acc-btc","type":"wallet"},{"id":"acc-usdc","type":"wallet"}]}',
            '#/v2/accounts/acc-btc/transactions#' => '{"pagination":{"next_uri":null},"data":[{"id":"b1","type":"advanced_trade_fill","status":"completed",'
                . '"amount":{"amount":"0.002","currency":"BTC"},"native_amount":{"amount":"120.00","currency":"EUR"},"created_at":"2026-03-14T10:00:00Z",' . $fill . '}]}',
            '#/v2/accounts/acc-usdc/transactions#' => '{"pagination":{"next_uri":null},"data":[{"id":"u1","type":"advanced_trade_fill","status":"completed",'
                . '"amount":{"amount":"-130","currency":"USDC"},"native_amount":{"amount":"-120.00","currency":"EUR"},"created_at":"2026-03-14T10:00:00Z",' . $fill . '}]}',
        ]);
        $d = (new CoinbaseClient($http))->fetch(['key_name' => 'organizations/x/apiKeys/k', 'private_key' => $pem],
            new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01'));
        $btc = array_values(array_filter(self::brief($d), static fn (array $r) => $r[1] === 'BTC'));
        // jeden nákup 0,002 BTC (ne druhý řádek z účtu USDC), poplatek 0,65 USDC → 0,60 EUR
        self::assertSame([['buy', 'BTC', '0.002', '120', 'EUR', '0.6']], $btc);
    }

    public function testBinanceBusyDayIsSplitNotTruncated(): void
    {
        // 1 500 obchodů XRPEUR během jednoho dne; okno vrací jen nejnovějších 1 000 (jako Binance bez fromId)
        $all = [];
        for ($i = 0; $i < 1500; $i++) {
            $all[] = ['id' => $i + 1, 'price' => '0.5', 'qty' => '1', 'quoteQty' => '0.5', 'commission' => '0', 'commissionAsset' => 'EUR',
                'time' => 1700000000000 + $i * 30000, 'isBuyer' => true];
        }
        $http = new FakeExchangeHttp([
            '#/sapi/#' => '[]',
            '#/api/v3/account#' => '{"balances":[{"asset":"XRP","free":"1500","locked":"0"}]}',
            '#/api/v3/exchangeInfo#' => '{"symbols":[{"symbol":"XRPEUR","baseAsset":"XRP","quoteAsset":"EUR"}]}',
            '#/api/v3/myTrades#' => static function (string $url) use ($all): string {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $hit = array_values(array_filter($all, static fn (array $t) => $t['time'] >= (int) $q['startTime'] && $t['time'] <= (int) $q['endTime']));
                return (string) json_encode(array_slice($hit, -1000));
            },
        ]);
        $d = (new BinanceClient($http))->fetch(['api_key' => 'K', 'api_secret' => 'S'], new \DateTimeImmutable('@1699990000'), new \DateTimeImmutable('@1700100000'));
        self::assertCount(1500, array_filter($d, static fn ($x) => $x->kind === 'buy'));
    }

    /** myTrades jako Binance: startTime+endTime → obchody v okně, fromId → od id vzestupně. */
    private static function trades(string $json): \Closure
    {
        return static function (string $url) use ($json): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $all = json_decode($json, true);
            $hit = array_values(array_filter($all, static fn (array $t) => isset($q['fromId'])
                ? $t['id'] >= (int) $q['fromId']
                : $t['time'] >= (int) $q['startTime'] && $t['time'] <= (int) $q['endTime']));
            return (string) json_encode($hit);
        };
    }

    public function testCoinbaseJwtAndTransactions(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $creds = ['key_name' => 'organizations/synthetic/apiKeys/key-1', 'private_key' => $pem];
        $http = new FakeExchangeHttp([
            '#/api/v3/brokerage/key_permissions#' => '{"can_view":true,"can_trade":false,"can_transfer":true,"portfolio_uuid":"x","portfolio_type":"DEFAULT"}',
            '#/v2/accounts\?#' => '{"pagination":{"next_uri":null},"data":[{"id":"acc-btc","type":"wallet","currency":{"code":"BTC"}},{"id":"acc-eur","type":"fiat","currency":{"code":"EUR"}}]}',
            '#/v2/accounts/acc-btc/transactions#' => '{"pagination":{"next_uri":null},"data":[
                {"id":"t4","type":"earn_payout","status":"completed","amount":{"amount":"0.00002100","currency":"BTC"},"native_amount":{"amount":"1.29","currency":"EUR"},"created_at":"2026-03-21T00:05:00Z"},
                {"id":"t3","type":"send","status":"completed","amount":{"amount":"-0.00100000","currency":"BTC"},"native_amount":{"amount":"-61.23","currency":"EUR"},"created_at":"2026-03-20T13:13:35Z","network":{"transaction_fee":{"amount":"0.00001200","currency":"BTC"}}},
                {"id":"t2","type":"advanced_trade_fill","status":"completed","amount":{"amount":"0.00150000","currency":"BTC"},"native_amount":{"amount":"91.85","currency":"EUR"},"created_at":"2026-03-14T09:59:59Z","advanced_trade_fill":{"fill_price":"61234.56","product_id":"BTC-EUR","order_id":"o1","commission":"0.55","order_side":"BUY"}},
                {"id":"t1","type":"buy","status":"completed","amount":{"amount":"0.01","currency":"BTC"},"native_amount":{"amount":"600.00","currency":"EUR"},"created_at":"2026-03-01T08:00:00Z"},
                {"id":"t0","type":"buy","status":"completed","amount":{"amount":"0.01","currency":"BTC"},"native_amount":{"amount":"500.00","currency":"EUR"},"created_at":"2026-01-01T08:00:00Z"}]}',
        ]);
        $client = new CoinbaseClient($http);
        $test = $client->test($creds);
        self::assertTrue($test['ok']);
        self::assertStringContainsString('Transfer', $test['warnings'][0]);

        $d = $client->fetch($creds, new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-04-01'));
        self::assertSame([
            ['reward', 'BTC', '0.000021', '1.29', 'EUR', null],
            ['send', 'BTC', '0.001', null, 'EUR', null],
            ['buy', 'BTC', '0.0015', '91.85', 'EUR', '0.55'],
            ['buy', 'BTC', '0.01', '600', 'EUR', null],
        ], self::brief($d));
        self::assertFalse(array_filter($http->requests, static fn (array $r) => str_contains($r['url'], 'acc-eur')) !== [], 'fiat účet se nestahuje');

        // JWT: ES256 podpis platný pro veřejný klíč, claims dle CDP
        [$h, $p, $s] = explode('.', substr($http->requests[0]['headers']['Authorization'], 7));
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        self::assertSame(['cdp', 'organizations/synthetic/apiKeys/key-1', 'GET api.coinbase.com/api/v3/brokerage/key_permissions'],
            [$claims['iss'], $claims['sub'], $claims['uri']]);
        $raw = base64_decode(strtr($s, '-_', '+/'));
        $int = static fn (string $b) => "\x02" . chr(strlen($b = (ord($b[0]) & 0x80 ? "\x00" : '') . ltrim($b, "\x00"))) . $b;
        $der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        self::assertSame(1, openssl_verify("$h.$p", "\x30" . chr(strlen($der)) . $der, openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));
    }
}
