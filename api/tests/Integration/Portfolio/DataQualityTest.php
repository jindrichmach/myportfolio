<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Portfolio;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Stránka Kvalita dat: chybějící cena drženého instrumentu a neúplná nabývací cena; jen vlastní data. */
#[Group('integration')]
final class DataQualityTest extends ApiTestCase
{
    public function testReportsMissingPriceAndIncompleteCost(): void
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic DQ', 'type' => 'exchange'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Wallet', 'currency' => 'CZK'])['body']['id'];
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'DQ'])['body']['id'];
        $ins = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => 'crypto', 'symbol' => 'SYNQ', 'name' => 'Synthetic Q',
            'quote_currency' => 'CZK', 'price_source' => 'manual'])['body']['id'];
        $pos = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'market', 'portfolio_id' => $pf, 'account_id' => $acc,
            'instrument_id' => $ins])['body']['id'];
        // Odměna bez částky a bez ceny instrumentu → lot s nulovou nabývací cenou, pozice bez ceny
        $r = $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pos, 'type' => 'reward', 'executed_at' => '2026-01-10 10:00', 'quantity' => '1']);
        self::assertSame(201, $r['status'], $r['raw']);

        $q = $this->call($this->userId, 'GET', '/api/data-quality');
        self::assertSame(200, $q['status'], $q['raw']);
        self::assertSame([['reward_without_value', $r['body']['id'], $pos, 'SYNQ']],
            array_map(static fn (array $c) => [$c['code'], $c['transaction_id'], $c['position_id'], $c['symbol']], $q['body']['costs']));
        self::assertSame(['missing_price'], array_column(array_filter($q['body']['prices'], static fn (array $p) => $p['symbol'] === 'SYNQ'), 'kind'));
        self::assertGreaterThanOrEqual(2, $q['body']['total']);

        $foreign = $this->call($this->adminId, 'GET', '/api/data-quality')['body'];
        self::assertSame([], $foreign['costs']);
    }
}
