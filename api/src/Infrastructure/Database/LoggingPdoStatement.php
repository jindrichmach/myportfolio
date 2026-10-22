<?php

declare(strict_types=1);

namespace MyPortfolio\Infrastructure\Database;

use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;

/**
 * PDOStatement subclass aktivovaný přes PDO::ATTR_STATEMENT_CLASS v LoggingPdo.
 * Při PDOException v execute() pošle strukturovaný záznam do Monologu
 * a pak chybu znovu vyhodí — fail-fast chování callerů zůstává.
 */
final class LoggingPdoStatement extends PDOStatement
{
    protected function __construct(private readonly LoggerInterface $logger) {}

    public function execute(?array $params = null): bool
    {
        try {
            return parent::execute($params);
        } catch (PDOException $e) {
            DbErrorLogger::log($this->logger, $e, (string) $this->queryString, $params ?? []);
            throw $e;
        }
    }
}
