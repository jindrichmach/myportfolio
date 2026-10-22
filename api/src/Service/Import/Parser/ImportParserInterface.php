<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use MyPortfolio\Service\Import\Draft;
use MyPortfolio\Service\Import\Reader\Table;

interface ImportParserInterface
{
    /** Stabilní klíč zdroje (source transakcí = `csv:<key>`). */
    public function key(): string;

    public function label(): string;

    /** @param list<Table> $tables */
    public function detect(array $tables): bool;

    /**
     * @param list<Table> $tables
     * @return list<Draft>
     * @throws \InvalidArgumentException soubor neodpovídá formátu
     */
    public function parse(array $tables): array;
}
