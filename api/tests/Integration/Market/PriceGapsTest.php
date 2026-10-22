<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Market;

use MyPortfolio\Repository\PriceRepository;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Mezery uvnitř historie cen (cron neběžel) — podklad pro doplnění v PriceService::backfillUsed. */
#[Group('integration')]
final class PriceGapsTest extends ApiTestCase
{
    public function testFindsGapsLongerThanLimit(): void
    {
        $id = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => 'etf', 'symbol' => 'SYNG', 'name' => 'Synthetic Gap ETF',
            'quote_currency' => 'CZK', 'price_source' => 'manual'])['body']['id'];
        $ins = $this->db->pdo()->prepare("INSERT INTO prices (instrument_id, date, close, currency, source) VALUES (?, ?, '100', 'CZK', 'manual')");
        foreach (['2026-09-01', '2026-09-04', '2026-09-07', '2026-09-18', '2026-09-19'] as $date) {
            $ins->execute([$id, $date]);
        }
        $repo = $this->container->get(PriceRepository::class);
        self::assertSame([['from' => '2026-09-08', 'to' => '2026-09-17']], $repo->gaps($id, '2026-08-01', 7));
        self::assertSame([['from' => '2026-09-02', 'to' => '2026-09-03'], ['from' => '2026-09-05', 'to' => '2026-09-06'],
            ['from' => '2026-09-08', 'to' => '2026-09-17']], $repo->gaps($id, '2026-08-01', 2));
        self::assertSame([], $repo->gaps($id, '2026-09-18', 2));
    }
}
