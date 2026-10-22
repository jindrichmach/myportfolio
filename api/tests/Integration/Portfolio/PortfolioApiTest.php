<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Portfolio;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class PortfolioApiTest extends ApiTestCase
{
    /** @return array{institution:int,instrument:int,account:int,portfolio:int,position:int} */
    private function setupMarketPosition(int $asUser, string $symbol = 'SYNTH'): array
    {
        $institution = $this->call($asUser, 'POST', '/api/institutions', ['name' => 'Synthetic Exchange ' . $symbol, 'type' => 'exchange']);
        self::assertSame(201, $institution['status'], json_encode($institution['body']) ?: '');
        $instrument = $this->call($asUser, 'POST', '/api/instruments', [
            'asset_class' => 'crypto', 'symbol' => $symbol, 'name' => 'Synthetic coin', 'quote_currency' => 'EUR',
            'price_source' => 'coingecko', 'source_refs' => ['coingecko' => 'synthetic-coin'],
        ]);
        self::assertSame(201, $instrument['status'], json_encode($instrument['body']) ?: '');
        $account = $this->call($asUser, 'POST', '/api/accounts', ['institution_id' => $institution['body']['id'], 'name' => 'Main', 'currency' => 'EUR']);
        self::assertSame(201, $account['status']);
        $portfolio = $this->call($asUser, 'POST', '/api/portfolios', ['name' => 'Long term ' . $symbol]);
        self::assertSame(201, $portfolio['status']);
        $position = $this->call($asUser, 'POST', '/api/positions', [
            'valuation_mode' => 'market',
            'portfolio_id'   => $portfolio['body']['id'],
            'account_id'     => $account['body']['id'],
            'instrument_id'  => $instrument['body']['id'],
        ]);
        self::assertSame(201, $position['status'], json_encode($position['body']) ?: '');
        self::assertSame('EUR', $position['body']['currency']);

        return [
            'institution' => $institution['body']['id'],
            'instrument'  => $instrument['body']['id'],
            'account'     => $account['body']['id'],
            'portfolio'   => $portfolio['body']['id'],
            'position'    => $position['body']['id'],
        ];
    }

    public function testBuySellUpdatesHoldingAndComputesMissingPrice(): void
    {
        $ids = $this->setupMarketPosition($this->userId);
        $buy = $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => '2024-01-10 10:00',
            'quantity' => '0,5', 'amount' => '20000', 'fee' => '12.5',
        ]);
        self::assertSame(201, $buy['status'], json_encode($buy['body']) ?: '');
        self::assertSame('40000', $buy['body']['unit_price']);
        self::assertSame('EUR', $buy['body']['fee_currency']);

        $sell = $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'sell', 'executed_at' => '2024-02-01',
            'quantity' => '0.2', 'unit_price' => '45000',
        ]);
        self::assertSame(201, $sell['status']);
        self::assertSame('9000', $sell['body']['amount']);

        $position = $this->call($this->userId, 'GET', '/api/positions/' . $ids['position']);
        self::assertSame('0.3', $position['body']['holding']);
    }

    public function testSellingMoreThanHeldIsRejected(): void
    {
        $ids = $this->setupMarketPosition($this->userId);
        $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => '2024-03-01', 'quantity' => '1', 'amount' => '100',
        ]);
        // Prodej před nákupem — v čase by stav klesl pod nulu.
        $early = $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'sell', 'executed_at' => '2024-02-01', 'quantity' => '1', 'amount' => '100',
        ]);
        self::assertSame(422, $early['status']);
        self::assertSame('insufficient_quantity', $early['body']['error']['code']);

        $tooMuch = $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'sell', 'executed_at' => '2024-04-01', 'quantity' => '1.0000001', 'amount' => '100',
        ]);
        self::assertSame(422, $tooMuch['status']);
        self::assertSame(1, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM transactions WHERE position_id = ' . $ids['position'])->fetchColumn());
    }

    public function testFloatAmountsAreRejected(): void
    {
        $ids = $this->setupMarketPosition($this->userId);
        $r = $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => '2024-01-10', 'quantity' => 0.5, 'amount' => '10',
        ]);
        self::assertSame(400, $r['status']);
        self::assertArrayHasKey('quantity', $r['body']['error']['fields']);
    }

    public function testOtherUserCannotSeeOrUseForeignData(): void
    {
        $ids = $this->setupMarketPosition($this->userId);
        $tx = $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => '2024-01-10', 'quantity' => '1', 'amount' => '10',
        ]);

        self::assertSame(404, $this->call($this->adminId, 'GET', '/api/positions/' . $ids['position'])['status']);
        self::assertSame(404, $this->call($this->adminId, 'GET', '/api/accounts/' . $ids['account'])['status']);
        self::assertSame(404, $this->call($this->adminId, 'GET', '/api/transactions/' . $tx['body']['id'])['status']);
        self::assertSame(404, $this->call($this->adminId, 'DELETE', '/api/transactions/' . $tx['body']['id'])['status']);
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/positions')['body']);
        self::assertSame(0, $this->call($this->adminId, 'GET', '/api/transactions')['body']['meta']['total']);

        // Cizí pozice / účet / portfolio jako vazba → 404 resp. validační chyba, nikdy zápis.
        $foreignTx = $this->call($this->adminId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => '2024-01-10', 'quantity' => '1', 'amount' => '10',
        ]);
        self::assertSame(404, $foreignTx['status']);
        $foreignPosition = $this->call($this->adminId, 'POST', '/api/positions', [
            'valuation_mode' => 'market', 'portfolio_id' => $ids['portfolio'], 'account_id' => $ids['account'], 'instrument_id' => $ids['instrument'],
        ]);
        self::assertSame(400, $foreignPosition['status']);
        self::assertArrayHasKey('portfolio_id', $foreignPosition['body']['error']['fields']);
        self::assertArrayHasKey('account_id', $foreignPosition['body']['error']['fields']);
    }

    public function testTransferCreatesPairAndDeletingOneLegRemovesBoth(): void
    {
        $ids = $this->setupMarketPosition($this->userId);
        $wallet = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $ids['institution'], 'name' => 'Cold wallet', 'currency' => 'EUR']);
        $target = $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'market', 'portfolio_id' => $ids['portfolio'], 'account_id' => $wallet['body']['id'], 'instrument_id' => $ids['instrument'],
        ]);
        $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => '2024-01-10', 'quantity' => '2', 'amount' => '100',
        ]);

        $tooMuch = $this->call($this->userId, 'POST', '/api/transfers', [
            'from_position_id' => $ids['position'], 'to_position_id' => $target['body']['id'], 'quantity' => '3', 'executed_at' => '2024-02-01',
        ]);
        self::assertSame(422, $tooMuch['status']);

        $transfer = $this->call($this->userId, 'POST', '/api/transfers', [
            'from_position_id' => $ids['position'], 'to_position_id' => $target['body']['id'],
            'quantity' => '1.5', 'executed_at' => '2024-02-01', 'fee' => '0.0001',
        ]);
        self::assertSame(201, $transfer['status'], json_encode($transfer['body']) ?: '');
        self::assertSame(['transfer_out', 'transfer_in'], array_column($transfer['body']['transactions'], 'type'));
        self::assertSame('0.5', $this->call($this->userId, 'GET', '/api/positions/' . $ids['position'])['body']['holding']);
        self::assertSame('1.5', $this->call($this->userId, 'GET', '/api/positions/' . $target['body']['id'])['body']['holding']);

        $edit = $this->call($this->userId, 'PUT', '/api/transactions/' . $transfer['body']['transactions'][1]['id'], ['type' => 'buy']);
        self::assertSame(409, $edit['status']);

        $delete = $this->call($this->userId, 'DELETE', '/api/transactions/' . $transfer['body']['transactions'][1]['id']);
        self::assertSame(204, $delete['status']);
        self::assertSame(0, $this->call($this->userId, 'GET', '/api/transactions?type=transfer_out')['body']['meta']['total']);
        self::assertSame('2', $this->call($this->userId, 'GET', '/api/positions/' . $ids['position'])['body']['holding']);
    }

    public function testManualPositionTracksDepositsAndRejectsOverdraw(): void
    {
        $bank = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Bank', 'type' => 'bank']);
        $account = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $bank['body']['id'], 'name' => 'Savings']);
        $portfolio = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Cash']);
        $position = $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'manual', 'portfolio_id' => $portfolio['body']['id'], 'account_id' => $account['body']['id'],
            'name' => 'Spořicí účet', 'interest_rate_pa' => '3,5',
        ]);
        self::assertSame(201, $position['status'], json_encode($position['body']) ?: '');
        self::assertSame('CZK', $position['body']['currency']);
        self::assertSame('3.5', $position['body']['interest_rate_pa']);

        $pid = $position['body']['id'];
        self::assertSame(201, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pid, 'type' => 'deposit', 'executed_at' => '2024-01-01', 'amount' => '100 000'])['status']);
        self::assertSame(400, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pid, 'type' => 'buy', 'executed_at' => '2024-01-02', 'quantity' => '1', 'amount' => '1'])['status']);
        self::assertSame(422, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pid, 'type' => 'withdrawal', 'executed_at' => '2024-01-03', 'amount' => '100000.01'])['status']);
        self::assertSame('100000', $this->call($this->userId, 'GET', '/api/positions/' . $pid)['body']['holding']);
    }

    public function testDeletingUsedEntitiesIsRejected(): void
    {
        $ids = $this->setupMarketPosition($this->userId);
        $this->call($this->userId, 'POST', '/api/transactions', [
            'position_id' => $ids['position'], 'type' => 'buy', 'executed_at' => '2024-01-10', 'quantity' => '1', 'amount' => '10',
        ]);
        self::assertSame(409, $this->call($this->userId, 'DELETE', '/api/positions/' . $ids['position'])['status']);
        self::assertSame(409, $this->call($this->userId, 'DELETE', '/api/accounts/' . $ids['account'])['status']);
        self::assertSame(409, $this->call($this->userId, 'DELETE', '/api/portfolios/' . $ids['portfolio'])['status']);
        self::assertSame(409, $this->call($this->adminId, 'DELETE', '/api/admin/instruments/' . $ids['instrument'])['status']);

        $archive = $this->call($this->userId, 'PUT', '/api/positions/' . $ids['position'], [
            'portfolio_id' => $ids['portfolio'], 'account_id' => $ids['account'], 'archived' => true,
        ]);
        self::assertSame(200, $archive['status']);
        self::assertNotNull($archive['body']['archived_at']);
        self::assertSame([], $this->call($this->userId, 'GET', '/api/positions')['body']);
    }

    public function testCatalogUserCreatesButOnlyAdminEdits(): void
    {
        $created = $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => 'etf', 'symbol' => 'synth', 'name' => 'Synthetic ETF', 'quote_currency' => 'usd', 'isin' => 'IE00B5BMR087',
        ]);
        self::assertSame(201, $created['status'], json_encode($created['body']) ?: '');
        self::assertSame('SYNTH', $created['body']['symbol']);
        self::assertSame('USD', $created['body']['quote_currency']);

        $dup = $this->call($this->adminId, 'POST', '/api/instruments', [
            'asset_class' => 'etf', 'symbol' => 'SYNTH', 'name' => 'Duplicate', 'quote_currency' => 'USD',
        ]);
        self::assertSame(409, $dup['status']);

        $id = $created['body']['id'];
        $payload = ['asset_class' => 'etf', 'symbol' => 'SYNTH', 'name' => 'Renamed', 'quote_currency' => 'USD'];
        self::assertSame(403, $this->call($this->userId, 'PUT', '/api/admin/instruments/' . $id, $payload)['status']);
        $edited = $this->call($this->adminId, 'PUT', '/api/admin/instruments/' . $id, $payload);
        self::assertSame(200, $edited['status']);
        self::assertSame('Renamed', $edited['body']['name']);
        self::assertSame(204, $this->call($this->adminId, 'DELETE', '/api/admin/instruments/' . $id)['status']);
    }
}
