<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Import;

use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Service\Import\ImportParsers;
use MyPortfolio\Service\Import\Parser\AnycoinParser;
use MyPortfolio\Service\Import\Parser\BinanceParser;
use MyPortfolio\Service\Import\Parser\CoinbaseParser;
use MyPortfolio\Service\Import\Parser\CoinmateParser;
use MyPortfolio\Service\Import\Parser\ImportParserInterface;
use MyPortfolio\Service\Import\Parser\KrakenParser;
use MyPortfolio\Service\Import\Reader\TabularReader;
use PHPUnit\Framework\TestCase;

/** Syntetické exporty krypto burz ve formátu zdroje (hodnoty i ID smyšlené). */
final class CryptoParsersTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Prague');
    }

    /** @return list<Draft> */
    public static function parse(ImportParserInterface $parser, string $content, string $name = 'export.csv'): array
    {
        $tables = (new TabularReader())->read($content, $name);
        self::assertTrue($parser->detect($tables), $parser->label() . ' nerozpoznal soubor');
        self::assertSame($parser->key(), (new ImportParsers())->detect($tables)?->key(), 'auto-detekce');
        return $parser->parse($tables);
    }

    /** @param list<Draft> $drafts @return list<array<int,mixed>> */
    private static function brief(array $drafts): array
    {
        return array_map(static fn (Draft $d) => [$d->kind, $d->symbol, $d->quantity, $d->amount, $d->currency, $d->fee], $drafts);
    }

    public function testCoinbaseV4(): void
    {
        $csv = "\xEF\xBB\xBFTransactions\nUser,Test User,00000000-0000-0000-0000-000000000000\n"
            . "ID,Timestamp,Transaction Type,Asset,Quantity Transacted,Price Currency,Price at Transaction,Subtotal,Total (inclusive of fees and/or spread),Fees and/or Spread,Notes\n"
            . "65a0000000000000000000a1,2025-03-02 09:15:11 UTC,Buy,BTC,0.00123456,EUR,€81000.00,€97.01,€100.00,€2.99,Bought 0.00123456 BTC for 100 EUR\n"
            . "65a0000000000000000000a2,2025-03-05 14:02:40 UTC,Buy,ETH,0.5,USD,\"\$2,400.00\",\"\$1,200.00\",\"\$1,214.95\",\$14.95,Bought 0.5 ETH for 1214.95 USD\n"
            . "65a0000000000000000000a3,2025-04-01 10:00:00 UTC,Sell,ETH,-0.25,EUR,€2200.00,€550.00,€541.80,€8.20,Sold 0.25000000 ETH for 541.80 EUR\n"
            . "65a0000000000000000000a5,2025-04-03 12:00:00 UTC,Advanced Trade Sell,SOL,-10,EUR,€120.00,-€1200.00,-€1192.80,€7.20,Sold 10 SOL for 1290.00 USDC on SOL-USDC at 129.6 USDC/SOL\n"
            . "65a0000000000000000000a6,2025-04-04 08:30:00 UTC,Convert,ETH,-0.1,EUR,€2000.00,€200.00,€203.00,,Converted 0.1 ETH to 214.5 USDC\n"
            . "65a0000000000000000000a7,2025-04-05 16:20:00 UTC,Send,BTC,-0.005,EUR,€80000.00,-€400.00,-€400.00,€0.00,Sent 0.005 BTC to bc1qexample\n"
            . "65a0000000000000000000a9,2025-04-07 06:06:14 UTC,Reward Income,USDC,0.002727,EUR,€0.923609,€0.00252,€0.00252,€0.00,Received 0.002727 USDC from Coinbase Rewards\n"
            . "65a0000000000000000000b1,2025-04-08 20:22:48 UTC,Staking Income,ATOM,0.001588522705,EUR,€2.526483358,€0.00401,€0.00401,€0.00,\n"
            . "65a0000000000000000000b3,2025-04-10 13:11:31 UTC,Deposit,EUR,500,EUR,€1.00,€500.00,€500.00,€0.00,Deposit from EXAMPLE BANK\n"
            . "65a0000000000000000000b5,2025-04-12 01:51:28 UTC,Retail Staking Transfer,ADA,100,EUR,€0.29,€29.00,€29.00,€0.00,\n"
            . "65a0000000000000000000b7,2025-04-13 01:00:00 UTC,Receive,USDC,100,EUR,€0.92,€92.00,€92.00,€0.00,Received 100.00 USDC from an external account\n";
        $d = self::parse(new CoinbaseParser(), $csv);
        self::assertSame([
            ['buy', 'BTC', '0.00123456', '97.01', 'EUR', '2.99'],
            ['buy', 'ETH', '0.5', '1200', 'USD', '14.95'],
            ['sell', 'ETH', '0.25', '550', 'EUR', '8.2'],
            ['sell', 'SOL', '10', '1200', 'EUR', '7.2'],
            ['buy', 'USDC', '1290', '1200', 'EUR', null],   // druhá strana Advanced Trade
            ['sell', 'ETH', '0.1', '200', 'EUR', null],
            ['buy', 'USDC', '214.5', '200', 'EUR', null],   // druhá strana Convert
            ['send', 'BTC', '0.005', null, 'EUR', null],
            ['reward', 'USDC', '0.002727', '0.00252', 'EUR', null],
            ['reward', 'ATOM', '0.001588522705', '0.00401', 'EUR', null],
            ['skip', 'EUR', null, null, null, null],
            ['skip', 'ADA', null, null, null, null],
            ['receive', 'USDC', '100', null, 'EUR', null],
        ], self::brief($d));
        self::assertSame(['2025-03-02 10:15:11', '65a0000000000000000000a1'], [$d[0]->executedAt, $d[0]->externalId]);
        self::assertSame('65a0000000000000000000a6:to', $d[6]->externalId);
    }

    public function testCoinbaseV3WithoutIdAndScientificQuantity(): void
    {
        $csv = "\"You can use this transaction report to inform your likely tax obligations.\"\n\n\n\nTransactions\nUser,Demo,1\n\n"
            . "Timestamp,Transaction Type,Asset,Quantity Transacted,Spot Price Currency,Spot Price at Transaction,Subtotal,Total (inclusive of fees and/or spread),Fees and/or Spread,Notes\n"
            . "2023-05-19T18:14:56Z,Rewards Income,ETH2,2.103e-05,EUR,1682.84,0.040000,0.040000,0.00,Received 0.00002103 ETH2 from Coinbase Rewards\n"
            . "2023-08-19T17:23:39Z,Send,BTC,0.00205323,EUR,24073.28,\"\",\"\",\"\",Sent 0.00205323 BTC to bc1qexample\n";
        $d = self::parse(new CoinbaseParser(), $csv);
        self::assertSame([['reward', 'ETH2', '0.00002103', '0.04', 'EUR', null], ['send', 'BTC', '0.00205323', null, 'EUR', null]], self::brief($d));
        self::assertSame(32, strlen($d[0]->externalId));
        self::assertNotSame($d[0]->externalId, $d[1]->externalId);
    }

    public function testCoinbaseProAccountStatement(): void
    {
        $csv = "portfolio,type,time,amount,balance,amount/balance unit,transfer id,trade id,order id\n"
            . "default,deposit,2021-03-01T10:00:00.000Z,500.0000000000000000,500.0000000000000000,EUR,t-0001,,\n"
            . "default,match,2021-03-02T10:00:00.100Z,-400.0000000000000000,100.0000000000000000,EUR,,1001,o-0001\n"
            . "default,match,2021-03-02T10:00:00.100Z,0.0100000000000000,0.0100000000000000,BTC,,1001,o-0001\n"
            . "default,fee,2021-03-02T10:00:00.100Z,-2.0000000000000000,98.0000000000000000,EUR,,1001,o-0001\n"
            . "default,match,2021-03-03T10:00:00.200Z,-0.0040000000000000,0.0060000000000000,BTC,,1002,o-0002\n"
            . "default,match,2021-03-03T10:00:00.200Z,200.0000000000000000,298.0000000000000000,EUR,,1002,o-0002\n"
            . "default,fee,2021-03-03T10:00:00.200Z,-1.0000000000000000,297.0000000000000000,EUR,,1002,o-0002\n"
            . "default,match,2021-03-04T10:00:00.300Z,-50.0000000000000000,10.0000000000000000,USDT,,1001,o-0003\n"
            . "default,match,2021-03-04T10:00:00.300Z,0.0010000000000000,0.0070000000000000,BTC,,1001,o-0003\n"
            . "default,fee,2021-03-04T10:00:00.300Z,-0.2500000000000000,9.7500000000000000,USDT,,1001,o-0003\n"
            . "default,match,2021-03-05T10:00:00.400Z,-1000.0000000000000000,0.0000000000000000,DOGE,,2001,o-0004\n"
            . "default,match,2021-03-05T10:00:00.400Z,0.0030000000000000,0.0100000000000000,BTC,,2001,o-0004\n"
            . "default,withdrawal,2021-03-06T10:00:00.000Z,-0.0100000000000000,0.0000000000000000,BTC,t-0002,,\n";
        $d = self::parse(new CoinbaseParser(), $csv);
        $brief = array_map(static fn (Draft $x) => [$x->kind, $x->symbol, $x->quantity, $x->amount, $x->currency, $x->fee, $x->feeCurrency, $x->externalId], $d);
        self::assertSame([
            ['skip', 'EUR', null, null, null, null, null, 'pro:t-0001'],
            ['buy', 'BTC', '0.01', '400', 'EUR', '2', 'EUR', 'pro:o-0001:1001'],
            ['sell', 'BTC', '0.004', '200', 'EUR', '1', 'EUR', 'pro:o-0002:1002'],
            ['buy', 'BTC', '0.001', '50', 'USD', '0.25', 'USDT', 'pro:o-0003:1001'],
            ['sell', 'USDT', '50', '50', 'USD', null, null, 'pro:o-0003:1001:paid'],
            ['skip', 'BTC', null, null, null, null, null, 'pro:o-0004:2001'],
            ['send', 'BTC', '0.01', null, null, null, null, 'pro:t-0002'],
        ], $brief);
        self::assertSame('2021-03-02 11:00:00', $d[1]->executedAt);
    }

    public function testAnycoinGroupsOrders(): void
    {
        $csv = "Date,Type,Amount,Currency,Order ID,anycoin TX ID,Description\n"
            . "2025-04-10T18:16:50.367Z,trade payment,-1000,CZK,900001,5000001,\n"
            . "2025-04-10T18:16:52.885Z,trade fill,0.00052,BTC,900001,5000002,\n"
            . "2025-05-02T08:46:44.616Z,trade payment,-52,ADA,900002,5000003,\n"
            . "2025-05-02T08:46:47.763Z,trade fill,912.40,CZK,900002,5000004,\n"
            . "2025-04-01T10:28:16.196Z,deposit,5000,CZK,,5000007,\n"
            . "2025-06-06T11:51:27.489Z,withdrawal,-0.0007,BTC,,5000008,\n"
            . "2025-06-06T06:11:24.727Z,withdrawal_block,-0.0007,BTC,,5000009,\n"
            . "2025-07-07T14:39:54.400Z,stake,-1.5,ATOM,,5000011,\n"
            . "2025-07-29T14:24:12.531Z,stake_reward,0.00334145,SOL.S,,5000013,\n";
        $d = self::parse(new AnycoinParser(), $csv);
        $byKind = array_map(static fn (Draft $x) => [$x->kind, $x->symbol, $x->quantity, $x->amount, $x->currency, $x->externalId], $d);
        self::assertContains(['buy', 'BTC', '0.00052', '1000', 'CZK', 'order:900001'], $byKind);
        self::assertContains(['sell', 'ADA', '52', '912.4', 'CZK', 'order:900002'], $byKind);
        self::assertContains(['send', 'BTC', '0.0007', null, null, '5000008'], $byKind);
        self::assertContains(['reward', 'SOL', '0.00334145', null, null, '5000013'], $byKind);
        self::assertSame(3, count(array_filter($d, static fn (Draft $x) => $x->kind === 'skip')));
        $buy = array_values(array_filter($d, static fn (Draft $x) => $x->externalId === 'order:900001'))[0];
        self::assertSame('2025-04-10 20:16:52', $buy->executedAt); // čas fillu, UTC → Praha (letní čas)
    }

    public function testAnycoinOrdersExport(): void
    {
        $csv = "UID,DATE,SYMBOL,ACTION,QUANTY,PRICE,FEE,FEE_CURRENCY,REBATE,REBATE_CURRENCY,ADDRESS_FROM,ADDRESS_TO\n"
            . "100001,24. 2. 2022 6:16:23,BTC/CZK,BUY,0.05,800000.123456789,,,,,,\n"
            . "00000000-0000-4000-8000-000000000001,21. 7. 2026 18:31:42,ETH/CZK,SELL,0.5,60000,15,CZK,,,,\n"
            . "100003,1. 3. 2025 10:00:00,ETH/BTC,BUY,1,0.03,,,,,,\n"
            . "100004,2. 3. 2025 10:00:00,BTC/CZK,WITHDRAWAL,0.01,,,,,,,bc1qexample\n";
        $d = self::parse(new AnycoinParser(), $csv);
        self::assertSame([
            ['buy', 'BTC', '0.05', '40000.00617283945', 'CZK', null],
            ['sell', 'ETH', '0.5', '30000', 'CZK', '15'],
            ['skip', 'ETH', null, null, null, null],
            ['skip', 'BTC', null, null, null, null],
        ], self::brief($d));
        self::assertSame(['800000.123456789', '2022-02-24 06:16:23', 'order:100001'], [$d[0]->unitPrice, $d[0]->executedAt, $d[0]->externalId]);
        self::assertSame(['CZK', 'order:00000000-0000-4000-8000-000000000001', '2026-07-21 18:31:42'], [$d[1]->feeCurrency, $d[1]->externalId, $d[1]->executedAt]);
    }

    public function testCoinmateEnglishAndCzech(): void
    {
        $csv = "\xEF\xBB\xBFID;Date;Account;Type;Amount;Amount Currency;Price;Price Currency;Fee;Fee Currency;Total;Total Currency;Description;Status;First balance after;First balance after Currency;Second balance after;Second balance after Currency\n"
            . "90000001;2025-03-21 18:54:15;M;QUICK_BUY;0.01;BTC;2000000;CZK;70;CZK;-20070;CZK;;OK;0.01;BTC;930;CZK\n"
            . "90000003;2025-03-23 12:31:14;M;SELL;-0.5;LTC;90.5;EUR;0.1131;EUR;45.1369;EUR;;OK;1.5;LTC;105.0;EUR\n"
            . "90000004;2025-03-24 07:00:00;M;BUY;1.2;ETH;0.035;BTC;0.0000042;BTC;-0.0420042;BTC;;OK;1.2;ETH;0.01;BTC\n"
            . "90000006;2025-03-26 06:39:40;M;WITHDRAWAL;-0.01;BTC; ; ;0.0001;BTC;-0.0101;BTC;bc1qexample;COMPLETED;0;BTC; ;\n"
            . "90000007;2025-03-27 08:00:00;M;DEPOSIT;10000;CZK; ; ;0;CZK;10000;CZK;Bank transfer;COMPLETED;10000;CZK; ;\n"
            . "90000011;2025-03-28 08:00:00;M;BUY;1;BTC;2000000;CZK;0;CZK;-2000000;CZK;;CANCELED;0;BTC;0;CZK\n";
        $d = self::parse(new CoinmateParser(), $csv);
        self::assertSame([
            ['buy', 'BTC', '0.01', null, 'CZK', '70'],
            ['sell', 'LTC', '0.5', null, 'EUR', '0.1131'],
            ['skip', 'ETH', null, null, null, null],
            ['send', 'BTC', '0.01', null, null, '0.0001'],
            ['skip', 'CZK', null, null, null, null],
            ['skip', 'BTC', null, null, null, null],
        ], self::brief($d));
        self::assertSame(['2000000', '2025-03-21 18:54:15', '90000001:QUICK_BUY'], [$d[0]->unitPrice, $d[0]->executedAt, $d[0]->externalId]);

        $cz = "ID;Datum;Účet;Typ;Částka;Částka měny;Cena;Cena měny;Poplatek;Poplatek měny;Celkem;Celkem měny;Popisek;Status;První zůstatek po;První zůstatek po měně;Druhý zůstatek po;Druhý zůstatek po měně\n"
            . "90000010;16.08.2025 9:42;M;AFFILIATE;0.0001;BTC;;;;;0.0001;BTC;User: example;OK;0.1;BTC;;\n";
        $c = self::parse(new CoinmateParser(), $cz);
        self::assertSame([['reward', 'BTC', '0.0001', null, null, null]], self::brief($c));
        self::assertSame('2025-08-16 09:42:00', $c[0]->executedAt);
    }

    public function testBinanceGroupsTradesAndValuesStablecoinPairsInUsd(): void
    {
        $csv = "User_ID,UTC_Time,Account,Operation,Coin,Change,Remark\n"
            . "100000001,2025-03-10 08:00:01,Spot,Fiat Deposit,EUR,1000.00000000,\n"
            . "100000001,2025-03-14 09:12:45,Spot,Transaction Spend,EUR,-150.00000000,\n"
            . "100000001,2025-03-14 09:12:45,Spot,Transaction Buy,BTC,0.00187000,\n"
            . "100000001,2025-03-14 09:12:45,Spot,Transaction Fee,BNB,-0.00025000,\n"
            . "100000001,2025-03-14 09:12:45,Spot,Transaction Buy,BTC,0.00125000,\n"
            . "100000001,2025-03-14 09:12:45,Spot,Transaction Spend,EUR,-100.00000000,\n"
            . "100000001,2025-03-14 09:12:45,Spot,Transaction Fee,BNB,-0.00016000,\n"
            . "100000001,25-04-02 14:03:09,Spot,Transaction Sold,ETH,-0.50000000,\n"
            . "100000001,25-04-02 14:03:09,Spot,Transaction Revenue,USDC,1012.35000000,\n"
            . "100000001,25-04-02 14:03:09,Spot,Transaction Fee,USDC,-1.01235000,\n"
            . "100000001,2025-01-04 11:20:13,Spot,Binance Convert,BTC,-0.001,\n"
            . "100000001,2025-01-04 11:20:13,Spot,Binance Convert,SOL,0.5,\n"
            . "100000001,2025-02-01 01:00:00,Earn,Simple Earn Flexible Interest,USDC,0.01234567,\n"
            . "100000001,2025-02-05 06:00:00,Spot,Simple Earn Flexible Subscription,USDC,-500.00000000,\n"
            . "100000001,2025-03-11 17:20:44,Spot,Withdraw,ETH,-0.25050000,Withdraw fee is included\n"
            . "100000001,2025-03-12 10:00:00,USD-MFutures,Realized Profit and Loss,USDT,12.5,\n";
        $d = self::parse(new BinanceParser(), $csv);
        $brief = self::brief($d);
        self::assertContains(['buy', 'BTC', '0.00312', '250', 'EUR', '0.00041'], $brief);
        self::assertContains(['sell', 'ETH', '0.5', '1012.35', 'USD', null], $brief);       // stablecoin → USD
        self::assertContains(['buy', 'USDC', '1012.35', '1012.35', 'USD', '1.01235'], $brief); // a příjem USDC
        self::assertContains(['skip', 'SOL', null, null, null, null], $brief);               // BTC → SOL krypto za krypto
        self::assertContains(['reward', 'USDC', '0.01234567', null, null, null], $brief);
        self::assertContains(['send', 'ETH', '0.2505', null, null, null], $brief);
        self::assertSame(4, count(array_filter($d, static fn (Draft $x) => $x->kind === 'skip')));
        $sell = array_values(array_filter($d, static fn (Draft $x) => $x->kind === 'sell'))[0];
        self::assertSame('2025-04-02 16:03:09', $sell->executedAt);
    }

    public function testKrakenLedgerInZip(): void
    {
        $csv = "\"txid\",\"refid\",\"time\",\"type\",\"subtype\",\"aclass\",\"subclass\",\"asset\",\"wallet\",\"amount\",\"fee\",\"balance\"\n"
            . "\"LQ7ZK2-ABCDE-F1G2H3\",\"FTa1b2\",\"2025-02-14 20:32:16\",\"deposit\",\"\",\"currency\",\"fiat\",\"EUR\",\"spot / main\",500.0000,0,500.0000\n"
            . "\"LR8YJ3-BCDEF-G2H3I4\",\"TQWERT-YUIOP-ASDFGH\",\"2025-02-14 22:18:09\",\"trade\",\"tradespot\",\"currency\",\"fiat\",\"EUR\",\"spot / main\",-250.0000,1.0000,249.0000\n"
            . "\"LS9XH4-CDEFG-H3I4J5\",\"TQWERT-YUIOP-ASDFGH\",\"2025-02-14 22:18:09\",\"trade\",\"tradespot\",\"currency\",\"crypto\",\"XXBT\",\"spot / main\",0.0029850746,0,0.0029850746\n"
            . "\"LV3UE7-FGHIJ-K6L7M8\",\"FTz9Y8\",\"2025-03-05 09:00:00\",\"withdrawal\",\"\",\"currency\",\"crypto\",\"BTC\",\"spot / main\",-0.0020000000,0.0000200000,0.0009650746\n"
            . "\"LY6RB1-IJKLM-N9O1P2\",\"VAQWER-TYUIO-PASDFG\",\"2025-03-07 08:00:00\",\"earn\",\"allocation\",\"currency\",\"crypto\",\"DOT\",\"spot / main\",-10.0000000000,0,0.0000000000\n"
            . "\"LA8PZ3-KLMNO-P2Q3R4\",\"ELASDF-GHJKL-ZXCVBN\",\"2025-03-14 21:20:34\",\"earn\",\"reward\",\"currency\",\"crypto\",\"DOT28.S\",\"earn / flexible\",0.0125000000,0.0025000000,10.0100000000\n";
        $tmp = tempnam(sys_get_temp_dir(), 'kraken');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('ledgers.csv', $csv);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        $d = self::parse(new KrakenParser(), $bytes, 'kraken_export.zip');
        $brief = self::brief($d);
        self::assertContains(['buy', 'BTC', '0.0029850746', '250', 'EUR', '1'], $brief);
        self::assertContains(['send', 'BTC', '0.002', null, null, '0.00002'], $brief);
        self::assertContains(['reward', 'DOT', '0.01', null, null, null], $brief);   // hrubá odměna − provize Krakenu
        self::assertSame(2, count(array_filter($d, static fn (Draft $x) => $x->kind === 'skip')));
        $buy = array_values(array_filter($d, static fn (Draft $x) => $x->kind === 'buy'))[0];
        self::assertSame(['TQWERT-YUIOP-ASDFGH', '2025-02-14 23:18:09'], [$buy->externalId, $buy->executedAt]);
    }
}
