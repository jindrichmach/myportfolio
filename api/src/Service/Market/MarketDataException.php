<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Market;

/** Zdroj kurzů/cen je nedostupný nebo vrátil nečitelná data. */
final class MarketDataException extends \RuntimeException
{
}
