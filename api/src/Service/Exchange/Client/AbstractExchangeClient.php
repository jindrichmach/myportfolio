<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange\Client;

use MyPortfolio\Service\Exchange\ExchangeClient;
use MyPortfolio\Service\Exchange\ExchangeException;
use MyPortfolio\Service\Exchange\ExchangeHttp;
use MyPortfolio\Service\Import\Reader\Table;

/**
 * Společný základ klientů burz. Odpovědi API se převádějí na tabulku ve formátu CSV exportu dané burzy
 * a zpracují existujícím parserem importu — stejná klasifikace pohybů, stablecoiny i ID řádků jako u CSV.
 */
abstract class AbstractExchangeClient implements ExchangeClient
{
    /** Pojistka proti nekonečnému stránkování. */
    protected const MAX_PAGES = 2000;

    public function __construct(protected readonly ExchangeHttp $http) {}

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    protected static function table(array $header, array $rows): Table
    {
        return new Table('api', [$header, ...$rows]);
    }

    /** @param array<string,string> $credentials */
    protected static function credential(array $credentials, string $key): string
    {
        $value = trim((string) ($credentials[$key] ?? ''));
        if ($value === '') {
            throw new ExchangeException("Chybí přihlašovací údaj „{$key}\".");
        }
        return $value;
    }

    /** Nonce rostoucí v rámci procesu (ms; při rychlých voláních +1). */
    protected static function nonce(): string
    {
        static $last = 0;
        $now = (int) floor(microtime(true) * 1000);
        $last = max($now, $last + 1);
        return (string) $last;
    }

    /** UTC čas z unix sekund / ms do formátu CSV exportu. */
    protected static function utc(string $seconds): string
    {
        return gmdate('Y-m-d H:i:s', (int) floor((float) $seconds));
    }
}
