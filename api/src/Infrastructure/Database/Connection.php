<?php

declare(strict_types=1);

namespace MyPortfolio\Infrastructure\Database;

use MyPortfolio\Infrastructure\Config\Config;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Connection
{
    private ?PDO $pdo = null;
    private readonly LoggerInterface $logger;
    /** @var array<string,bool> */
    private array $schemaCache = [];

    public function __construct(private readonly Config $config, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $host    = $this->config->get('db.host', '127.0.0.1');
            $port    = (int) $this->config->get('db.port', 3306);
            $name    = $this->config->get('db.name');
            $user    = $this->config->get('db.user');
            $pass    = $this->config->get('db.pass', '');
            $charset = $this->config->get('db.charset', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

            $this->pdo = new LoggingPdo($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ], $this->logger);

            $this->pdo->exec("SET time_zone = '" . date('P') . "'");
        }

        return $this->pdo;
    }

    /**
     * Uvolní PDO spojení (nastaví na null → GC zavře MySQL connection). Web ho
     * nepotřebuje (1 connection per request, zavře se na konci), ale testy stavějí
     * container per metodu — bez uvolnění by se connections kumulovaly přes celý
     * běh a narazily na MariaDB max_connections. Při dalším pdo() se vytvoří znovu.
     */
    public function close(): void
    {
        $this->pdo = null;
        $this->schemaCache = [];
    }

    public function hasColumn(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException('Neplatný identifikátor databázového schématu.');
        }
        $key = "column:{$table}.{$column}";
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }

        $pdo = $this->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
            return $this->schemaCache[$key] = array_any(
                $rows,
                static fn (array $row): bool => (string) ($row['name'] ?? '') === $column,
            );
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return $this->schemaCache[$key] = $stmt->fetchColumn() !== false;
    }

    public function hasTable(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Neplatný identifikátor databázového schématu.');
        }
        $key = "table:{$table}";
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }

        $pdo = $this->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
        } else {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
        }
        $stmt->execute([$table]);
        return $this->schemaCache[$key] = $stmt->fetchColumn() !== false;
    }

    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
