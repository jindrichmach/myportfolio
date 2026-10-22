<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import;

/**
 * Jeden pohyb z exportu burzy připravený k importu. Čísla jsou desetinné stringy (tečka), kladná.
 *
 * kind: buy | sell | reward | dividend | interest | fee | send | receive | split | skip
 *   send / receive = kryptoměna odeslaná z účtu / přijatá na účet (převod — protistranu určí uživatel)
 *   skip = řádek se neimportuje (`skipReason`), v náhledu se jen ukáže
 * assetClass: crypto | security (akcie/ETF — rozliší se při párování instrumentu)
 * currency: měna částky / ceny transakce; null = měna cílového účtu (export ji neuvádí)
 * quoteCurrency: měna, ve které se instrument obchoduje (pro nový instrument), když se liší od `currency`
 */
final class Draft
{
    public function __construct(
        public string $kind,
        public string $executedAt,
        public string $symbol = '',
        public ?string $isin = null,
        public ?string $name = null,
        public string $assetClass = 'crypto',
        public ?string $quantity = null,
        public ?string $unitPrice = null,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $fee = null,
        public ?string $feeCurrency = null,
        public ?string $splitRatio = null,
        public string $externalId = '',
        public ?string $note = null,
        public ?string $skipReason = null,
        public int $line = 0,
        public ?string $quoteCurrency = null,
    ) {}

    public static function skip(string $executedAt, string $reason, int $line, string $externalId = '', string $symbol = ''): self
    {
        return new self('skip', $executedAt, $symbol, skipReason: $reason, externalId: $externalId, line: $line);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(...$a);
    }
}
