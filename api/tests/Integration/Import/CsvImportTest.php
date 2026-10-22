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
        self::assertSame(['rows' => 4, 'new' => 3, 'duplicate' => 0, 'skipped' => 1, 'transfers' => 1], $p['summary']);
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
        self::assertSame(['rows' => 4, 'new' => 0, 'duplicate' => 3, 'skipped' => 1, 'transfers' => 0], $again['summary']);
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
