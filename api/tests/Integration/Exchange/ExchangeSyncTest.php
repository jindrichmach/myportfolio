<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Exchange;

use DI\Container;
use MyPortfolio\Service\Exchange\ExchangeHttp;
use MyPortfolio\Tests\Support\ApiTestCase;
use MyPortfolio\Tests\Support\FakeExchangeHttp;
use PHPUnit\Framework\Attributes\Group;

/**
 * Napojení burzy přes API (fake Coinmate): ověření klíče, synchronizace bez duplicit, čekající náhled
 * s neznámým instrumentem a posun kurzoru po potvrzení, utajení klíče, izolace uživatelů.
 */
#[Group('integration')]
final class ExchangeSyncTest extends ApiTestCase
{
    private const CREDS = ['client_id' => '7', 'public_key' => 'SYNTHPUBKEY', 'private_key' => 'SYNTHPRIVKEY'];

    private FakeExchangeHttp $http;

    /** @var list<string> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeExchangeHttp(['#/api/balances#' => '{"error":false,"errorMessage":null,"data":{}}']);
        $container = $this->container;
        self::assertInstanceOf(Container::class, $container);
        $container->set(ExchangeHttp::class, $this->http);
    }

    private function serve(string ...$rows): void
    {
        $this->http->on('#/api/transactionHistory#', '{"error":false,"errorMessage":null,"data":[' . implode(',', $rows) . ']}');
    }

    private static function buy(int $id, string $at, string $symbol, string $qty, string $price): string
    {
        $ms = (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->getTimestamp() * 1000;
        return sprintf('{"timestamp":%d,"transactionId":%d,"transactionType":"BUY","price":%s,"priceCurrency":"CZK","amount":%s,"amountCurrency":"%s","fee":1,"feeCurrency":"CZK","description":"","status":"OK","orderId":null}',
            $ms, $id, $price, $qty, $symbol);
    }

    private function rowCount(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function testConnectSyncPendingAndIsolation(): void
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Coinmate', 'type' => 'exchange'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Spot', 'currency' => 'CZK'])['body']['id'];
        $pf = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Krypto'])['body']['id'];
        $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => 'crypto', 'symbol' => 'XTSA', 'name' => 'Synthetic Coin A', 'quote_currency' => 'CZK', 'price_source' => 'manual',
        ]);

        $list = $this->call($this->userId, 'GET', '/api/settings/exchanges')['body'];
        self::assertContains('coinmate', array_column($list['providers'], 'provider'));
        self::assertSame([], $list['connections']);

        // Špatný klíč → validace, nic se neuloží
        $this->http->on('#/api/balances#', '{"error":true,"errorMessage":"Access denied.","data":null}');
        $bad = $this->call($this->userId, 'POST', '/api/settings/exchanges', ['provider' => 'coinmate', 'account_id' => $acc, 'portfolio_id' => $pf, 'credentials' => self::CREDS]);
        self::assertSame(400, $bad['status'], $bad['raw']);
        self::assertArrayHasKey('credentials', $bad['body']['error']['fields']);
        $this->http->on('#/api/balances#', '{"error":false,"errorMessage":null,"data":{}}');

        $created = $this->call($this->userId, 'POST', '/api/settings/exchanges', [
            'provider' => 'coinmate', 'account_id' => $acc, 'portfolio_id' => $pf, 'credentials' => self::CREDS, 'start' => 'all',
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $id = $created['body']['connection']['id'];
        self::assertStringNotContainsString('SYNTHPRIVKEY', $created['raw']);
        self::assertStringNotContainsString('SYNTHPRIVKEY', $this->call($this->userId, 'GET', '/api/settings/exchanges')['raw']);
        self::assertStringNotContainsString('SYNTHPRIVKEY', (string) $this->db->pdo()->query("SELECT credentials_enc FROM exchange_connections WHERE id = $id")->fetchColumn());

        // 1. synchronizace: známý instrument → rovnou zapsáno
        $this->serve(self::buy(101, '2026-01-10 10:00:00', 'XTSA', '2', '100'));
        $s1 = $this->call($this->userId, 'POST', "/api/settings/exchanges/$id/sync");
        self::assertSame(200, $s1['status'], $s1['raw']);
        self::assertSame(['ok', 1], [$s1['body']['status'], $s1['body']['imported']]);
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND source = 'api:coinmate'", [$this->userId]));

        // 2. stejná data znovu → žádné duplicity
        $s2 = $this->call($this->userId, 'POST', "/api/settings/exchanges/$id/sync")['body'];
        self::assertSame(['ok', 0], [$s2['status'], $s2['imported']]);

        // 3. nový neznámý instrument → čeká na rozhodnutí, kurzor stojí
        $until = $s2['connection']['synced_until'];
        $this->serve(self::buy(101, '2026-01-10 10:00:00', 'XTSA', '2', '100'), self::buy(102, date('Y-m-d H:i:s', time() - 60), 'XTSZ', '5', '10'));
        $s3 = $this->call($this->userId, 'POST', "/api/settings/exchanges/$id/sync")['body'];
        self::assertSame('pending', $s3['status']);
        $token = $s3['pending_token'];
        self::assertSame($until, $s3['connection']['synced_until']);
        self::assertSame('pending', $this->call($this->userId, 'POST', "/api/settings/exchanges/$id/sync")['body']['status']);

        // Cizí uživatel napojení ani náhled nevidí
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/settings/exchanges')['body']['connections']);
        self::assertSame(404, $this->call($this->adminId, 'POST', "/api/settings/exchanges/$id/sync")['status']);
        self::assertSame(404, $this->call($this->adminId, 'DELETE', "/api/settings/exchanges/$id")['status']);
        self::assertSame(404, $this->call($this->adminId, 'GET', "/api/imports/preview/$token")['status']);

        // Potvrzení v Importu → zapsáno, kurzor posunut, stav ok
        $preview = $this->call($this->userId, 'GET', "/api/imports/preview/$token")['body'];
        $key = array_column($preview['assets'], 'key', 'symbol')['XTSZ'];
        $ok = $this->call($this->userId, 'POST', "/api/imports/preview/$token/confirm", ['assets' => [$key => ['create' => ['name' => 'Synthetic Coin Z', 'quote_currency' => 'CZK']]]]);
        self::assertSame(200, $ok['status'], $ok['raw']);
        self::assertSame(1, $ok['body']['imported']);
        $conn = $this->call($this->userId, 'GET', '/api/settings/exchanges')['body']['connections'][0];
        self::assertSame(['ok', null], [$conn['last_status'], $conn['pending_token']]);
        self::assertGreaterThanOrEqual($until, $conn['synced_until']);

        // Zpožděný pohyb z překryvu (starší než kurzor) s neznámým instrumentem se nezahodí — čeká na rozhodnutí
        $late = self::buy(103, date('Y-m-d H:i:s', strtotime((string) $conn['synced_until']) - 3 * 86400), 'XTSL', '1', '50');
        $this->serve($late);
        $s4 = $this->call($this->userId, 'POST', "/api/settings/exchanges/$id/sync")['body'];
        self::assertSame('pending', $s4['status']);
        // Uživatel ho vynechá → zapamatuje se a příští synchronizace ho přeskočí bez ptaní
        $p4 = $this->call($this->userId, 'GET', "/api/imports/preview/{$s4['pending_token']}")['body'];
        $keyL = array_column($p4['assets'], 'key', 'symbol')['XTSL'];
        self::assertSame(200, $this->call($this->userId, 'POST', "/api/imports/preview/{$s4['pending_token']}/confirm", ['assets' => [$keyL => ['skip' => true]]])['status']);
        $s5 = $this->call($this->userId, 'POST', "/api/settings/exchanges/$id/sync")['body'];
        self::assertSame(['ok', 0], [$s5['status'], $s5['imported']]);

        self::assertSame(204, $this->call($this->userId, 'DELETE', "/api/settings/exchanges/$id")['status']);
        self::assertSame(2, $this->rowCount("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND source = 'api:coinmate'", [$this->userId]));
    }
}
