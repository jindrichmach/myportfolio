<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange;

/** Chyba komunikace s burzou (síť, neplatný klíč, chybějící oprávnění, limit) — zpráva je pro uživatele. */
final class ExchangeException extends \RuntimeException
{
}
