<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Market;

use Brick\Math\BigDecimal;
use MyPortfolio\Repository\FxRateRepository;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\PriceRepository;
use MyPortfolio\Repository\SnapshotMaintenanceRepository;
use MyPortfolio\Repository\TransactionFxRepository;
use MyPortfolio\Service\Fx\CnbFxProvider;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Service\Price\PriceProvider;
use MyPortfolio\Service\Price\PriceQuote;
use MyPortfolio\Service\Price\PriceService;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Tests\Support\ApiTestCase;
use MyPortfolio\Tests\Support\FakeHttpFetcher;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;

#[Group('integration')]
final class MarketDataTest extends ApiTestCase
{
    private const FX_DAYS = ['2001-01-05', '2001-01-08'];

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->exec("DELETE FROM fx_rates WHERE date BETWEEN '2001-01-01' AND '2001-12-31'");
        }
        parent::tearDown();
    }

    private function clock(string $now): ClockInterface
    {
        return new class ($now) implements ClockInterface {
            public function __construct(private readonly string $now) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->now);
            }
        };
    }

    /** Poskytovatel pro testy: vrátí pevnou cenu, nebo selže. */
    private function provider(string $name, ?string $close, array $history = []): PriceProvider
    {
        return new class ($name, $close, $history) implements PriceProvider {
            public int $latestCalls = 0;

            public function __construct(private readonly string $n, private readonly ?string $close, private readonly array $h) {}

            public function name(): string { return $this->n; }

            public function supports(array $instrument): bool { return true; }

            public function latest(array $instrument): PriceQuote
            {
                $this->latestCalls++;
                if ($this->close === null) {
                    throw new MarketDataException($this->n . ' down');
                }
                return new PriceQuote('2026-09-28', BigDecimal::of($this->close), 'EUR');
            }

            public function history(array $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return array_map(static fn (array $row) => new PriceQuote($row[0], BigDecimal::of($row[1]), 'EUR'), $this->h);
            }
        };
    }

    private function service(array $providers, string $now = '2026-09-28 12:00:00'): PriceService
    {
        return new PriceService(
            $providers,
            $this->container->get(InstrumentRepository::class),
            $this->container->get(PriceRepository::class),
            $this->container->get(TransactionFxRepository::class),
            $this->clock($now),
            $this->container->get(SnapshotMaintenanceRepository::class),
        );
    }

    private function instrument(string $priceSource = 'coingecko'): int
    {
        $r = $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => 'crypto', 'symbol' => 'SYN' . random_int(1000, 9999), 'name' => 'Synthetic', 'quote_currency' => 'EUR',
            'price_source' => $priceSource, 'source_refs' => ['coingecko' => 'synthetic'],
        ]);
        self::assertSame(201, $r['status'], json_encode($r['body']) ?: '');
        return (int) $r['body']['id'];
    }

    public function testFallsBackToNextSourceAndReportsFailureWhenAllFail(): void
    {
        $id = $this->instrument();
        $instruments = $this->container->get(InstrumentRepository::class);
        $prices = $this->container->get(PriceRepository::class);

        $report = $this->service([$this->provider('coingecko', null), $this->provider('yahoo', '100.5')])->updateLatest([$id]);
        self::assertSame('ok', $report[0]['status']);
        self::assertSame('yahoo', $report[0]['source']);
        self::assertSame(['date' => '2026-09-28', 'close' => '100.5', 'currency' => 'EUR', 'source' => 'yahoo'], $prices->latest($id));

        $failed = $this->service([$this->provider('coingecko', null), $this->provider('yahoo', null)])->updateLatest([$id]);
        self::assertSame('failed', $failed[0]['status']);
        self::assertCount(2, $failed[0]['errors']);
        self::assertSame('100.5', $prices->latest($id)['close'], 'Výpadek zdrojů nesmí cenu přepsat ani vynulovat.');

        $late = $this->service([], '2026-10-05 12:00:00');
        self::assertTrue($late->withStaleness($instruments->find($id) ?? [], $prices->latest($id))['stale']);
        self::assertFalse($this->service([])->withStaleness($instruments->find($id) ?? [], $prices->latest($id))['stale']);
    }

    public function testManualSourceHasNoChainAndBackfillKeepsManualPrices(): void
    {
        $manualId = $this->instrument('manual');
        $report = $this->service([$this->provider('coingecko', '1')])->updateLatest([$manualId]);
        self::assertSame('skipped', $report[0]['status']);

        $id = $this->instrument();
        $service = $this->service([
            $this->provider('coingecko', '1', [['2026-09-26', '50'], ['2026-09-27', '51']]),
            $this->provider('yahoo', '1', [['2026-09-25', '49'], ['2026-09-26', '999']]),
        ]);
        $service->setManual($id, '2026-09-27', '60.0000000000', 'EUR');
        $result = $service->backfill($this->container->get(InstrumentRepository::class)->find($id) ?? [], new \DateTimeImmutable('2026-09-25'), new \DateTimeImmutable('2026-09-27'));

        self::assertSame(2, $result['inserted']);
        $history = $this->container->get(PriceRepository::class)->history($id, '2026-09-25', '2026-09-27');
        self::assertSame(['49', '50', '60'], array_column($history, 'close'));
        self::assertSame(['yahoo', 'coingecko', 'manual'], array_column($history, 'source'));
    }

    public function testFxUsesLastBusinessDayAndNeverFallsBackToOne(): void
    {
        $http = new FakeHttpFetcher([
            'date=05.01.2001' => "05.01.2001 #3\nzemě|měna|množství|kód|kurz\nEMU|euro|1|EUR|35,100\nMaďarsko|forint|100|HUF|13,500\n",
            'date=08.01.2001' => null,
        ]);
        $fx = new FxService(
            new CnbFxProvider($http, new Config(['prices' => ['cnb' => ['daily_url' => 'https://cnb.example.invalid/denni_kurz.txt']]])),
            $this->container->get(FxRateRepository::class),
            $this->container->get(TransactionFxRepository::class),
            $this->clock('2001-01-08 16:00:00'),
        );

        $report = $fx->sync(new \DateTimeImmutable('2001-01-05'), new \DateTimeImmutable('2001-01-08'));
        self::assertSame(1, $report['fetched']);
        self::assertSame(2, $report['skipped'], 'Sobota a neděle se nestahují.');
        self::assertCount(1, $report['errors']);

        self::assertSame('35.1', (string) $fx->rateToCzk('EUR', '2001-01-07')->strippedOfTrailingZeros(), 'Neděle → páteční kurz.');
        self::assertSame('0.135', (string) $fx->rateToCzk('HUF', '2001-01-05')->strippedOfTrailingZeros());
        self::assertSame('1', (string) $fx->rateToCzk('CZK', '1999-01-01'));
        self::assertSame('2', (string) $fx->convert(BigDecimal::of('70.2'), 'CZK', 'EUR', '2001-01-06')->strippedOfTrailingZeros());

        $this->expectException(FxRateMissing::class);
        $fx->rateToCzk('EUR', '2001-01-20');
    }

    public function testManualPriceApiPermissionsAndLastPriceInLists(): void
    {
        $auto = $this->instrument();
        $manual = $this->instrument('manual');

        $forbidden = $this->call($this->userId, 'POST', '/api/prices', ['instrument_id' => $auto, 'date' => '2026-09-01', 'close' => '10']);
        self::assertSame(403, $forbidden['status']);
        $ok = $this->call($this->userId, 'POST', '/api/prices', ['instrument_id' => $manual, 'date' => '2026-09-01', 'close' => '12,5']);
        self::assertSame(201, $ok['status'], json_encode($ok['body']) ?: '');
        self::assertSame('12.5', $ok['body']['last_price']['close']);
        self::assertSame('manual', $ok['body']['last_price']['source']);
        self::assertSame(201, $this->call($this->adminId, 'POST', '/api/prices', ['instrument_id' => $auto, 'date' => '2026-09-01', 'close' => '10'])['status']);

        $invalid = $this->call($this->adminId, 'POST', '/api/prices', ['instrument_id' => $auto, 'date' => '2999-01-01', 'close' => '-1']);
        self::assertSame(400, $invalid['status']);
        self::assertArrayHasKey('date', $invalid['body']['error']['fields']);
        self::assertArrayHasKey('close', $invalid['body']['error']['fields']);

        $list = $this->call($this->userId, 'GET', '/api/instruments?q=SYN');
        $byId = array_column($list['body'], 'last_price', 'id');
        self::assertSame('10', $byId[$auto]['close']);

        $history = $this->call($this->userId, 'GET', "/api/prices?instrument_id=$manual&from=2026-08-01&to=2026-09-30");
        self::assertSame([['date' => '2026-09-01', 'close' => '12.5', 'currency' => 'EUR', 'source' => 'manual']], $history['body']['data']);

        self::assertSame(403, $this->call($this->userId, 'GET', '/api/admin/market-status')['status']);
        self::assertSame(200, $this->call($this->adminId, 'GET', '/api/admin/market-status')['status']);
    }
}
