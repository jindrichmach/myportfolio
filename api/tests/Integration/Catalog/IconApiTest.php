<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Catalog;

use DI\Container;
use MyPortfolio\Service\Catalog\IconStore;
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
}
