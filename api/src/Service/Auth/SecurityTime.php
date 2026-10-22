<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

final readonly class SecurityTime
{
    private function __construct(
        public \DateTimeImmutable $utc,
        public string $utcSql,
        public int $epochSeconds,
    ) {}

    public static function fromDatabase(string $utcSql, string|int|float $epoch): self
    {
        $utc = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $utcSql,
            new \DateTimeZone('UTC'),
        );
        if ($utc === false || !is_numeric($epoch)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný bezpečnostní čas.');
        }
        $epochSeconds = (int) floor((float) $epoch);
        if (abs($utc->getTimestamp() - $epochSeconds) > 1) {
            throw new \UnexpectedValueException('UTC a epoch databázového času si neodpovídají.');
        }

        return new self($utc, $utcSql, $epochSeconds);
    }

    public static function fromDateTime(\DateTimeImmutable $time): self
    {
        $utc = $time->setTimezone(new \DateTimeZone('UTC'));
        return new self(
            $utc,
            $utc->format('Y-m-d H:i:s.u'),
            $utc->getTimestamp(),
        );
    }

    public function plusSeconds(int $seconds): \DateTimeImmutable
    {
        return $this->utc->modify(sprintf('+%d seconds', $seconds));
    }
}
