<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Captcha;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Captcha\TurnstileVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;

/**
 * Pokrývá no-op cesty (provider != turnstile, missing secret) — síťová cesta
 * (HTTP call na Cloudflare API) je out of scope unit testu.
 */
final class TurnstileVerifierTest extends TestCase
{
    /** @param array<string,mixed> $captchaCfg */
    private function makeWithCfg(array $captchaCfg): TurnstileVerifier
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($config, 'data'))
            ->setValue($config, ['captcha' => $captchaCfg]);
        return new TurnstileVerifier($config, new NullLogger());
    }

    public function testNoOpWhenProviderIsNone(): void
    {
        $svc = $this->makeWithCfg(['provider' => 'none']);
        // Bez konfigurace captcha → vždy true (žádný verify call)
        self::assertTrue($svc->verify('any-token-or-empty', '127.0.0.1', 'login'));
        self::assertTrue($svc->verify(null, '127.0.0.1', 'login'));
        self::assertTrue($svc->verify('', '127.0.0.1', 'login'));
    }

    public function testNoOpWhenProviderMissing(): void
    {
        $svc = $this->makeWithCfg([]);  // captcha config úplně chybí
        self::assertTrue($svc->verify('token', '127.0.0.1', 'login'));
    }

    public function testReturnsTrueWhenTurnstileEnabledButSecretEmpty(): void
    {
        // Pokud admin omylem nakonfiguroval `provider=turnstile` ale nezadal `secret_key`,
        // verifier loguje warning a vrací true (fail-open) — jinak by aplikace nešla.
        $svc = $this->makeWithCfg(['provider' => 'turnstile', 'secret_key' => '']);
        self::assertTrue($svc->verify('any-token', '127.0.0.1', 'login'));
    }

    public function testReturnsFalseWhenSecretSetButTokenMissing(): void
    {
        // Standard fail-closed: Turnstile aktivní + token chybí → reject (400).
        $svc = $this->makeWithCfg(['provider' => 'turnstile', 'secret_key' => 'secret']);
        self::assertFalse($svc->verify(null, '127.0.0.1', 'login'));
        self::assertFalse($svc->verify('',   '127.0.0.1', 'login'));
    }
}
