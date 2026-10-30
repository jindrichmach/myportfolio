<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Catalog;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * HTTP pro stažení loga z webu instituce: krátké timeouty, jen https, každé přesměrování projde
 * {@see PublicUrlGuard}, odpověď nad 2 MB se zahodí.
 */
final class GuzzleIconHttp implements HttpFetcher
{
    private const MAX_BYTES = 2_097_152;
    private ?Client $client = null;

    public function __construct(private readonly PublicUrlGuard $guard) {}

    public function get(string $url, array $headers = []): string
    {
        try {
            $response = $this->client()->get($url, [
                'headers' => $headers + ['User-Agent' => 'Mozilla/5.0 (MyPortfolio)'],
                'on_headers' => static function (ResponseInterface $r): void {
                    if ((int) $r->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                        throw new \RuntimeException('Odpověď je příliš velká.');
                    }
                },
            ]);
        } catch (GuzzleException|DomainError $e) {
            throw new MarketDataException('HTTP ' . parse_url($url, PHP_URL_HOST) . ': ' . $e->getMessage(), 0, $e);
        }
        $body = $response->getBody()->read(self::MAX_BYTES + 1);
        if (strlen($body) > self::MAX_BYTES) {
            throw new MarketDataException('Odpověď je příliš velká.');
        }
        return $body;
    }

    private function client(): Client
    {
        return $this->client ??= new Client([
            'timeout'         => 8,
            'connect_timeout' => 4,
            'http_errors'     => true,
            'allow_redirects' => [
                'max'         => 3,
                'protocols'   => ['https'],
                'on_redirect' => fn ($request, $response, UriInterface $uri) => $this->guard->assert((string) $uri),
            ],
            'protocols'       => ['https'],
        ]);
    }
}
