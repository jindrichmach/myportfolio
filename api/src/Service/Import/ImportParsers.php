<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import;

use MyPortfolio\Service\Import\Parser\ImportParserInterface;
use MyPortfolio\Service\Import\Reader\Table;

/** Registr parserů exportů burz a brokerů. */
final class ImportParsers
{
    /** @var array<string,ImportParserInterface> */
    private array $parsers = [];

    /** @param list<ImportParserInterface>|null $parsers null = všechny podporované */
    public function __construct(?array $parsers = null)
    {
        foreach ($parsers ?? self::all() as $p) {
            $this->parsers[$p->key()] = $p;
        }
    }

    /** @return list<ImportParserInterface> */
    public static function all(): array
    {
        return [
            new Parser\CoinbaseParser(), new Parser\AnycoinParser(), new Parser\CoinmateParser(), new Parser\BinanceParser(), new Parser\KrakenParser(),
            new Parser\XtbParser(), new Parser\Trading212Parser(), new Parser\RevolutParser(), new Parser\DegiroParser(), new Parser\InteractiveBrokersParser(),
        ];
    }

    public function get(string $key): ?ImportParserInterface
    {
        return $this->parsers[$key] ?? null;
    }

    /** @param list<Table> $tables */
    public function detect(array $tables): ?ImportParserInterface
    {
        foreach ($this->parsers as $p) {
            if ($p->detect($tables)) {
                return $p;
            }
        }
        return null;
    }

    /** @return list<array{key:string,label:string}> */
    public function list(): array
    {
        return array_values(array_map(static fn (ImportParserInterface $p) => ['key' => $p->key(), 'label' => $p->label()], $this->parsers));
    }
}
