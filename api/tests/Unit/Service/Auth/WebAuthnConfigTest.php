<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Auth\PasskeyService;
use MyPortfolio\Service\Auth\WebAuthnConfig;
use MyPortfolio\Service\Auth\WebAuthnConfigProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebAuthnConfigTest extends TestCase
{
    #[DataProvider('validUrlProvider')]
    public function testCanonicalRpIdAndOrigin(string $url, string $rpId, string $origin): void
    {
        $config = new WebAuthnConfig(new Config(['app' => ['url' => $url]]));

        self::assertSame($rpId, $config->rpId());
        self::assertSame($origin, $config->origin());
    }

    /**
     * @return iterable<string,array{string,string,string}>
     */
    public static function validUrlProvider(): iterable
    {
        yield 'https' => ['https://invoice.example.cz', 'invoice.example.cz', 'https://invoice.example.cz'];
        yield 'uppercase and default port' => [
            'HTTPS://INVOICE.EXAMPLE.CZ:443/',
            'invoice.example.cz',
            'https://invoice.example.cz',
        ];
        yield 'non-default port' => [
            'https://invoice.example.cz:8443/',
            'invoice.example.cz',
            'https://invoice.example.cz:8443',
        ];
        yield 'localhost development' => ['http://localhost:8080/', 'localhost', 'http://localhost:8080'];
        yield 'punycode' => ['https://xn--faktura-2za.example', 'xn--faktura-2za.example', 'https://xn--faktura-2za.example'];
    }

    #[DataProvider('invalidUrlProvider')]
    public function testInvalidCanonicalUrlIsRejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WebAuthnConfig(new Config(['app' => ['url' => $url]]));
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidUrlProvider(): iterable
    {
        yield 'missing' => [''];
        yield 'plain http' => ['http://invoice.example.cz'];
        yield 'IPv4' => ['https://127.0.0.1'];
        yield 'IPv6' => ['https://[::1]'];
        yield 'path' => ['https://invoice.example.cz/app'];
        yield 'query' => ['https://invoice.example.cz/?tenant=1'];
        yield 'fragment' => ['https://invoice.example.cz/#login'];
        yield 'userinfo' => ['https://user:pass@invoice.example.cz'];
        yield 'unicode hostname' => ['https://faktura.česko'];
        yield 'invalid label' => ['https://bad_host.example'];
        yield 'trailing dot' => ['https://invoice.example.cz.'];
    }

    public function testLazyProviderKeepsApplicationAvailableWithInvalidCanonicalUrl(): void
    {
        $provider = new WebAuthnConfigProvider(new Config([
            'app' => ['url' => 'http://invoice.example.cz'],
        ]));
        $passkeys = new PasskeyService($provider);

        self::assertFalse($passkeys->isAvailable());
        self::assertSame(
            'WebAuthn vyžaduje HTTPS; HTTP je povolené pouze pro localhost.',
            $passkeys->configurationError(),
        );

        $this->expectException(\DomainException::class);
        $passkeys->assertionOptions([], random_bytes(32));
    }
}
