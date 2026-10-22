<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Portfolio;

use Brick\Math\BigDecimal;

final class HoldingResult
{
    /**
     * @param array<string,mixed>|null $firstNegative transakce, po které stav poprvé klesl pod nulu
     */
    public function __construct(
        public readonly BigDecimal $holding,
        public readonly ?array $firstNegative,
    ) {}

    public function isValid(): bool
    {
        return $this->firstNegative === null;
    }
}
