<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange;

/** Registr klientů burz (jako ImportParsers pro CSV). */
final class ExchangeClients
{
    /** @var array<string,ExchangeClient> */
    private array $clients = [];

    public function __construct(
        Client\CoinbaseClient $coinbase,
        Client\BinanceClient $binance,
        Client\KrakenClient $kraken,
        Client\CoinmateClient $coinmate,
    ) {
        foreach ([$coinbase, $binance, $kraken, $coinmate] as $client) {
            $this->clients[$client->provider()] = $client;
        }
    }

    public function get(string $provider): ?ExchangeClient
    {
        return $this->clients[$provider] ?? null;
    }

    /** @return list<array{provider:string,label:string,fields:list<array<string,mixed>>}> */
    public function list(string $locale): array
    {
        $i = $locale === 'en' ? 1 : 0;
        return array_values(array_map(static fn (ExchangeClient $c) => [
            'provider' => $c->provider(),
            'label'    => $c->label(),
            'fields'   => array_map(static fn (array $f) => ['label' => $f['label'][$i]] + $f, $c->credentialFields()),
        ], $this->clients));
    }
}
