<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Catalog;

use MyPortfolio\Service\Catalog\PublicUrlGuard;
use MyPortfolio\Service\Catalog\WebsiteIconFetcher;
use MyPortfolio\Service\Market\HttpFetcher;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Service\Portfolio\DomainError;
use PHPUnit\Framework\TestCase;

final class WebsiteIconFetcherTest extends TestCase
{
    /** @param array<string,string> $responses URL → tělo; ostatní = síťová chyba */
    private static function fetcher(array $responses, array &$requested = []): WebsiteIconFetcher
    {
        $http = new class ($responses, $requested) implements HttpFetcher {
            /** @param array<string,string> $responses */
            public function __construct(private array $responses, private array &$requested) {}
            public function get(string $url, array $headers = []): string
            {
                $this->requested[] = $url;
                return $this->responses[$url] ?? throw new MarketDataException("404 $url");
            }
        };
        return new WebsiteIconFetcher($http, new PublicUrlGuard(static fn (string $host) => $host === 'intranet.example' ? ['10.0.0.5'] : ['93.184.216.34']));
    }

    public function testPicksLargestUsableIconFromHtml(): void
    {
        $html = '<head><link rel="icon" href="/favicon.ico"><link rel="icon" type="image/svg+xml" href="/logo.svg">'
            . '<link rel="icon" sizes="32x32" href="/f32.png"><link rel="apple-touch-icon" sizes="180x180" href="/apple.png"></head>';
        $requested = [];
        $bytes = self::fetcher([
            'https://bank.example/' => $html,
            'https://bank.example/apple.png' => IconStoreTest::image(180, 180),
        ], $requested)->fetch('https://bank.example');

        self::assertSame(180, getimagesizefromstring($bytes)[0] ?? 0);
        self::assertSame(['https://bank.example/', 'https://bank.example/apple.png'], $requested); // SVG a ICO se nezkouší
    }

    public function testFallsBackToAppleTouchIconPath(): void
    {
        $bytes = self::fetcher(['https://bank.example/apple-touch-icon.png' => IconStoreTest::image(60, 60)])->fetch('https://bank.example/');
        self::assertSame(60, getimagesizefromstring($bytes)[0] ?? 0);
    }

    public function testIconLinksResolveRelativeAndProtocolRelativeUrls(): void
    {
        $links = WebsiteIconFetcher::iconLinks('<link href="//cdn.example/i.png" rel="shortcut icon"><link rel="icon" href="img/a.png?v=2&amp;x=1">', 'https://bank.example/cs/');
        self::assertSame(['https://cdn.example/i.png', 'https://bank.example/cs/img/a.png?v=2&x=1'], $links);
    }

    public function testRejectsNonHttpsAndPrivateAddresses(): void
    {
        foreach (['http://bank.example', 'https://intranet.example', 'https://127.0.0.1/', 'https://192.168.1.10', 'https://bank.example:8443', 'ftp://bank.example'] as $url) {
            try {
                self::fetcher([])->fetch($url);
                self::fail("Mělo selhat: $url");
            } catch (DomainError $e) {
                self::assertSame(400, $e->status, $url);
            }
        }
    }

    public function testFailsWhenNoImageFound(): void
    {
        $this->expectException(DomainError::class);
        self::fetcher(['https://bank.example/' => '<link rel="icon" href="/x.png">', 'https://bank.example/x.png' => 'not an image'])->fetch('https://bank.example');
    }

    public function testExtractsLargestPngFromIco(): void
    {
        $small = IconStoreTest::image(16, 16);
        $big = IconStoreTest::image(48, 48);
        $header = pack('vvv', 0, 1, 2);
        $offset = 6 + 2 * 16;
        $dir = pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, strlen($small), $offset)
            . pack('CCCCvvVV', 48, 48, 0, 0, 1, 32, strlen($big), $offset + strlen($small));
        $ico = $header . $dir . $small . $big;

        self::assertSame($big, WebsiteIconFetcher::pngFromIco($ico));
        self::assertNull(WebsiteIconFetcher::pngFromIco($big));
        $bytes = self::fetcher(['https://bank.example/favicon.ico' => $ico])->fetch('https://bank.example');
        self::assertSame(48, getimagesizefromstring($bytes)[0] ?? 0);
    }
}
