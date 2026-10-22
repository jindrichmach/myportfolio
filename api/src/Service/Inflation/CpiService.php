<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Inflation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\CpiRepository;

/**
 * Inflace z měsíčního indexu: mezi měsíci A a B = index(B) / index(A) − 1. Když index pro B ještě
 * nevyšel, použije poslední dostupný měsíc (vrací ho v `to`). Chybějící data = null, nikdy 0 %.
 * Reálný výnos = (1 + nominální) / (1 + inflace) − 1.
 */
final class CpiService
{
    private const SCALE = 10;
    private const REVISION_MONTHS = 24;

    /** @var array<string,string>|null */
    private ?array $index = null;

    public function __construct(
        private readonly CpiRepository $cpi,
        private readonly EurostatCpiProvider $provider,
    ) {}

    /** Stáhne index (posledních 24 měsíců kvůli revizím, prázdná tabulka = celá řada). @return array{fetched:int,updated:int,latest:?string} */
    public function sync(): array
    {
        $latest = $this->cpi->latestMonth();
        $since = $latest === null ? '1996-01'
            : (new \DateTimeImmutable($latest . '-01'))->modify('-' . self::REVISION_MONTHS . ' months')->format('Y-m');
        $rows = $this->provider->fetch($since);
        $updated = 0;
        foreach ($rows as $month => $value) {
            $updated += $this->cpi->upsert($month, $value, EurostatCpiProvider::NAME) ? 1 : 0;
        }
        $this->index = null;
        return ['fetched' => count($rows), 'updated' => $updated, 'latest' => $this->cpi->latestMonth()];
    }

    /**
     * Inflace od měsíce `$from` do měsíce `$to` (YYYY-MM).
     *
     * @return array{rate:BigDecimal,from:string,to:string}|null
     */
    public function between(string $from, string $to): ?array
    {
        $index = $this->index ??= $this->cpi->all();
        if (!isset($index[$from])) {
            return null;
        }
        $end = null;
        foreach ($index as $month => $value) {
            if ($month > $to) {
                break;
            }
            $end = $month;
        }
        if ($end === null || $end <= $from) {
            return null;
        }
        return [
            'rate' => BigDecimal::of($index[$end])->dividedBy($index[$from], self::SCALE, RoundingMode::HalfUp)->minus(1),
            'from' => $from,
            'to'   => $end,
        ];
    }

    /** Reálný výnos z nominálního (zlomky, ne procenta). */
    public static function real(BigDecimal $nominal, BigDecimal $inflation): BigDecimal
    {
        return $nominal->plus(1)->dividedBy($inflation->plus(1), self::SCALE, RoundingMode::HalfUp)->minus(1);
    }

    /** Zlomek → procenta se 2 místy. */
    public static function pct(?BigDecimal $v): ?string
    {
        return $v === null ? null : (string) $v->multipliedBy(100)->toScale(2, RoundingMode::HalfUp);
    }
}
