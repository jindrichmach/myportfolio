<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Price;

use MyPortfolio\Service\Market\MarketDataException;

/**
 * Svíčky z veřejného XTB widgetu (x-station WebSocket). Odděleno od XtbProvider kvůli testům.
 */
interface XtbWidgetTransport
{
    /**
     * @param array{url:string,endpoint:string,user:string,accountId:string,accessCode:string} $config
     * @return list<array{time:int,close:float|int}> time v ms (UTC)
     * @throws MarketDataException
     */
    public function chart(array $config, int $assetClass, string $symbol, int $quoteId, int $startMs, int $endMs, int $intervalMs): array;
}
