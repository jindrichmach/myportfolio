<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Service\Auth\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    public function testGeneratedSecretIsBase32_32Chars(): void
    {
        $secret = TotpService::generateSecret();
        self::assertSame(32, strlen($secret));
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testGeneratedSecretsAreUnique(): void
    {
        self::assertNotSame(TotpService::generateSecret(), TotpService::generateSecret());
    }

    public function testCurrentCodeIs6Digits(): void
    {
        $svc = new TotpService();
        $code = $svc->currentCode(TotpService::generateSecret());
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testCurrentCodeVerifies(): void
    {
        $svc = new TotpService();
        $secret = TotpService::generateSecret();
        $code = $svc->currentCode($secret);
        self::assertTrue($svc->verify($secret, $code));
    }

    public function testWrongCodeRejected(): void
    {
        $svc = new TotpService();
        $secret = TotpService::generateSecret();
        self::assertFalse($svc->verify($secret, '000000'));
    }

    public function testNonNumericCodeRejected(): void
    {
        $svc = new TotpService();
        $secret = TotpService::generateSecret();
        self::assertFalse($svc->verify($secret, 'abcdef'));
        self::assertFalse($svc->verify($secret, '12345'));   // 5 digits
        self::assertFalse($svc->verify($secret, '1234567')); // 7 digits
    }

    /**
     * RFC 6238 test vector — Appendix B (TOTP test vectors).
     * Secret = ASCII "12345678901234567890" (base32 = GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ),
     * algorithm SHA1, time T=59 → counter=1 → code 287082.
     */
    public function testRfc6238TestVector(): void
    {
        $svc = new TotpService();
        // ASCII "12345678901234567890" = 20 bytes → base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        // RFC test counter 1 (T=59) — používáme reflexi pro testování i v jiném čase
        $reflection = new \ReflectionClass(TotpService::class);
        $method = $reflection->getMethod('generateAt');
        self::assertSame('287082', $method->invoke($svc, $secret, 1));
    }

    public function testProvisioningUriContainsExpectedFields(): void
    {
        $svc = new TotpService();
        $uri = $svc->provisioningUri('JBSWY3DPEHPK3PXP', 'me@example.com', 'MyPortfolio');
        self::assertStringStartsWith('otpauth://totp/MyPortfolio:me%40example.com?', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=MyPortfolio', $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
