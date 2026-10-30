<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Catalog;

use DI\Container;
use MyPortfolio\Service\Catalog\IconStore;
use MyPortfolio\Service\Catalog\PublicUrlGuard;
use MyPortfolio\Service\Catalog\WebsiteIconFetcher;
use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Tests\Support\ApiTestCase;
use MyPortfolio\Tests\Unit\Service\Catalog\IconStoreTest;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UploadedFileFactory;

#[Group('integration')]
final class IconApiTest extends ApiTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/myportfolio-icon-api-' . bin2hex(random_bytes(4));
        $container = $this->container;
        self::assertInstanceOf(Container::class, $container);
        $container->set(IconStore::class, new IconStore($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private static function upload(string $bytes, string $name = 'icon.png'): UploadedFileInterface
    {
        return (new UploadedFileFactory())->createUploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name);
    }

    /** @return list<string> */
    private function files(): array
    {
        return array_map('basename', glob($this->dir . '/*.png') ?: []);
    }

    public function testAuthorUploadsReplacesAndRemovesIcon(): void
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Icon Bank', 'type' => 'bank']);
        self::assertSame(201, $inst['status']);
        self::assertNull($inst['body']['icon_url']);
        self::assertTrue($inst['body']['can_edit_icon']);
        self::assertArrayNotHasKey('created_by_user_id', $inst['body']);
        $id = $inst['body']['id'];

        $up = $this->call($this->userId, 'POST', "/api/institutions/$id/icon", null, ['icon' => self::upload(IconStoreTest::image(300, 300))]);
        self::assertSame(200, $up['status'], $up['raw']);
        $url = $up['body']['icon_url'];
        self::assertMatchesRegularExpression('#^/api/icons/[0-9a-f]{32}\.png$#', $url);
        self::assertCount(1, $this->files());

        $img = $this->call($this->userId, 'GET', $url);
        self::assertSame(200, $img['status']);
        self::assertSame(['image/png'], $img['headers']['Content-Type']);
        self::assertStringContainsString('immutable', $img['headers']['Cache-Control'][0]);
        self::assertSame(128, getimagesizefromstring($img['raw'])[0] ?? 0);

        // Seznam institucí i účty vrací URL ikony.
        $list = array_column($this->call($this->userId, 'GET', '/api/institutions')['body'], null, 'id');
        self::assertSame($url, $list[$id]['icon_url']);
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $id, 'name' => 'Main', 'currency' => 'CZK']);
        self::assertSame($url, $this->call($this->userId, 'GET', '/api/accounts')['body'][0]['institution_icon_url'], json_encode($acc['body']) ?: '');

        // Výměna uklidí starý soubor, odebrání i nový.
        $second = $this->call($this->userId, 'POST', "/api/institutions/$id/icon", null, ['icon' => self::upload(IconStoreTest::image(64, 32, 'jpeg'), 'a.jpg')]);
        self::assertSame(200, $second['status']);
        self::assertSame([basename($second['body']['icon_url'])], $this->files());
        self::assertSame(204, $this->call($this->userId, 'DELETE', "/api/institutions/$id/icon")['status']);
        self::assertSame([], $this->files());
        self::assertSame(404, $this->call($this->userId, 'GET', $url)['status']);
    }

    public function testOnlyAdminOrAuthorMayChangeIconAndInvalidFilesAreRejected(): void
    {
        $inst = $this->call($this->adminId, 'POST', '/api/institutions', ['name' => 'Synthetic Admin Exchange', 'type' => 'exchange']);
        $id = $inst['body']['id'];
        $asUser = array_column($this->call($this->userId, 'GET', '/api/institutions')['body'], null, 'id')[$id];
        self::assertFalse($asUser['can_edit_icon']);

        self::assertSame(403, $this->call($this->userId, 'POST', "/api/institutions/$id/icon", null, ['icon' => self::upload(IconStoreTest::image(32, 32))])['status']);
        self::assertSame(403, $this->call($this->userId, 'DELETE', "/api/institutions/$id/icon")['status']);
        self::assertSame(404, $this->call($this->adminId, 'POST', '/api/institutions/999999999/icon', null, ['icon' => self::upload(IconStoreTest::image(32, 32))])['status']);

        $svg = $this->call($this->adminId, 'POST', "/api/institutions/$id/icon", null, ['icon' => self::upload('<svg xmlns="http://www.w3.org/2000/svg"/>', 'x.svg')]);
        self::assertSame(400, $svg['status']);
        self::assertSame(400, $this->call($this->adminId, 'POST', "/api/institutions/$id/icon")['status']);
        self::assertSame([], $this->files());

        // Admin smí i cizí položku; instrumenty mají stejné API.
        $ins = $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => 'etf', 'symbol' => 'SYNICO', 'name' => 'Synthetic Icon ETF', 'quote_currency' => 'EUR', 'price_source' => 'manual',
        ]);
        self::assertSame(201, $ins['status'], json_encode($ins['body']) ?: '');
        $r = $this->call($this->adminId, 'POST', "/api/instruments/{$ins['body']['id']}/icon", null, ['icon' => self::upload(IconStoreTest::image(32, 32))]);
        self::assertSame(200, $r['status'], $r['raw']);
        $row = array_column($this->call($this->userId, 'GET', '/api/instruments?q=SYNICO')['body'], null, 'id')[$ins['body']['id']];
        self::assertSame($r['body']['icon_url'], $row['icon_url']);
    }

    public function testLibraryAndWebsiteIcons(): void
    {
        // Podvržené HTTP pro logo z webu (žádná síť) — dřív, než kontejner sestaví IconAction
        $container = $this->container;
        self::assertInstanceOf(Container::class, $container);
        $png = IconStoreTest::image(180, 180);
        $container->set(WebsiteIconFetcher::class, new WebsiteIconFetcher(
            new class ($png) implements HttpFetcher {
                public function __construct(private string $png) {}
                public function get(string $url, array $headers = []): string
                {
                    return $url === 'https://bank.example.invalid/apple-touch-icon.png' ? $this->png : throw new MarketDataException('404');
                }
            },
            new PublicUrlGuard(static fn () => ['93.184.216.34']),
        ));
        $lib = $this->call($this->userId, 'GET', '/api/icon-library');
        self::assertSame(200, $lib['status']);
        $btc = array_column($lib['body']['crypto'], null, 'symbol')['BTC'] ?? null;
        self::assertSame('/api/icon-library/crypto/btc.png', $btc['url'] ?? null);
        self::assertContains('Fio banka', array_column($lib['body']['institutions'], 'name'));
        self::assertSame(['image/png'], $this->call($this->userId, 'GET', '/api/icon-library/crypto/btc.png')['headers']['Content-Type']);

        $ins = $this->call($this->userId, 'POST', '/api/instruments', [
            'asset_class' => 'crypto', 'symbol' => 'SYNICO', 'name' => 'Synthetic icon coin', 'quote_currency' => 'EUR', 'price_source' => 'manual',
        ]);
        self::assertSame(201, $ins['status'], $ins['raw']);
        $set = $this->call($this->userId, 'POST', "/api/instruments/{$ins['body']['id']}/icon/library", ['symbol' => 'btc']);
        self::assertSame(200, $set['status'], $set['raw']);
        self::assertCount(1, $this->files());
        self::assertSame(404, $this->call($this->userId, 'POST', "/api/instruments/{$ins['body']['id']}/icon/library", ['symbol' => 'nope'])['status']);
        // Logo správce ETF
        self::assertContains('ishares', array_column($lib['body']['etf'], 'slug'));
        self::assertSame(200, $this->call($this->userId, 'GET', '/api/icon-library/etf/ishares.png')['status']);
        self::assertSame(200, $this->call($this->userId, 'POST', "/api/instruments/{$ins['body']['id']}/icon/library", ['etf' => 'vanguard'])['status']);
        // Cizí položku (autor admin) uživatel nemění ani z knihovny
        $foreign = $this->call($this->adminId, 'POST', '/api/instruments', [
            'asset_class' => 'crypto', 'symbol' => 'SYNICA', 'name' => 'Synthetic admin coin', 'quote_currency' => 'EUR', 'price_source' => 'manual',
        ]);
        self::assertSame(403, $this->call($this->userId, 'POST', "/api/instruments/{$foreign['body']['id']}/icon/library", ['symbol' => 'eth'])['status']);

        // Logo z webu: bez webu chyba; s webem přes podvržené HTTP (žádná síť)
        $bank = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Web Bank', 'type' => 'bank']);
        self::assertSame(400, $this->call($this->userId, 'POST', "/api/institutions/{$bank['body']['id']}/icon/website")['status']);
        // Přibalené logo instituce (web, odkud stažení nejde)
        $rb = array_column($lib['body']['institutions'], null, 'name')['Raiffeisenbank'];
        self::assertSame('/api/icon-library/institutions/raiffeisenbank.png', $rb['url']);
        self::assertSame(200, $this->call($this->userId, 'GET', $rb['url'])['status']);
        self::assertSame(200, $this->call($this->userId, 'POST', "/api/institutions/{$bank['body']['id']}/icon/library", ['institution' => $rb['icon']])['status']);
        self::assertSame(404, $this->call($this->userId, 'POST', "/api/institutions/{$bank['body']['id']}/icon/library", ['institution' => '../crypto/btc'])['status']);
        $web = $this->call($this->userId, 'POST', "/api/institutions/{$bank['body']['id']}/icon/website", ['url' => 'https://bank.example.invalid']);
        self::assertSame(200, $web['status'], $web['raw']);
        self::assertSame(400, $this->call($this->userId, 'POST', "/api/institutions/{$bank['body']['id']}/icon/website", ['url' => 'http://bank.example.invalid'])['status']);
    }
}
