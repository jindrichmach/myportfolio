<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Market;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyPortfolio\Infrastructure\Config\Config;

final class GuzzleHttpFetcher implements HttpFetcher
{
    private ?Client $client = null;

    public function __construct(private readonly Config $config) {}

    public function get(string $url, array $headers = []): string
    {
        try {
            $response = $this->client()->get($url, [
                'headers' => $headers + ['User-Agent' => 'Mozilla/5.0 (MyPortfolio)', 'Accept' => '*/*'],
            ]);
        } catch (GuzzleException $e) {
            throw new MarketDataException('HTTP ' . parse_url($url, PHP_URL_HOST) . ': ' . $e->getMessage(), 0, $e);
        }
        return (string) $response->getBody();
    }

    private function client(): Client
    {
        return $this->client ??= new Client([
            'timeout'         => (int) $this->config->get('prices.timeout', 10),
            'connect_timeout' => 5,
            'http_errors'     => true,
            'allow_redirects' => ['max' => 3, 'protocols' => ['https']],
        ]);
    }
}
