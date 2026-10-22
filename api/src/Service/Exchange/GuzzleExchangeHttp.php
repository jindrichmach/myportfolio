<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleExchangeHttp implements ExchangeHttp
{
    private ?Client $client = null;

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        try {
            $response = $this->client()->request($method, $url, array_filter([
                'headers' => $headers + ['User-Agent' => 'MyPortfolio', 'Accept' => 'application/json'],
                'body'    => $body,
            ], static fn ($v) => $v !== null));
        } catch (GuzzleException $e) {
            // Text chyby obsahuje URL včetně query (Binance: podpis HMAC, timestamp) — do last_message a logu jen bez ní
            $message = (string) preg_replace('/\?\S*/', '?…', $e->getMessage());
            throw new ExchangeException('Spojení s ' . parse_url($url, PHP_URL_HOST) . ' selhalo: ' . $message);
        }
        $out = [];
        foreach ($response->getHeaders() as $name => $values) {
            $out[strtolower((string) $name)] = implode(', ', $values);
        }
        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody(), 'headers' => $out];
    }

    private function client(): Client
    {
        return $this->client ??= new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'allow_redirects' => ['max' => 3, 'protocols' => ['https']],
        ]);
    }
}
