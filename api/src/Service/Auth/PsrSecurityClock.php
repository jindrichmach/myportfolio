<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use PDO;
use Psr\Clock\ClockInterface;

/**
 * Deterministická varianta pro testy; produkce používá DatabaseSecurityClock.
 */
final class PsrSecurityClock implements SecurityClock
{
    public function __construct(private readonly ClockInterface $clock) {}

    public function capture(PDO $pdo): SecurityTime
    {
        return SecurityTime::fromDateTime($this->clock->now());
    }
}
