<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Price;

use Brick\Math\RoundingMode;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Support\Decimal;

/**
 * Yahoo Finance chart API (neoficiální). Ticker = `source_refs.yahoo`; u krypta
 * výchozí `{SYMBOL}-{QUOTE}` (BTC-EUR). Pence (GBp/GBX) se převádí na GBP.
 */
final class YahooProvider implements PriceProvider
{
    public function __construct(
        private readonly HttpFetcher $http,
        private readonly Config $config,
    ) {}

    public function name(): string
    {
        return 'yahoo';
    }

    public function supports(array $instrument): bool
    {
        return self::ticker($instrument) !== null;
    }

    public function latest(array $instrument): PriceQuote
    {
        $result = $this->chart($instrument, 'range=5d&interval=1d');
        $meta = $result['meta'] ?? [];
        $price = $meta['regularMarketPrice'] ?? null;
        if (!is_int($price) && !is_float($price)) {
            throw new MarketDataException('Yahoo: chybí regularMarketPrice.');
        }
        $at = (int) ($meta['regularMarketTime'] ?? time()) + (int) ($meta['gmtoffset'] ?? 0);
        return self::quote(gmdate('Y-m-d', $at), $price, (string) ($meta['currency'] ?? $instrument['quote_currency']));
    }

    public function history(array $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $result = $this->chart($instrument, sprintf(
            'period1=%d&period2=%d&interval=1d',
            $from->setTime(0, 0)->getTimestamp(),
            $to->setTime(23, 59, 59)->getTimestamp(),
        ));
        $meta = $result['meta'] ?? [];
        $offset = (int) ($meta['gmtoffset'] ?? 0);
        $currency = (string) ($meta['currency'] ?? $instrument['quote_currency']);
        $closes = $result['indicators']['quote'][0]['close'] ?? [];
        $quotes = [];
        foreach ($result['timestamp'] ?? [] as $i => $ts) {
            $close = $closes[$i] ?? null;
            if (!is_int($close) && !is_float($close)) {
                continue; // svátek / chybějící svíčka
            }
            $date = gmdate('Y-m-d', (int) $ts + $offset);
            if ($date >= $from->format('Y-m-d') && $date <= $to->format('Y-m-d')) {
                $quotes[$date] = self::quote($date, $close, $currency);
            }
        }
        return array_values($quotes);
    }

    /** @param array<string,mixed> $instrument */
    public static function ticker(array $instrument): ?string
    {
        $ref = trim((string) ($instrument['source_refs']['yahoo'] ?? ''));
        if ($ref !== '') {
            return $ref;
        }
        return ($instrument['asset_class'] ?? '') === 'crypto'
            ? strtoupper($instrument['symbol'] . '-' . $instrument['quote_currency'])
            : null;
    }

    /**
     * @param array<string,mixed> $instrument
     * @return array<string,mixed>
     */
    private function chart(array $instrument, string $query): array
    {
        $ticker = self::ticker($instrument) ?? throw new MarketDataException('Yahoo: chybí ticker.');
        $body = $this->http->get(
            rtrim((string) $this->config->get('prices.yahoo.chart_api'), '/') . '/' . rawurlencode($ticker) . '?' . $query,
        );
        $data = json_decode($body, true);
        $result = $data['chart']['result'][0] ?? null;
        if (!is_array($result)) {
            $error = $data['chart']['error']['description'] ?? 'nečitelná odpověď';
            throw new MarketDataException("Yahoo {$ticker}: {$error}");
        }
        return $result;
    }

    private static function quote(string $date, int|float $price, string $currency): PriceQuote
    {
        $close = Decimal::fromExternalFloat($price);
        if ($currency === 'GBp' || $currency === 'GBX') {
            return new PriceQuote($date, $close->dividedBy(100, Decimal::SCALE_PRICE, RoundingMode::HalfUp)->strippedOfTrailingZeros(), 'GBP');
        }
        return new PriceQuote($date, $close, strtoupper($currency));
    }
}
