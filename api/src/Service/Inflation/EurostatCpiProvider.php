<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Inflation;

use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Support\Decimal;

/**
 * Měsíční index spotřebitelských cen ČR — HICP z Eurostatu (datová sada `prc_hicp_minr`, celkový index,
 * 2015 = 100). Veřejná databáze ČSÚ se od 2026 neaktualizuje a nový DataStat nemá zdokumentované API;
 * HICP se od národního CPI liší jen nepatrně (jiné váhy, bez imputovaného nájemného).
 */
final class EurostatCpiProvider
{
    public const NAME = 'eurostat';
    private const URL = 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_minr'
        . '?format=JSON&lang=EN&geo=CZ&coicop18=TOTAL&unit=I15&sinceTimePeriod=%s';

    public function __construct(private readonly HttpFetcher $http) {}

    /** @return array<string,string> měsíc YYYY-MM → index (od `$since`) */
    public function fetch(string $since = '1996-01'): array
    {
        return self::parse($this->http->get(sprintf(self::URL, $since)));
    }

    /** @return array<string,string> */
    public static function parse(string $body): array
    {
        $data = json_decode($body, true);
        $time = $data['dimension']['time']['category']['index'] ?? null;
        if (!is_array($time) || !is_array($data['value'] ?? null) || ($data['size'] ?? null) === null
            || array_product(array_slice((array) $data['size'], 0, -1)) !== 1) {
            throw new MarketDataException('Eurostat: neočekávaný tvar odpovědi.');
        }
        $out = [];
        foreach ($time as $month => $i) {
            $value = $data['value'][(string) $i] ?? null;
            if (is_int($value) || is_float($value)) {
                $out[(string) $month] = (string) Decimal::fromExternalFloat($value, 4);
            }
        }
        ksort($out);
        return $out;
    }
}
