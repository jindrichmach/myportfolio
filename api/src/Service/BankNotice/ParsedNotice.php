<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

/**
 * Vytěžené avízo. Částky jsou desetinné stringy (tečka), `amount` se znaménkem:
 * kladná = příchozí, záporná = odchozí.
 */
final class ParsedNotice
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $postedAt,
        public readonly string $account,
        public readonly ?string $balance = null,
        public readonly ?string $counterpartyAccount = null,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $message = null,
        public readonly ?string $movementType = null,
        public readonly ?string $bankRef = null,
    ) {}

    public function isIncoming(): bool
    {
        return !str_starts_with($this->amount, '-');
    }

    /** @return array<string,?string> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
