<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Catalog;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use MyPortfolio\Service\Catalog\GuzzleIconHttp;
use MyPortfolio\Service\Catalog\PublicUrlGuard;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Service\Portfolio\DomainError;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Stažení loga: připnutí na ověřenou IP, přesměrování přes kontrolu SSRF, limit velikosti bez Content-Length. */
final class GuzzleIconHttpTest extends TestCase
{
    private const DNS = [
        'bank.example'   => ['203.0.113.10'],
        'cdn.example'    => ['2001:db8:85a3::8a2e:370:7334', '203.0.113.20'],
        'intranet.example' => ['10.0.0.5'],
        'tailnet.example'  => ['100.101.102.103'],
    ];

    private function guard(): PublicUrlGuard
    {
        return new PublicUrlGuard(static fn (string $host): array => self::DNS[$host] ?? []);
    }

    /** @param list<callable> $responses */
    private function http(array $responses, ?MockHandler &$mock = null): GuzzleIconHttp
    {
        $mock = new MockHandler($responses);
        return new GuzzleIconHttp($this->guard(), $mock);
    }

    public function testConnectionIsPinnedToCheckedAddressAcrossRedirect(): void
    {
        $pinned = [];
        $http = $this->http([
            function (RequestInterface $r, array $o) use (&$pinned) {
                $pinned[] = $o['curl'][CURLOPT_RESOLVE];
                return new Response(301, ['Location' => 'https://cdn.example/logo.png']);
            },
            function (RequestInterface $r, array $o) use (&$pinned) {
                $pinned[] = $o['curl'][CURLOPT_RESOLVE];
                return new Response(200, [], 'PNG');
            },
        ]);

        self::assertSame('PNG', $http->get('https://bank.example/'));
        self::assertSame([['bank.example:443:203.0.113.10'], ['cdn.example:443:[2001:db8:85a3::8a2e:370:7334]']], $pinned);
    }

    public function testRedirectIntoInternalNetworkIsRefused(): void
    {
        $http = $this->http([new Response(302, ['Location' => 'https://intranet.example/admin'])], $mock);
        try {
            $http->get('https://bank.example/');
            self::fail('přesměrování do vnitřní sítě prošlo');
        } catch (MarketDataException) {
            self::assertSame(0, $mock->count(), 'na vnitřní adresu nešel žádný požadavek');
        }
    }

    public function testCgnatAddressIsNotPublic(): void
    {
        $this->expectException(DomainError::class);
        $this->guard()->resolve('https://tailnet.example/');
    }

    public function testOversizedBodyWithoutContentLengthIsRejected(): void
    {
        $this->expectException(MarketDataException::class);
        $this->http([new Response(200, [], str_repeat('x', 2_097_153))])->get('https://bank.example/');
    }

    public function testRedirectLoopStops(): void
    {
        $loop = fn () => new Response(302, ['Location' => '/again']);
        $this->expectException(MarketDataException::class);
        $this->http([$loop, $loop, $loop, $loop, $loop])->get('https://bank.example/');
    }
}
