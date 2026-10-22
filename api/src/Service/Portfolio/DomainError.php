<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Portfolio;

/**
 * Očekávaná doménová chyba s HTTP mapováním (Action ji převede přes Json::error).
 */
final class DomainError extends \RuntimeException
{
    /**
     * @param array<string,mixed> $extra např. ['fields' => [...]]
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Záznam nenalezen.'): self
    {
        return new self('not_found', $message, 404);
    }

    /** @param array<string,list<string>> $fields */
    public static function validation(array $fields): self
    {
        return new self('validation_failed', 'Validace selhala', 400, ['fields' => $fields]);
    }

    public static function inUse(string $message): self
    {
        return new self('in_use', $message, 409);
    }

    public static function duplicate(string $message): self
    {
        return new self('duplicate', $message, 409);
    }
}
