<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Import;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UploadedFileFactory;

/**
 * Import exportu burzy přes API: náhled → potvrzení (nový instrument, převod) → opakovaný import bez duplicit → revert.
 * Syntetický Coinbase export se smyšlenými symboly XTSA / XTSB.
 */
#[Group('integration')]
final class CsvImportTest extends ApiTestCase
{
    private const CSV = "ID,Timestamp,Transaction Type,Asset,Quantity Transacted,Price Currency,Price at Transaction,Subtotal,Total (inclusive of fees and/or spread),Fees and/or Spread,Notes\n"
        . "syn-0001,2024-01-10 09:00:00 UTC,Deposit,EUR,500,EUR,€1.00,€500.00,€500.00,€0.00,Deposit from EXAMPLE BANK\n"
        . "syn-0002,2024-01-10 10:00:00 UTC,Buy,XTSA,2,EUR,€10.00,€20.00,€21.00,€1.00,\n"
        . "syn-0003,2024-01-11 10:00:00 UTC,Buy,XTSB,5,EUR,€4.00,€20.00,€20.50,€0.50,\n"
        . "syn-0004,2024-01-12 10:00:00 UTC,Send,XTSA,-0.5,EUR,€10.00,-€5.00,-€5.00,€0.00,Sent 0.5 XTSA to example-address\n";

    private static function upload(): UploadedFileInterface
    {
        return (new UploadedFileFactory())->createUploadedFile((new StreamFactory())->createStream(self::CSV), strlen(self::CSV), UPLOAD_ERR_OK, 'coinbase.csv');
    }

    private function rowCount(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function testPreviewConfirmReimportAndRevert(): void
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Exchange', 'type' => 'exchange'])['body']['id'];
        $accA = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Spot', 'currency' => 'EUR'])['body']['id'];
        $accB = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Cold wallet', 'currency' => 'EUR'])['body']['id'];
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Krypto'])['body']['id'];
        $xtsb = $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => 'crypto', 'symbol' => 'XTSB', 'name' => 'Synthetic Coin B', 'quote_currency' => 'EUR', 'price_source' => 'manual',
        ])['body']['id'];
        $form = ['account_id' => $accA, 'portfolio_id' => $pf];

        $preview = $this->call($this->userId, 'POST', '/api/imports/preview', $form, ['file' => self::upload()]);
        self::assertSame(200, $preview['status'], $preview['raw']);
        $p = $preview['body'];
        self::assertSame('coinbase', $p['source']['key']);
        self::assertSame(['rows' => 4, 'new' => 3, 'duplicate' => 0, 'paired' => 0, 'skipped' => 1, 'transfers' => 1], $p['summary']);
        $assets = array_column($p['assets'], null, 'symbol');
        self::assertNull($assets['XTSA']['instrument_id']);
        self::assertSame($xtsb, $assets['XTSB']['instrument_id']);
        $transfer = $p['transfers'][0];

        // Neznámý instrument bez rozhodnutí → chyba, nic se nezapíše
        $bad = $this->call($this->userId, 'POST', "/api/imports/preview/{$p['token']}/confirm", ['assets' => []]);
        self::assertSame(400, $bad['status']);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM transactions WHERE user_id = ?', [$this->userId]));

        // Cizí uživatel náhled nevidí
        self::assertSame(404, $this->call($this->adminId, 'GET', "/api/imports/preview/{$p['token']}")['status']);

        $ok = $this->call($this->userId, 'POST', "/api/imports/preview/{$p['token']}/confirm", [
            'assets'    => [$assets['XTSA']['key'] => ['create' => ['name' => 'Synthetic Coin A', 'quote_currency' => 'EUR']]],
            'transfers' => [(string) $transfer['index'] => ['account_id' => $accB]],
        ]);
        self::assertSame(200, $ok['status'], $ok['raw']);
        self::assertSame(3, $ok['body']['imported']);
        self::assertSame(1, $ok['body']['transfers']);
        $batch = $ok['body']['batch_id'];
        self::assertSame(4, $this->rowCount('SELECT COUNT(*) FROM transactions WHERE user_id = ? AND import_batch_id = ?', [$this->userId, $batch]));
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM transactions t JOIN positions p ON p.id = t.position_id
            WHERE t.user_id = ? AND p.account_id = ? AND t.type = 'transfer_in' AND t.quantity = 0.5", [$this->userId, $accB]));
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = 'buy' AND fee = 1 AND fee_currency = 'EUR' AND amount = 20", [$this->userId]));

        // Opakovaný import téhož souboru: vše duplicitní
        $again = $this->call($this->userId, 'POST', '/api/imports/preview', $form, ['file' => self::upload()])['body'];
        self::assertSame(['rows' => 4, 'new' => 0, 'duplicate' => 3, 'paired' => 0, 'skipped' => 1, 'transfers' => 0], $again['summary']);
        $none = $this->call($this->userId, 'POST', "/api/imports/preview/{$again['token']}/confirm", [])['body'];
        self::assertSame(0, $none['imported']);
        self::assertNull($none['batch_id']);

        $list = $this->call($this->userId, 'GET', '/api/imports')['body'];
        self::assertCount(1, $list);
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/imports')['body']);
        self::assertSame(404, $this->call($this->adminId, 'POST', "/api/imports/$batch/revert")['status']);

        $revert = $this->call($this->userId, 'POST', "/api/imports/$batch/revert");
        self::assertSame(200, $revert['status'], $revert['raw']);
        self::assertSame(4, $revert['body']['deleted']);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM transactions WHERE user_id = ?', [$this->userId]));
        self::assertSame(409, $this->call($this->userId, 'POST', "/api/imports/$batch/revert")['status']);
    }

    public function testPairsManuallyEnteredTransactions(): void
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Broker', 'type' => 'exchange'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Spot', 'currency' => 'CZK'])['body']['id'];
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Krypto'])['body']['id'];
        $ins = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => 'crypto', 'symbol' => 'XTSP', 'name' => 'Synthetic Coin P',
            'quote_currency' => 'CZK', 'price_source' => 'manual'])['body']['id'];
        $pos = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'market', 'portfolio_id' => $pf, 'account_id' => $acc,
            'instrument_id' => $ins])['body']['id'];
        $buy = fn (string $at, string $qty) => $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pos, 'type' => 'buy',
            'executed_at' => $at, 'quantity' => $qty, 'amount' => '1000', 'note' => 'ručně'])['body']['id'];
        $manual = $buy('2025-03-01 11:00', '0.5');     // ručně zadaný čas o hodinu vedle (jiná zóna)
        $other = $buy('2025-03-01 10:00', '0.25');     // jiné množství → nespáruje se

        $csv = "Date,Type,Amount,Currency,Order ID,anycoin TX ID,Description\n"
            . "2025-03-01T09:00:05Z,trade payment,-1000.5,CZK,700001,8000001,\n"
            . "2025-03-01T09:00:10Z,trade fill,0.5,XTSP,700001,8000002,\n"
            . "2025-03-02T09:00:00Z,trade payment,-600,CZK,700002,8000003,\n"
            . "2025-03-02T09:00:01Z,trade fill,0.3,XTSP,700002,8000004,\n";
        $file = static fn () => (new UploadedFileFactory())->createUploadedFile((new StreamFactory())->createStream($csv), strlen($csv), UPLOAD_ERR_OK, 'anycoin.csv');
        $form = ['account_id' => $acc, 'portfolio_id' => $pf];

        $p = $this->call($this->userId, 'POST', '/api/imports/preview', $form, ['file' => $file()])['body'];
        self::assertSame(['rows' => 2, 'new' => 1, 'duplicate' => 0, 'paired' => 1, 'skipped' => 0, 'transfers' => 0], $p['summary']);
        self::assertSame(['id' => $manual, 'executed_at' => '2025-03-01 11:00:00', 'relink' => true], $p['rows'][0]['paired']);

        $ok = $this->call($this->userId, 'POST', "/api/imports/preview/{$p['token']}/confirm", [])['body'];
        self::assertSame([1, 1], [$ok['imported'], $ok['paired']]);
        $tx = $this->db->pdo()->prepare('SELECT executed_at, amount, source, external_id, note, import_batch_id FROM transactions WHERE id = ?');
        $tx->execute([$manual]);
        self::assertSame(['2025-03-01 10:00:10', '1000.5000', 'csv:anycoin', 'order:700001', 'ručně', null], array_values($tx->fetch(\PDO::FETCH_ASSOC)));
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM transactions WHERE id = ? AND source = 'manual' AND external_id IS NULL", [$other]));

        $again = $this->call($this->userId, 'POST', '/api/imports/preview', $form, ['file' => $file()])['body'];
        self::assertSame(['rows' => 2, 'new' => 0, 'duplicate' => 2, 'paired' => 0, 'skipped' => 0, 'transfers' => 0], $again['summary']);

        // Vrácení dávky smaže jen nově zapsaný řádek, spárovaná transakce zůstane
        self::assertSame(1, $this->call($this->userId, 'POST', "/api/imports/{$ok['batch_id']}/revert")['body']['deleted']);
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM transactions WHERE user_id = ?', [$this->userId]));
    }

    public function testNewRowsUseExistingAccountPositionFromAnotherPortfolio(): void
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Broker Q', 'type' => 'exchange'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Spot', 'currency' => 'CZK'])['body']['id'];
        $crypto = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Krypto'])['body']['id'];
        $other = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Akcie'])['body']['id'];
        $ins = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => 'crypto', 'symbol' => 'XTSQ', 'name' => 'Synthetic Coin Q',
            'quote_currency' => 'CZK', 'price_source' => 'manual'])['body']['id'];
        $pos = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'market', 'portfolio_id' => $crypto, 'account_id' => $acc,
            'instrument_id' => $ins])['body']['id'];
        $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $pos, 'type' => 'buy',
            'executed_at' => '2025-03-01 10:00', 'quantity' => '0.5', 'amount' => '1000']);

        $csv = "Date,Type,Amount,Currency,Order ID,anycoin TX ID,Description\n"
            . "2025-03-02T09:00:00Z,trade payment,-600,CZK,700101,8000101,\n"
            . "2025-03-02T09:00:01Z,trade fill,0.3,XTSQ,700101,8000102,\n";
        $file = (new UploadedFileFactory())->createUploadedFile((new StreamFactory())->createStream($csv), strlen($csv), UPLOAD_ERR_OK, 'anycoin.csv');
        $p = $this->call($this->userId, 'POST', '/api/imports/preview', ['account_id' => $acc, 'portfolio_id' => $other], ['file' => $file])['body'];
        $ok = $this->call($this->userId, 'POST', "/api/imports/preview/{$p['token']}/confirm", [])['body'];

        self::assertSame(1, $ok['imported']);
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM transactions WHERE position_id = ?', [$pos]));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM positions WHERE portfolio_id = ?', [$other]));
    }

    public function testUnknownFormatAndForeignAccount(): void
    {
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'X'])['body']['id'];
        $inst = $this->call($this->adminId, 'POST', '/api/institutions', ['name' => 'Admin Bank', 'type' => 'bank'])['body']['id'];
        $foreign = $this->call($this->adminId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'A', 'currency' => 'CZK'])['body']['id'];
        $r = $this->call($this->userId, 'POST', '/api/imports/preview', ['account_id' => $foreign, 'portfolio_id' => $pf], ['file' => self::upload()]);
        self::assertSame(400, $r['status']);
        self::assertArrayHasKey('account_id', $r['body']['error']['fields']);

        $junk = (new UploadedFileFactory())->createUploadedFile((new StreamFactory())->createStream("a,b\n1,2\n"), 8, UPLOAD_ERR_OK, 'x.csv');
        $own = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Own', 'type' => 'exchange'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $own, 'name' => 'A', 'currency' => 'EUR'])['body']['id'];
        self::assertSame(400, $this->call($this->userId, 'POST', '/api/imports/preview', ['account_id' => $acc, 'portfolio_id' => $pf], ['file' => $junk])['status']);
    }
}
