<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Sharing;

/**
 * Kontext aktuálního požadavku pro sdílení: kdo jedná (divák / partner) a čí data čte. Nastavuje ho
 * {@see \MyPortfolio\Middleware\ShareContextMiddleware} na začátku každého požadavku (bez sdílení prázdný).
 *
 * Repository z něj berou jen dvě věci: omezení na sdílená portfolia vlastníka (`portfolioFilter`) a autora
 * zápisu, když zapisuje partner (`createdBy`). `user_id` jim dál předává akce — izolace se nemění.
 */
final class AccessContext
{
    private ?int $owner = null;
    private ?int $actor = null;
    /** @var list<int>|null */
    private ?array $portfolios = null;

    public function reset(): void
    {
        $this->owner = $this->actor = $this->portfolios = null;
    }

    /** @param list<int>|null $portfolios null = všechna portfolia vlastníka */
    public function set(int $owner, int $actor, ?array $portfolios): void
    {
        $this->owner = $owner;
        $this->actor = $actor;
        $this->portfolios = $portfolios;
    }

    /**
     * Spustí výpočet v kontextu jiného vlastníka (souhrn domácnosti) a vrátí původní kontext.
     *
     * @template T
     * @param list<int>|null $portfolios
     * @param callable():T $fn
     * @return T
     */
    public function as(int $owner, int $actor, ?array $portfolios, callable $fn): mixed
    {
        $saved = [$this->owner, $this->actor, $this->portfolios];
        $this->set($owner, $actor, $portfolios);
        try {
            return $fn();
        } finally {
            [$this->owner, $this->actor, $this->portfolios] = $saved;
        }
    }

    /** Sdílená portfolia, když se čtou data vlastníka `$userId` partnerem se sdílením portfolií; jinak null = bez omezení. @return list<int>|null */
    public function portfolioFilter(int $userId): ?array
    {
        return $this->owner === $userId && $this->actor !== $userId ? $this->portfolios : null;
    }

    /** Autor zápisu do dat vlastníka `$userId` — partner, nebo null (zapisuje vlastník). */
    public function createdBy(int $userId): ?int
    {
        return $this->owner === $userId && $this->actor !== null && $this->actor !== $userId ? $this->actor : null;
    }

    /** SQL podmínka `AND <sloupec> IN (…)` pro sdílená portfolia; bez omezení prázdný řetězec. */
    public function sqlIn(int $userId, string $column): string
    {
        $ids = $this->portfolioFilter($userId);
        if ($ids === null) {
            return '';
        }
        return $ids === [] ? ' AND 1 = 0' : ' AND ' . $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
}
