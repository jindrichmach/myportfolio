<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Support;

use MyPortfolio\Service\Exchange\ExchangeHttp;

/** Fake HTTP pro klienty burz: odpovědi podle regexu na „METODA url", zaznamenává požadavky. */
final class FakeExchangeHttp implements ExchangeHttp
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $requests = [];

    /** @param array<string,string|array{status:int,body:string}|\Closure(string):string> $routes regex → tělo (200), odpověď, nebo funkce URL → tělo */
    public function __construct(private array $routes = []) {}

    public function on(string $pattern, string|array|\Closure $response): self
    {
        $this->routes[$pattern] = $response;
        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        foreach ($this->routes as $pattern => $response) {
            if (preg_match($pattern, "$method $url") === 1) {
                if ($response instanceof \Closure) {
                    $response = $response($url);
                }
                return is_array($response) ? $response + ['headers' => []] : ['status' => 200, 'body' => $response, 'headers' => []];
            }
        }
        return ['status' => 404, 'body' => '{"error":["EGeneral:Unknown method"],"code":-1,"msg":"not found","errors":[{"message":"not found"}]}', 'headers' => []];
    }
}
