<?php

declare(strict_types=1);

namespace MyPortfolio\Infrastructure\Cache;

use MyPortfolio\Infrastructure\Config\Config;
use Predis\Client as RedisClient;

/**
 * Vrací Predis klienta nebo null pokud je Redis vypnutý / nedostupný.
 * Klíče se prefixují přes `cfg.redis.prefix`.
 */
final class RedisFactory
{
    private ?RedisClient $client = null;
    private bool $checked = false;

    public function __construct(private readonly Config $config) {}

    public function client(): ?RedisClient
    {
        if ($this->checked) {
            return $this->client;
        }
        $this->checked = true;

        if (!$this->config->get('redis.enabled', false)) {
            return null;
        }

        try {
            $this->client = new RedisClient([
                'scheme'   => 'tcp',
                'host'     => (string) $this->config->get('redis.host', '127.0.0.1'),
                'port'     => (int) $this->config->get('redis.port', 6379),
                'database' => (int) $this->config->get('redis.db', 0),
                'timeout'  => 1.5,
            ], [
                'prefix' => (string) $this->config->get('redis.prefix', 'myportfolio:'),
            ]);
            $this->client->ping();
        } catch (\Throwable) {
            $this->client = null;
        }

        return $this->client;
    }

    public function isAvailable(): bool
    {
        return $this->client() !== null;
    }
}
