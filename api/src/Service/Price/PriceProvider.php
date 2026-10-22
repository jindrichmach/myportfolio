<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Price;

use MyPortfolio\Service\Market\MarketDataException;

/**
 * Zdroj cen instrumentů. `$instrument` je řádek z InstrumentRepository
 * (asset_class, symbol, quote_currency, source_refs, …).
 */
interface PriceProvider
{
    /** Hodnota ENUM `prices.source`. */
    public function name(): string;

    /** @param array<string,mixed> $instrument */
    public function supports(array $instrument): bool;

    /**
     * @param array<string,mixed> $instrument
     * @throws MarketDataException
     */
    public function latest(array $instrument): PriceQuote;

    /**
     * Denní uzavírací ceny v rozsahu (provider může vrátit jen část, např. CoinGecko max. 365 dní).
     *
     * @param array<string,mixed> $instrument
     * @return list<PriceQuote>
     * @throws MarketDataException
     */
    public function history(array $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
