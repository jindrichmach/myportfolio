<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Price;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Support\Decimal;

/**
 * CoinGecko — `source_refs.coingecko` (např. "bitcoin"). Veřejné API vrací historii
 * jen za posledních 365 dní; starší dny doplní další zdroj v řetězci (Yahoo).
 */
final class CoinGeckoProvider implements PriceProvider
{
    public const HISTORY_DAYS = 365;

    /** Veřejné API má přísný rate limit (429) — mezi požadavky držíme odstup. */
    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly Config $config,
    ) {}

    public function name(): string
    {
        return 'coingecko';
    }

    public function supports(array $instrument): bool
    {
        return ($instrument['source_refs']['coingecko'] ?? '') !== '';
    }

    public function latest(array $instrument): PriceQuote
    {
        $id = $this->ref($instrument);
        $vs = strtolower((string) $instrument['quote_currency']);
        $data = $this->json('/simple/price?ids=' . rawurlencode($id) . '&vs_currencies=' . $vs . '&include_last_updated_at=true');
        $price = $data[$id][$vs] ?? null;
        if (!is_int($price) && !is_float($price)) {
            throw new MarketDataException("CoinGecko: cena {$id}/{$vs} chybí.");
        }
        $at = isset($data[$id]['last_updated_at']) ? (int) $data[$id]['last_updated_at'] : time();
        return new PriceQuote(self::localDate($at), Decimal::fromExternalFloat($price), (string) $instrument['quote_currency']);
    }

    public function history(array $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $oldest = (new \DateTimeImmutable('today'))->modify('-' . (self::HISTORY_DAYS - 1) . ' days');
        if ($from < $oldest) {
            $from = $oldest;
        }
        if ($from > $to) {
            return [];
        }
        $id = $this->ref($instrument);
        $vs = strtolower((string) $instrument['quote_currency']);
        $data = $this->json(sprintf(
            '/coins/%s/market_chart/range?vs_currency=%s&from=%d&to=%d',
            rawurlencode($id), $vs, $from->getTimestamp(), $to->modify('+1 day')->getTimestamp(),
        ));
        $byDate = [];
        foreach ($data['prices'] ?? [] as $point) {
            if (!is_array($point) || count($point) < 2 || !is_numeric($point[1])) {
                continue;
            }
            // Pro rozsah > 90 dní CoinGecko vrací denní body (00:00 UTC); poslední bod dne vyhrává.
            $byDate[gmdate('Y-m-d', intdiv((int) $point[0], 1000))] = $point[1];
        }
        $quotes = [];
        foreach ($byDate as $date => $price) {
            if ($date >= $from->format('Y-m-d') && $date <= $to->format('Y-m-d')) {
                $quotes[] = new PriceQuote($date, Decimal::fromExternalFloat($price), (string) $instrument['quote_currency']);
            }
        }
        return $quotes;
    }

    /** @param array<string,mixed> $instrument */
    private function ref(array $instrument): string
    {
        return (string) ($instrument['source_refs']['coingecko'] ?? throw new MarketDataException('CoinGecko: chybí identifikátor.'));
    }

    /** @return array<string,mixed> */
    private function json(string $path): array
    {
        $key = (string) $this->config->get('prices.coingecko.api_key', '');
        $this->throttle((int) $this->config->get('prices.coingecko.min_interval_ms', $key !== '' ? 0 : 2500));
        $body = $this->http->get(
            rtrim((string) $this->config->get('prices.coingecko.api'), '/') . $path,
            $key !== '' ? ['x-cg-demo-api-key' => $key] : [],
        );
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new MarketDataException('CoinGecko: nečitelná odpověď.');
        }
        if (isset($data['error']) || isset($data['status']['error_code'])) {
            throw new MarketDataException('CoinGecko: ' . json_encode($data['error'] ?? $data['status'], JSON_UNESCAPED_UNICODE));
        }
        return $data;
    }

    private function throttle(int $minIntervalMs): void
    {
        $wait = $this->lastRequestAt + $minIntervalMs / 1000 - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);
    }

    private static function localDate(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d');
    }
}
