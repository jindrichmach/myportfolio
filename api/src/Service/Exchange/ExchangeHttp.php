<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange;

/**
 * HTTP pro API burz — vrací i chybové odpovědi (burzy v nich posílají důvod). V testech fake s fixtures.
 */
interface ExchangeHttp
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     * @throws ExchangeException síťová chyba (timeout, DNS, TLS)
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
