<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Import;

use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Service\Import\Parser\DegiroParser;
use MyPortfolio\Service\Import\Parser\InteractiveBrokersParser;
use MyPortfolio\Service\Import\Parser\RevolutParser;
use MyPortfolio\Service\Import\Parser\Trading212Parser;
use MyPortfolio\Service\Import\Parser\XtbParser;
use PHPUnit\Framework\TestCase;

/** Syntetické exporty brokerů ve formátu zdroje (hodnoty, ISINy a ID smyšlené nebo veřejné kódy instrumentů). */
final class BrokerParsersTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Prague');
    }

    /** @param list<Draft> $drafts @return list<array<int,mixed>> */
    private static function brief(array $drafts): array
    {
        return array_map(static fn (Draft $d) => [$d->kind, $d->symbol !== '' ? $d->symbol : (string) $d->isin, $d->quantity ?? $d->splitRatio,
            $d->amount, $d->currency, $d->fee], $drafts);
    }

    public function testXtbXlsxWithProfitRowsAndWithholdingTax(): void
    {
        $xlsx = TabularReaderTest::xlsx(['CASH OPERATION HISTORY' => [
            ['Name and surname', 'Account', 'Currency', 'Balance'],
            ['Test User', '00000000', 'CZK', 1000],
            [],
            ['ID', 'Type', 'Time', 'Comment', 'Symbol', 'Amount'],
            [1001, 'Stock purchase', 45292.4375, 'OPEN BUY 10 @ 101.50', 'VWCE.DE', -24500.5],
            [1002, 'Stock sale', 45323.5, 'CLOSE BUY 4/10 @ 110', 'VWCE.DE', 9800],
            [1003, 'close trade', 45323.5, 'Profit of position #1001', 'VWCE.DE', 700],
            [1004, 'DIVIDENT', 45330.5, 'AAPL.US USD 0.2400/ SHR', 'AAPL.US', 55.2],
            [1005, 'Withholding Tax', 45330.5, 'AAPL.US USD WHT 15%', 'AAPL.US', -8.28],
            [1006, 'deposit', 45290.4, 'Deposit', '', 50000],
            ['Total', '', '', '', '', 82546.42],
        ]]);
        $d = CryptoParsersTest::parse(new XtbParser(), $xlsx, 'xtb.xlsx');
        self::assertSame([
            ['buy', 'VWCE', '10', '24500.5', 'CZK', null],
            ['sell', 'VWCE', '4', '10500', 'CZK', null],
            ['skip', '', null, null, null, null],
            ['dividend', 'AAPL', null, '55.2', 'CZK', '8.28'],
        ], self::brief($d));
        self::assertSame('2024-01-01 10:30:00', $d[0]->executedAt);
        self::assertSame('EUR', $d[0]->quoteCurrency);
        self::assertSame('1001', $d[0]->externalId);
    }

    public function testXtbLegacyCsv(): void
    {
        $csv = "ID;Type;Time;Symbol;Comment;Amount\n2001;Stock purchase;02.01.2024 10:00:00;CEZ.CZ;OPEN BUY 5 @ 900;-4500\n";
        $d = CryptoParsersTest::parse(new XtbParser(), $csv);
        self::assertSame([['buy', 'CEZ', '5', '4500', null, null]], self::brief($d));
        self::assertSame('CZK', $d[0]->quoteCurrency);
    }

    public function testTrading212(): void
    {
        $csv = "Action,Time,ISIN,Ticker,Name,No. of shares,Price / share,Currency (Price / share),Exchange rate,Result,Currency (Result),Total,Currency (Total),Withholding tax,Currency (Withholding tax),Notes,ID,Currency conversion fee,Currency (Currency conversion fee)\n"
            . "Deposit,2024-01-30 09:00:00,,,,,,,,,,10000.00,CZK,,,,dep1,,\n"
            . "Market buy,2024-02-01 15:30:00,US0378331005,AAPL,\"Apple Inc.\",2,180.00,USD,0.0435,,,\"8,295.40\",CZK,,,,EOF100001,12.40,CZK\n"
            . "Dividend (Dividend),2024-02-15 12:00:00,US0378331005,AAPL,\"Apple Inc.\",2,0.204,USD,0.043,,,9.49,CZK,0.072,USD,,,,\n"
            . "Limit sell,2024-03-01 16:00:00,US0378331005,AAPL,\"Apple Inc.\",1,190.00,USD,0.0430,200.00,CZK,4400.00,CZK,,,,EOF100002,6.60,CZK\n"
            . "Stock split close,2024-06-10 06:00:00,US0000000001,XYZ,\"XYZ Corp\",3,50.00,USD,,,,,,,,,,,\n"
            . "Stock split open,2024-06-10 06:00:00,US0000000001,XYZ,\"XYZ Corp\",30,5.00,USD,,,,,,,,,,,\n";
        $d = CryptoParsersTest::parse(new Trading212Parser(), $csv);
        self::assertSame([
            ['skip', '', null, null, null, null],
            ['buy', 'AAPL', '2', '8283', 'CZK', '12.4'],
            ['dividend', 'AAPL', null, '0.48', 'USD', '0.072'],
            ['sell', 'AAPL', '1', '4406.6', 'CZK', '6.6'],
            ['split', 'XYZ', '10', null, null, null],
        ], self::brief($d));
        self::assertSame('2024-02-01 16:30:00', $d[1]->executedAt);
        self::assertSame(['US0378331005', 'USD', 'EOF100001'], [$d[1]->isin, $d[1]->quoteCurrency, $d[1]->externalId]);
    }

    public function testRevolut(): void
    {
        $csv = "Date,Ticker,Type,Quantity,Price per share,Total Amount,Currency,FX Rate\n"
            . "2024-01-10T15:31:02.123Z,TSLA,BUY - MARKET,0.5,USD 240.00,USD 120.00,USD,1.10\n"
            . "2024-02-10T15:31:02Z,TSLA,SELL - LIMIT,0.25,\$260.00,\$65.00,USD,1.09\n"
            . "2024-03-01T10:00:00Z,MSFT,DIVIDEND,,,USD 0.53,USD,1.08\n"
            . "2024-03-01T10:00:00Z,,CASH TOP-UP,,,\"USD 1,000\",USD,1.08\n"
            . "2024-04-01T10:00:00Z,TSLA,STOCK SPLIT,1,,USD 0,USD,1.08\n";
        $d = CryptoParsersTest::parse(new RevolutParser(), $csv);
        self::assertSame([
            ['buy', 'TSLA', '0.5', '120', 'USD', null],
            ['sell', 'TSLA', '0.25', '65', 'USD', null],
            ['dividend', 'MSFT', null, '0.53', 'USD', null],
            ['skip', '', null, null, null, null],
            ['skip', 'TSLA', null, null, null, null],
        ], self::brief($d));
        self::assertSame('2024-01-10 16:31:02', $d[0]->executedAt);
        self::assertNotSame($d[0]->externalId, $d[1]->externalId);
    }

    public function testDegiroTransactionsAndAccountInZip(): void
    {
        $tx = "Date,Time,Product,ISIN,Reference exchange,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third,,Total,,Order ID\n"
            . "15-01-2024,09:05,VANGUARD FTSE AW,IE00B3RBWM25,EAM,XAMS,5,\"105,20\",EUR,\"-526,00\",EUR,\"-526,00\",EUR,,\"-2,00\",EUR,\"-528,00\",EUR,aaaa-1111\n"
            . "20-02-2024,14:10,VANGUARD FTSE AW,IE00B3RBWM25,EAM,XAMS,-2,\"110,00\",EUR,\"220,00\",EUR,\"220,00\",EUR,,\"-1,00\",EUR,\"219,00\",EUR,bbbb-2222\n"
            . "01-03-2024,15:45,APPLE INC,US0378331005,NDQ,XNAS,3,\"170,00\",USD,\"-510,00\",USD,\"-470,10\",EUR,\"1,0849\",\"-1,50\",EUR,\"-471,60\",EUR,cccc-3333\n"
            . "10-06-2024,07:00,XYZ CORP,US1111111111,NDQ,XNAS,-3,\"50,00\",USD,\"-150,00\",USD,\"-138,00\",EUR,\"1,0870\",,,\"-138,00\",EUR,\n"
            . "10-06-2024,07:00,XYZ CORP,US1111111111,NDQ,XNAS,30,\"5,00\",USD,\"150,00\",USD,\"138,00\",EUR,\"1,0870\",,,\"138,00\",EUR,\n";
        $account = "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
            . "02-04-2024,07:30,01-04-2024,APPLE INC,US0378331005,Dividend Tax,,USD,\"-0,36\",USD,\"2,04\",\n"
            . "02-04-2024,07:30,01-04-2024,APPLE INC,US0378331005,Dividend,,USD,\"2,40\",USD,\"2,40\",\n"
            . "15-01-2024,09:05,15-01-2024,VANGUARD FTSE AW,IE00B3RBWM25,Buy 5 VANGUARD FTSE AW@105.2 EUR,,EUR,\"-526,00\",EUR,\"474,00\",aaaa-1111\n";
        $tmp = tempnam(sys_get_temp_dir(), 'mp-test-zip-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('Transactions.csv', $tx);
        $zip->addFromString('Account.csv', $account);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        $d = CryptoParsersTest::parse(new DegiroParser(), $bytes, 'degiro.zip');
        $byKind = [];
        foreach (self::brief($d) as $b) {
            $byKind[] = $b;
        }
        self::assertEqualsCanonicalizing([
            ['buy', 'IE00B3RBWM25', '5', '526', 'EUR', '2'],
            ['sell', 'IE00B3RBWM25', '2', '220', 'EUR', '1'],
            ['buy', 'US0378331005', '3', '470.1', 'EUR', '1.5'],
            ['split', 'US1111111111', '10', null, null, null],
            ['skip', 'IE00B3RBWM25', null, null, null, null],
            ['dividend', 'US0378331005', null, '2.4', 'USD', '0.36'],
        ], $byKind);
        $apple = array_values(array_filter($d, static fn (Draft $x) => $x->kind === 'buy' && $x->isin === 'US0378331005'))[0];
        self::assertSame(['USD', '2024-03-01 15:45:00', 'APPLE INC'], [$apple->quoteCurrency, $apple->executedAt, $apple->name]);
    }

    public function testInteractiveBrokersActivityStatement(): void
    {
        $csv = "Statement,Header,Field Name,Field Value\n"
            . "Statement,Data,BrokerName,Interactive Brokers\n"
            . "Account Information,Header,Field Name,Field Value\n"
            . "Account Information,Data,Base Currency,USD\n"
            . "Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code\n"
            . "Trades,Data,Order,Stocks,USD,VOO,\"2024-01-16, 10:30:00\",10,430.5,431,-4305,-1,4306,0,5,O\n"
            . "Trades,Data,Order,Stocks,USD,VOO,\"2024-03-01, 11:00:00\",-4,460,460,1840,-1.0005,-1722.4,116.6,0,C\n"
            . "Trades,SubTotal,,Stocks,USD,VOO,,6,,,-2465,-2.0005,,,,\n"
            . "Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,,Proceeds,Comm in USD,,,MTM in USD,Code\n"
            . "Trades,Data,Order,Forex,USD,EUR.USD,\"2024-01-15, 09:00:00\",-1000,1.09,,1090,-2,,,0,\n"
            . "Dividends,Header,Currency,Date,Description,Amount\n"
            . "Dividends,Data,USD,2024-03-28,VOO(US9229083632) Cash Dividend USD 1.50 per Share (Ordinary Dividend),15\n"
            . "Dividends,Data,Total,,,15\n"
            . "Withholding Tax,Header,Currency,Date,Description,Amount,Code\n"
            . "Withholding Tax,Data,USD,2024-03-28,VOO(US9229083632) Cash Dividend USD 1.50 per Share - US Tax,-2.25,\n"
            . "Corporate Actions,Header,Asset Category,Currency,Report Date,Date/Time,Description,Quantity,Proceeds,Value,Realized P/L,Code\n"
            . "Corporate Actions,Data,Stocks,USD,2024-06-10,\"2024-06-07, 20:25:00\",\"NVDA(US67066G1040) Split 10 for 1 (NVDA, NVIDIA CORP, US67066G1040)\",90,0,0,0,\n"
            . "Financial Instrument Information,Header,Asset Category,Symbol,Description,Conid,Security ID,Listing Exch,Multiplier,Type,Code\n"
            . "Financial Instrument Information,Data,Stocks,VOO,VANGUARD S&P 500 ETF,136155102,US9229083632,ARCA,1,ETF,\n"
            . "Deposits & Withdrawals,Header,Currency,Settle Date,Description,Amount\n"
            . "Deposits & Withdrawals,Data,USD,2024-01-10,Electronic Fund Transfer,5000\n";
        $d = CryptoParsersTest::parse(new InteractiveBrokersParser(), $csv);
        self::assertSame([
            ['buy', 'VOO', '10', '4305', 'USD', '1'],
            ['sell', 'VOO', '4', '1840', 'USD', '1.0005'],
            ['skip', 'EUR.USD', null, null, null, null],
            ['dividend', 'VOO', null, '15', 'USD', '2.25'],
            ['split', 'NVDA', '10', null, null, null],
            ['skip', '', null, null, null, null],
        ], self::brief($d));
        self::assertSame(['2024-01-16 16:30:00', 'US9229083632', 'VANGUARD S&P 500 ETF'], [$d[0]->executedAt, $d[0]->isin, $d[0]->name]);
        self::assertSame('US67066G1040', $d[4]->isin);
    }

    public function testInteractiveBrokersFlexQuery(): void
    {
        $csv = "\"ClientAccountID\",\"AssetClass\",\"Symbol\",\"Description\",\"ISIN\",\"DateTime\",\"Quantity\",\"TradePrice\",\"Proceeds\",\"IBCommission\",\"CurrencyPrimary\",\"TransactionID\",\"Buy/Sell\"\n"
            . "\"U0000000\",\"STK\",\"VOO\",\"VANGUARD S&P 500 ETF\",\"US9229083632\",\"20240116;103000\",\"10\",\"430.5\",\"-4305\",\"-1\",\"USD\",\"9000001\",\"BUY\"\n"
            . "\"ClientAccountID\",\"CurrencyPrimary\",\"Symbol\",\"ISIN\",\"Description\",\"DateTime\",\"Amount\",\"Type\",\"TransactionID\"\n"
            . "\"U0000000\",\"USD\",\"VOO\",\"US9229083632\",\"VOO(US9229083632) CASH DIVIDEND USD 1.50 PER SHARE\",\"20240328\",\"15\",\"Dividends\",\"9000002\"\n"
            . "\"U0000000\",\"USD\",\"VOO\",\"US9229083632\",\"VOO(US9229083632) CASH DIVIDEND - US TAX\",\"20240328\",\"-2.25\",\"Withholding Tax\",\"9000003\"\n"
            . "\"U0000000\",\"USD\",\"\",\"\",\"Deposit\",\"20240110\",\"5000\",\"Deposits/Withdrawals\",\"9000004\"\n";
        $d = CryptoParsersTest::parse(new InteractiveBrokersParser(), $csv);
        self::assertSame([
            ['buy', 'VOO', '10', '4305', 'USD', '1'],
            ['skip', '', null, null, null, null],
            ['dividend', 'VOO', null, '15', 'USD', '2.25'],
        ], self::brief($d));
        self::assertSame(['9000001', '2024-01-16 16:30:00'], [$d[0]->externalId, $d[0]->executedAt]);
    }
}
