<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Price;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Fx\CnbFxProvider;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Service\Price\CoinGeckoProvider;
use MyPortfolio\Service\Price\XtbProvider;
use MyPortfolio\Service\Price\XtbWidgetTransport;
use MyPortfolio\Service\Price\YahooProvider;
use MyPortfolio\Tests\Support\FakeHttpFetcher;
use PHPUnit\Framework\TestCase;

final class MarketProvidersTest extends TestCase
{
    private function config(): Config
    {
        return new Config(['prices' => [
            'cnb'       => ['daily_url' => 'https://cnb.example.invalid/denni_kurz.txt'],
            'coingecko' => ['api' => 'https://cg.example.invalid/api/v3', 'api_key' => '', 'min_interval_ms' => 0],
            'yahoo'     => ['chart_api' => 'https://yahoo.example.invalid/v8/finance/chart'],
            'xtb'       => ['instruments_api' => 'https://xtb.example.invalid/instruments', 'websocket_url' => 'wss://x.example.invalid'],
        ]]);
    }

    /** @return array<string,mixed> */
    private function instrument(array $override = []): array
    {
        return $override + [
            'id' => 1, 'asset_class' => 'crypto', 'symbol' => 'BTC', 'quote_currency' => 'EUR',
            'price_source' => 'coingecko', 'source_refs' => ['coingecko' => 'bitcoin'],
        ];
    }

    public function testCnbDailySheetUsesHeaderDateAndKeepsDecimalsAsText(): void
    {
        $sheet = CnbFxProvider::parse(FakeHttpFetcher::fixture('cnb_daily.txt'));
        self::assertSame('2026-09-25', $sheet['date']);
        self::assertSame(['currency' => 'HUF', 'amount' => 100, 'rate' => '6.663'], $sheet['rates'][1]);
        self::assertSame('24.350', $sheet['rates'][0]['rate']);
    }

    public function testCnbYearFileHandlesRepeatedHeader(): void
    {
        $sheets = CnbFxProvider::parseYear(FakeHttpFetcher::fixture('cnb_year.txt'));
        self::assertCount(3, $sheets);
        self::assertSame('2024-01-02', $sheets[0]['date']);
        self::assertCount(2, $sheets[0]['rates']);
        self::assertSame(['currency' => 'HUF', 'amount' => 100, 'rate' => '6.470'], $sheets[2]['rates'][2]);
    }

    public function testCnbRejectsGarbage(): void
    {
        $this->expectException(MarketDataException::class);
        CnbFxProvider::parse('<html>maintenance</html>');
    }

    public function testCoinGeckoLatestAndHistory(): void
    {
        $http = new FakeHttpFetcher([
            '/simple/price'        => FakeHttpFetcher::fixture('coingecko_simple.json'),
            '/market_chart/range'  => FakeHttpFetcher::fixture('coingecko_range.json'),
        ]);
        $provider = new CoinGeckoProvider($http, $this->config());

        $latest = $provider->latest($this->instrument());
        self::assertSame('72874.12', (string) $latest->close);
        self::assertSame('EUR', $latest->currency);
        self::assertStringContainsString('vs_currencies=eur', $http->requested[0]);

        // Období podle bodů ve fixture (ne podle dnešního data — test nesmí časem začít padat).
        $from = (new \DateTimeImmutable('@1790467200'))->modify('-2 days');
        $history = $provider->history($this->instrument(), $from, (new \DateTimeImmutable('@1790592000'))->modify('+1 day'));
        $byDate = [];
        foreach ($history as $q) {
            $byDate[$q->date] = (string) $q->close;
        }
        // Poslední bod dne vyhrává (1790592000000 je týž UTC den jako 1790553600000).
        self::assertSame('72874.12', $byDate[gmdate('Y-m-d', 1790592000)] ?? null);
        self::assertSame('71000.5', $byDate[gmdate('Y-m-d', 1790467200)] ?? null);
    }

    public function testCoinGeckoHistoryIsClampedTo365Days(): void
    {
        $http = new FakeHttpFetcher(['/market_chart/range' => '{"prices":[]}']);
        $provider = new CoinGeckoProvider($http, $this->config());
        $provider->history($this->instrument(), new \DateTimeImmutable('2021-01-01'), new \DateTimeImmutable('today'));

        parse_str((string) parse_url($http->requested[0], PHP_URL_QUERY), $q);
        $oldest = (new \DateTimeImmutable('today'))->modify('-364 days')->getTimestamp();
        self::assertSame($oldest, (int) $q['from']);

        self::assertSame([], $provider->history($this->instrument(), new \DateTimeImmutable('2021-01-01'), new \DateTimeImmutable('2021-12-31')));
        self::assertCount(1, $http->requested, 'Rozsah celý mimo 365 dní se vůbec nedotazuje.');
    }

    public function testCoinGeckoErrorBodyIsFailure(): void
    {
        $http = new FakeHttpFetcher(['/simple/price' => '{"status":{"error_code":429,"error_message":"Rate limit"}}']);
        $this->expectException(MarketDataException::class);
        (new CoinGeckoProvider($http, $this->config()))->latest($this->instrument());
    }

    public function testYahooHistorySkipsNullsAndUsesExchangeOffset(): void
    {
        $http = new FakeHttpFetcher(['/chart/CSPX.L' => FakeHttpFetcher::fixture('yahoo_chart.json')]);
        $provider = new YahooProvider($http, $this->config());
        $etf = ['id' => 2, 'asset_class' => 'etf', 'symbol' => 'CSPX', 'quote_currency' => 'USD', 'price_source' => 'xtb', 'source_refs' => ['yahoo' => 'CSPX.L']];

        $history = $provider->history($etf, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
        self::assertCount(2, $history);
        self::assertSame(gmdate('Y-m-d', 1790406000 + 3600), $history[0]->date);
        self::assertSame('828.1', (string) $history[0]->close);
        self::assertSame('USD', $history[1]->currency);

        self::assertSame('830.66', (string) $provider->latest($etf)->close);
    }

    public function testYahooDefaultCryptoTickerAndPenceConversion(): void
    {
        self::assertSame('BTC-EUR', YahooProvider::ticker($this->instrument()));
        self::assertNull(YahooProvider::ticker(['asset_class' => 'etf', 'symbol' => 'X', 'quote_currency' => 'EUR', 'source_refs' => []]));

        $http = new FakeHttpFetcher(['/chart/VUSA.L' => FakeHttpFetcher::fixture('yahoo_pence.json')]);
        $quote = (new YahooProvider($http, $this->config()))->latest(['asset_class' => 'etf', 'symbol' => 'VUSA', 'quote_currency' => 'GBP', 'source_refs' => ['yahoo' => 'VUSA.L']]);
        self::assertSame('GBP', $quote->currency);
        self::assertSame('93.455', (string) $quote->close);
    }

    public function testYahooNotFoundIsFailure(): void
    {
        $http = new FakeHttpFetcher(['/chart/' => FakeHttpFetcher::fixture('yahoo_not_found.json')]);
        $this->expectException(MarketDataException::class);
        (new YahooProvider($http, $this->config()))->latest(['asset_class' => 'etf', 'symbol' => 'X', 'quote_currency' => 'EUR', 'source_refs' => ['yahoo' => 'X.DE']]);
    }

    public function testXtbResolvesMetadataAndWidgetConfig(): void
    {
        $http = new FakeHttpFetcher([
            'instruments?query=CSPX' => FakeHttpFetcher::fixture('xtb_instruments.json'),
            '/cz/etf/synthetic-sp500' => FakeHttpFetcher::fixture('xtb_page.html'),
        ]);
        $transport = new class implements XtbWidgetTransport {
            /** @var list<array<string,mixed>> */
            public array $calls = [];

            public function chart(array $config, int $assetClass, string $symbol, int $quoteId, int $startMs, int $endMs, int $intervalMs): array
            {
                $this->calls[] = compact('config', 'assetClass', 'symbol', 'quoteId', 'intervalMs');
                return [['time' => 1790546400000, 'close' => 829.5], ['time' => 1790589600000, 'close' => 830.85]];
            }
        };
        $provider = new XtbProvider($http, $transport, $this->config());
        $etf = ['id' => 2, 'asset_class' => 'etf', 'symbol' => 'CSPX', 'quote_currency' => 'USD', 'price_source' => 'xtb', 'source_refs' => ['xtb' => 'CSPX.UK']];

        $latest = $provider->latest($etf);
        self::assertSame('830.85', (string) $latest->close);
        self::assertSame('USD', $latest->currency);
        self::assertSame(9, $transport->calls[0]['assetClass']);
        self::assertSame('CSPX.UK', $transport->calls[0]['symbol']);
        self::assertSame(6, $transport->calls[0]['quoteId']);
        self::assertSame('synthetic-user', $transport->calls[0]['config']['user']);
        self::assertSame('wss://widget.example.invalid/widget', $transport->calls[0]['config']['url']);

        $provider->history($etf, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
        self::assertSame(86_400_000, $transport->calls[1]['intervalMs']);
        self::assertCount(2, $http->requested, 'Metadata a konfigurace widgetu se v rámci běhu cachují.');
    }

    public function testXtbUnknownInstrumentIsFailure(): void
    {
        $http = new FakeHttpFetcher(['instruments?query=' => '{"instrumentsCollectionLimited":{"etfs":{}}}']);
        $transport = $this->createStub(XtbWidgetTransport::class);
        $this->expectException(MarketDataException::class);
        (new XtbProvider($http, $transport, $this->config()))->latest(['asset_class' => 'etf', 'symbol' => 'VWCE', 'quote_currency' => 'EUR', 'source_refs' => ['xtb' => 'VWCE.DE']]);
    }
}
