<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Price;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Support\Decimal;

/**
 * XTB — `source_refs.xtb` (klíč z XTB instrument API, např. "CSPX.UK").
 *
 * 1) instrument API → metadata (`xapi5_symbol`, `currencyCode`, `route`)
 * 2) stránka instrumentu → `window.websocketClientData` (veřejné přihlášení widgetu)
 * 3) WebSocket → hodinové svíčky (aktuální cena) / denní svíčky (historie)
 *
 * Přístup nemá oficiální API a stránka se může změnit — proto je v řetězci za ním Yahoo.
 */
final class XtbProvider implements PriceProvider
{
    private const WEB_BASE = 'https://www.xtb.com';
    private const HOUR_MS = 3_600_000;
    private const DAY_MS = 86_400_000;

    /** @var array<string,array<string,mixed>> */
    private array $metaCache = [];
    /** @var array<string,array{url:string,endpoint:string,user:string,accountId:string,accessCode:string}> */
    private array $configCache = [];

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly XtbWidgetTransport $transport,
        private readonly Config $config,
    ) {}

    public function name(): string
    {
        return 'xtb';
    }

    public function supports(array $instrument): bool
    {
        return ($instrument['source_refs']['xtb'] ?? '') !== '';
    }

    public function latest(array $instrument): PriceQuote
    {
        $end = time() * 1000;
        $bars = $this->bars($instrument, $end - 2 * self::DAY_MS, $end, self::HOUR_MS);
        $last = end($bars) ?: throw new MarketDataException('XTB: žádné svíčky.');
        return new PriceQuote(self::localDate($last['time']), Decimal::fromExternalFloat($last['close']), $this->currency($instrument));
    }

    public function history(array $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $bars = $this->bars(
            $instrument,
            $from->setTime(0, 0)->getTimestamp() * 1000,
            $to->setTime(23, 59, 59)->getTimestamp() * 1000,
            self::DAY_MS,
        );
        $currency = $this->currency($instrument);
        $quotes = [];
        foreach ($bars as $bar) {
            $date = self::localDate($bar['time']);
            $quotes[$date] = new PriceQuote($date, Decimal::fromExternalFloat($bar['close']), $currency);
        }
        return array_values($quotes);
    }

    /**
     * @param array<string,mixed> $instrument
     * @return list<array{time:int,close:float|int}>
     */
    private function bars(array $instrument, int $startMs, int $endMs, int $intervalMs): array
    {
        $meta = $this->meta($instrument);
        if (preg_match('/^(\d+)_(.+)_(\d+)$/', (string) ($meta['xapi5_symbol'] ?? ''), $m) !== 1) {
            throw new MarketDataException('XTB: neočekávaný xapi5_symbol.');
        }
        return $this->transport->chart($this->widgetConfig((string) $meta['route']), (int) $m[1], $m[2], (int) $m[3], $startMs, $endMs, $intervalMs);
    }

    /**
     * @param array<string,mixed> $instrument
     * @return array<string,mixed>
     */
    private function meta(array $instrument): array
    {
        $ref = (string) ($instrument['source_refs']['xtb'] ?? '');
        if (isset($this->metaCache[$ref])) {
            return $this->metaCache[$ref];
        }
        $query = explode('.', $ref)[0];
        $body = $this->http->get((string) $this->config->get('prices.xtb.instruments_api') . '?query=' . rawurlencode($query));
        $data = json_decode($body, true);
        foreach ((array) ($data['instrumentsCollectionLimited'] ?? []) as $items) {
            if (is_array($items) && isset($items[$ref]) && is_array($items[$ref])) {
                return $this->metaCache[$ref] = $items[$ref];
            }
        }
        throw new MarketDataException("XTB: instrument {$ref} nenalezen.");
    }

    /** @return array{url:string,endpoint:string,user:string,accountId:string,accessCode:string} */
    private function widgetConfig(string $route): array
    {
        if (isset($this->configCache[$route])) {
            return $this->configCache[$route];
        }
        $html = $this->http->get(self::WEB_BASE . $route);
        if (preg_match('/window\.websocketClientData\s*=\s*({[^}]+})/', $html, $m) !== 1) {
            throw new MarketDataException('XTB: konfigurace widgetu na stránce chybí.');
        }
        $cfg = json_decode(str_replace("'", '"', $m[1]), true);
        foreach (['user', 'accountId', 'accessCode'] as $key) {
            if (!is_array($cfg) || !isset($cfg[$key])) {
                throw new MarketDataException('XTB: neúplná konfigurace widgetu.');
            }
        }
        return $this->configCache[$route] = [
            'url'        => (string) ($cfg['url'] ?? $this->config->get('prices.xtb.websocket_url')),
            'endpoint'   => (string) ($cfg['endpoint'] ?? 'meta1'),
            'user'       => (string) $cfg['user'],
            'accountId'  => (string) $cfg['accountId'],
            'accessCode' => (string) $cfg['accessCode'],
        ];
    }

    /** @param array<string,mixed> $instrument */
    private function currency(array $instrument): string
    {
        $code = (string) ($this->meta($instrument)['currencyCode'] ?? '');
        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : (string) $instrument['quote_currency'];
    }

    private static function localDate(int $timeMs): string
    {
        return (new \DateTimeImmutable('@' . intdiv($timeMs, 1000)))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d');
    }
}
