<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Price;

use Brick\Math\BigDecimal;

final class PriceQuote
{
    public function __construct(
        public readonly string $date,
        public readonly BigDecimal $close,
        public readonly string $currency,
    ) {}
}
