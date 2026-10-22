<?php

declare(strict_types=1);

namespace MyPortfolio\Infrastructure\Cache;

use MyPortfolio\Infrastructure\Config\Config;
use Predis\Client as RedisClient;

final class RedisProbe
{
    public function __construct(private readonly Config $config) {}

    public function isAvailable(): bool
    {
        if (!$this->config->get('redis.enabled', false)) {
            return false;
        }

        try {
            $client = new RedisClient([
                'scheme' => 'tcp',
                'host'   => $this->config->get('redis.host', '127.0.0.1'),
                'port'   => (int) $this->config->get('redis.port', 6379),
                'database' => (int) $this->config->get('redis.db', 0),
                'timeout'  => 1.5,
            ]);
            $client->ping();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
