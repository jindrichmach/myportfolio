<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Fx;

/** Kurz měny k datu není k dispozici — nikdy se nenahrazuje 1. */
final class FxRateMissing extends \RuntimeException
{
    public function __construct(public readonly string $currency, public readonly string $date)
    {
        parent::__construct(sprintf('Chybí kurz %s k %s.', $currency, $date));
    }
}
