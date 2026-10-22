<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Market;

/**
 * GET požadavky na externí zdroje kurzů a cen. V testech se nahrazuje fake implementací s fixtures.
 */
interface HttpFetcher
{
    /**
     * @param array<string,string> $headers
     * @throws MarketDataException síťová chyba nebo HTTP status mimo 2xx
     */
    public function get(string $url, array $headers = []): string;
}
