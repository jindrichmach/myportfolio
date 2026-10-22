<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Price;

use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\PriceRepository;
use MyPortfolio\Repository\SnapshotMaintenanceRepository;
use MyPortfolio\Repository\TransactionFxRepository;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Support\Decimal;
use Psr\Clock\ClockInterface;

/**
 * Ceny instrumentů přes řetězec zdrojů podle `instruments.price_source`:
 *
 *   coingecko → [coingecko, yahoo]   (Yahoo i pro historii starší než 365 dní)
 *   xtb       → [xtb, yahoo]
 *   yahoo     → [yahoo]
 *   manual    → []                   (jen ruční ceny)
 *
 * Když selžou všechny zdroje, nic se nezapíše — cena zůstane poslední známá a je `stale`.
 */
final class PriceService
{
    private const CHAINS = [
        'coingecko' => ['coingecko', 'yahoo'],
        'xtb'       => ['xtb', 'yahoo'],
        'yahoo'     => ['yahoo'],
        'manual'    => [],
    ];

    /** Po kolika dnech bez nové ceny je cena zastaralá (krypto se obchoduje i o víkendu). */
    private const STALE_DAYS = ['crypto' => 2, 'default' => 5];
    /** Mezera v historii, kterou backfillUsed doplní: krypto obchoduje denně, ostatní mají víkendy a svátky. */
    private const GAP_DAYS = ['crypto' => 2, 'default' => 7];
    private const GAP_LOOKBACK_DAYS = 60;

    /** @var array<string,PriceProvider> */
    private array $providers = [];

    /** @param iterable<PriceProvider> $providers */
    public function __construct(
        iterable $providers,
        private readonly InstrumentRepository $instruments,
        private readonly PriceRepository $prices,
        private readonly TransactionFxRepository $usage,
        private readonly ClockInterface $clock,
        private readonly SnapshotMaintenanceRepository $snapshots,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    /**
     * Aktuální cena pro aktivní instrumenty (výchozí: ty, které jsou v pozicích).
     *
     * @param list<int>|null $instrumentIds
     * @return list<array{instrument_id:int,symbol:string,status:string,source:?string,close:?string,errors:list<string>}>
     */
    public function updateLatest(?array $instrumentIds = null): array
    {
        $report = [];
        foreach ($this->targets($instrumentIds) as $instrument) {
            $errors = [];
            $row = ['instrument_id' => $instrument['id'], 'symbol' => $instrument['symbol'], 'status' => 'skipped', 'source' => null, 'close' => null];
            foreach ($this->chain($instrument) as $provider) {
                try {
                    $quote = $provider->latest($instrument);
                } catch (MarketDataException $e) {
                    $errors[] = $provider->name() . ': ' . $e->getMessage();
                    continue;
                }
                $close = (string) Decimal::toDb($quote->close, Decimal::SCALE_PRICE);
                $this->prices->upsert($instrument['id'], $quote->date, $close, $quote->currency, $provider->name());
                $row = [...$row, 'status' => 'ok', 'source' => $provider->name(), 'close' => $quote->close->toString()];
                break;
            }
            if ($row['status'] !== 'ok' && $this->chain($instrument) !== []) {
                $row['status'] = 'failed';
            }
            $report[] = $row + ['errors' => $errors];
        }
        return $report;
    }

    /**
     * Doplní chybějící denní ceny v rozsahu. Existující dny (i ruční) nepřepisuje;
     * co nevrátí první zdroj (např. CoinGecko starší 365 dní), zkusí další.
     *
     * @param array<string,mixed> $instrument
     * @return array{inserted:int,errors:list<string>}
     */
    public function backfill(array $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $inserted = 0;
        $errors = [];
        $earliest = null;
        foreach ($this->chain($instrument) as $provider) {
            try {
                $quotes = $provider->history($instrument, $from, $to);
            } catch (MarketDataException $e) {
                $errors[] = $provider->name() . ': ' . $e->getMessage();
                continue;
            }
            foreach ($quotes as $quote) {
                $close = (string) Decimal::toDb($quote->close, Decimal::SCALE_PRICE);
                if ($this->prices->insertMissing($instrument['id'], $quote->date, $close, $quote->currency, $provider->name())) {
                    $inserted++;
                    $earliest = $earliest === null ? $quote->date : min($earliest, $quote->date);
                }
            }
        }
        if ($earliest !== null) {
            $this->snapshots->markInstrumentDirty((int) $instrument['id'], $earliest);
        }
        return ['inserted' => $inserted, 'errors' => $errors];
    }

    /**
     * Backfill pro instrumenty v pozicích: od první transakce po dnešek, jen co chybí na krajích.
     *
     * @return list<array{instrument_id:int,symbol:string,inserted:int,errors:list<string>}>
     */
    public function backfillUsed(?\DateTimeImmutable $from = null): array
    {
        $report = [];
        $today = $this->today();
        foreach ($this->usage->instrumentsNeedingHistory() as $instrumentId => $since) {
            $instrument = $this->instruments->find($instrumentId);
            if ($instrument === null || !$instrument['is_active'] || $this->chain($instrument) === []) {
                continue;
            }
            $start = $from ?? new \DateTimeImmutable($since);
            $coverage = $this->prices->coverage($instrumentId);
            $ranges = [];
            if ($coverage['min'] === null) {
                $ranges[] = [$start, $today];
            } else {
                if ($start->format('Y-m-d') < $coverage['min']) {
                    $ranges[] = [$start, (new \DateTimeImmutable($coverage['min']))->modify('-1 day')];
                }
                if ((string) $coverage['max'] < $today->format('Y-m-d')) {
                    $ranges[] = [(new \DateTimeImmutable((string) $coverage['max']))->modify('+1 day'), $today];
                }
                // Mezery uprostřed (cron nějakou dobu neběžel, pak uložil jen dnešní cenu); jen nedávné, aby se
                // zdroj bez starších dat nevolal každou hodinu znovu.
                $gapFrom = max($start, $today->modify('-' . self::GAP_LOOKBACK_DAYS . ' days'))->format('Y-m-d');
                $maxGap = self::GAP_DAYS[$instrument['asset_class']] ?? self::GAP_DAYS['default'];
                foreach ($this->prices->gaps($instrumentId, $gapFrom, $maxGap) as $gap) {
                    $ranges[] = [new \DateTimeImmutable($gap['from']), new \DateTimeImmutable($gap['to'])];
                }
            }
            $inserted = 0;
            $errors = [];
            foreach ($ranges as [$a, $b]) {
                $r = $this->backfill($instrument, $a, $b);
                $inserted += $r['inserted'];
                array_push($errors, ...$r['errors']);
            }
            if ($ranges !== []) {
                $report[] = ['instrument_id' => $instrumentId, 'symbol' => $instrument['symbol'], 'inserted' => $inserted, 'errors' => $errors];
            }
        }
        return $report;
    }

    public function setManual(int $instrumentId, string $date, string $close, string $currency): void
    {
        $this->prices->upsert($instrumentId, $date, $close, $currency, 'manual');
        $this->snapshots->markInstrumentDirty($instrumentId, $date);
    }

    /**
     * Poslední cena s příznakem zastaralosti.
     *
     * @param array<string,mixed> $instrument
     * @param array{date:string,close:string,currency:string,source:string}|null $latest
     * @return array{date:string,close:string,currency:string,source:string,stale:bool}|null
     */
    public function withStaleness(array $instrument, ?array $latest): ?array
    {
        if ($latest === null) {
            return null;
        }
        return $latest + ['stale' => $this->isStaleAt($instrument, $latest['date'], $this->today()->format('Y-m-d'))];
    }

    /** Je cena z `$priceDate` k datu `$asOf` zastaralá (krypto 2 dny, ostatní 5)? */
    public function isStaleAt(array $instrument, string $priceDate, string $asOf): bool
    {
        $limit = self::STALE_DAYS[$instrument['asset_class'] ?? ''] ?? self::STALE_DAYS['default'];
        $age = (int) (new \DateTimeImmutable($priceDate))->diff(new \DateTimeImmutable($asOf))->format('%r%a');
        return $age > $limit;
    }

    /**
     * Poslední ceny pro více instrumentů najednou (pro seznamy).
     *
     * @param list<array<string,mixed>> $instruments řádky s `id` a `asset_class`
     * @return array<int,array{date:string,close:string,currency:string,source:string,stale:bool}|null>
     */
    public function lastPrices(array $instruments): array
    {
        $latest = $this->prices->latestFor(array_map(static fn (array $i) => (int) $i['id'], $instruments));
        $out = [];
        foreach ($instruments as $instrument) {
            $out[(int) $instrument['id']] = $this->withStaleness($instrument, $latest[(int) $instrument['id']] ?? null);
        }
        return $out;
    }

    /** @return list<string> názvy zdrojů v pořadí */
    public function chainNames(array $instrument): array
    {
        return array_map(static fn (PriceProvider $p) => $p->name(), $this->chain($instrument));
    }

    /**
     * @param array<string,mixed> $instrument
     * @return list<PriceProvider>
     */
    private function chain(array $instrument): array
    {
        $out = [];
        foreach (self::CHAINS[$instrument['price_source']] ?? [] as $name) {
            $provider = $this->providers[$name] ?? null;
            if ($provider !== null && $provider->supports($instrument)) {
                $out[] = $provider;
            }
        }
        return $out;
    }

    /**
     * @param list<int>|null $instrumentIds
     * @return list<array<string,mixed>>
     */
    private function targets(?array $instrumentIds): array
    {
        $ids = $instrumentIds ?? array_keys($this->usage->instrumentsNeedingHistory());
        $out = [];
        foreach ($ids as $id) {
            $instrument = $this->instruments->find((int) $id);
            if ($instrument !== null && $instrument['is_active']) {
                $out[] = $instrument;
            }
        }
        return $out;
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
    }
}
