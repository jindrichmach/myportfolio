<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Support;

use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;

/**
 * HttpFetcher pro testy: odpověď podle první shody podřetězce v URL.
 * Hodnota `null` simuluje chybu zdroje (MarketDataException).
 */
final class FakeHttpFetcher implements HttpFetcher
{
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string,?string> $routes podřetězec URL => tělo odpovědi */
    public function __construct(private array $routes) {}

    public function get(string $url, array $headers = []): string
    {
        $this->requested[] = $url;
        foreach ($this->routes as $needle => $body) {
            if (str_contains($url, $needle)) {
                return $body ?? throw new MarketDataException('fake: zdroj nedostupný (' . $needle . ')');
            }
        }
        throw new MarketDataException('fake: neznámá URL ' . $url);
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/Fixtures/market/' . $name);
    }
}
