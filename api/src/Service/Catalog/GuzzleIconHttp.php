<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Catalog;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP pro stažení loga z webu instituce: krátké timeouty, jen https. Každý krok (i přesměrování, které
 * se následuje ručně) projde {@see PublicUrlGuard} a spojení se připne na ověřenou IP (CURLOPT_RESOLVE),
 * takže DNS rebinding nepřesměruje stahování do vnitřní sítě. Přenos nad 2 MB curl přeruší (progress)
 * i bez hlavičky Content-Length — streamování nejde, Guzzle by ho poslal přes stream handler bez curl voleb.
 */
final class GuzzleIconHttp implements HttpFetcher
{
    private const MAX_BYTES = 2_097_152;
    private const MAX_REDIRECTS = 3;
    private ?Client $client = null;

    /** @param callable|null $handler Guzzle handler (v testech MockHandler) */
    public function __construct(
        private readonly PublicUrlGuard $guard,
        private readonly mixed $handler = null,
    ) {}

    public function get(string $url, array $headers = []): string
    {
        try {
            for ($redirects = 0; ; $redirects++) {
                $target = $this->guard->resolve($url);
                $response = $this->client()->get($target['url'], [
                    'headers' => $headers + ['User-Agent' => 'Mozilla/5.0 (MyPortfolio)'],
                    'curl'    => [CURLOPT_RESOLVE => [$target['host'] . ':443:' . self::curlAddress($target['ips'][0])]],
                ]);
                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                    if ($redirects >= self::MAX_REDIRECTS) {
                        throw new MarketDataException('Příliš mnoho přesměrování.');
                    }
                    $url = (string) UriResolver::resolve(new Uri($target['url']), new Uri($response->getHeaderLine('Location')));
                    continue;
                }
                if ($status >= 400) {
                    throw new MarketDataException("HTTP {$status}");
                }
                return self::readLimited($response);
            }
        } catch (GuzzleException|DomainError|MarketDataException $e) {
            throw new MarketDataException('HTTP ' . parse_url($url, PHP_URL_HOST) . ': ' . $e->getMessage(), 0, $e);
        }
    }

    private static function readLimited(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(65536);
            if (strlen($body) > self::MAX_BYTES) {
                throw new MarketDataException('Odpověď je příliš velká.');
            }
        }
        return $body;
    }

    private static function curlAddress(string $ip): string
    {
        return str_contains($ip, ':') ? "[{$ip}]" : $ip;
    }

    private function client(): Client
    {
        return $this->client ??= new Client(array_filter([
            'handler'         => $this->handler !== null ? HandlerStack::create($this->handler) : null,
            'timeout'         => 8,
            'connect_timeout' => 4,
            'http_errors'     => false,
            'allow_redirects' => false,
            'progress'        => static function (int|float $downloadTotal, int|float $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new \RuntimeException('Odpověď je příliš velká.');
                }
            },
            'protocols'       => ['https'],
            'on_headers'      => static function (ResponseInterface $r): void {
                if ((int) $r->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                    throw new \RuntimeException('Odpověď je příliš velká.');
                }
            },
        ], static fn ($v) => $v !== null));
    }
}
