<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Auth\SecretEncryption;
use PHPUnit\Framework\TestCase;

final class SecretEncryptionTest extends TestCase
{
    private const CTX = 'users.totp_secret:7';

    /** @param list<string> $previous */
    private function make(string $base64Key, string $pepper = '', string $env = 'production', array $previous = []): SecretEncryption
    {
        return new SecretEncryption(new Config(['app' => ['secret_encryption_key' => $base64Key, 'pepper' => $pepper, 'env' => $env,
            'secret_encryption_previous_keys' => $previous]]));
    }

    private static function key(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** Hodnota ve formátu enc:v1 (bez kid a AAD), jak ji ukládaly starší verze. */
    private static function legacyV1(string $plain, string $rawKey): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $nonce, $tag);
        return 'enc:v1:' . base64_encode($nonce . $ct . $tag);
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $svc = $this->make(self::key());
        $plain = 'JBSWY3DPEHPK3PXP'; // typický base32 TOTP secret
        $cipher = $svc->encrypt($plain, self::CTX);
        self::assertMatchesRegularExpression('/^enc:v2:[0-9a-f]{8}:/', $cipher);
        self::assertSame($plain, $svc->decrypt($cipher, self::CTX));
    }

    public function testValueBoundToContext(): void
    {
        // Zašifrovaná hodnota přesunutá k jinému uživateli / do jiného sloupce se nedešifruje (AAD)
        $svc = $this->make(self::key());
        $cipher = $svc->encrypt('secret', SecretEncryption::context('users.totp_secret', 7));
        $this->expectException(\RuntimeException::class);
        $svc->decrypt($cipher, SecretEncryption::context('users.totp_secret', 8));
    }

    public function testPlaintextAndLegacyFormatAreRejected(): void
    {
        $key = self::key();
        $svc = $this->make($key);
        foreach (['legacy-plain', self::legacyV1('x', base64_decode($key))] as $stored) {
            try {
                $svc->decrypt($stored, self::CTX);
                self::fail('decrypt přijal ' . $stored);
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRotationReadsLegacyFormatsAndOldKeys(): void
    {
        $old = self::key();
        $new = self::key();
        $before = $this->make($old);
        $after = $this->make($new, previous: [$old]);

        self::assertSame(['plain', true], $after->decryptForRotation('plain', self::CTX));
        self::assertSame(['v1', true], $after->decryptForRotation(self::legacyV1('v1', base64_decode($old)), self::CTX));
        // Hodnota starým klíčem jde přečíst (kid) a je k přešifrování; aktuálním klíčem už ne
        $oldCipher = $before->encrypt('v2-old', self::CTX);
        self::assertSame('v2-old', $after->decrypt($oldCipher, self::CTX));
        self::assertSame(['v2-old', true], $after->decryptForRotation($oldCipher, self::CTX));
        self::assertSame(['v2-new', false], $after->decryptForRotation($after->encrypt('v2-new', self::CTX), self::CTX));

        $this->expectException(\RuntimeException::class);
        $this->make($new)->decrypt($oldCipher, self::CTX); // starý klíč z konfigurace zmizel
    }

    public function testPepperKeyEncryptsOnlyOutsideProduction(): void
    {
        $dev = $this->make('', 'pepper-for-hkdf-fallback', 'development');
        $cipher = $dev->encrypt('x', self::CTX);
        self::assertSame('x', $dev->decrypt($cipher, self::CTX));

        $prod = $this->make('', 'pepper-for-hkdf-fallback');
        self::assertSame('x', $prod->decrypt($cipher, self::CTX), 'stará data z pepperu jdou v produkci přečíst');
        $this->expectException(\RuntimeException::class);
        $prod->encrypt('x', self::CTX);
    }

    public function testTwoEncryptionsOfSameInputDiffer(): void
    {
        // Random nonce → každý encrypt je jiný blob (důležité pro chosen-plaintext security)
        $svc = $this->make(self::key());
        $a = $svc->encrypt('same-input', self::CTX);
        $b = $svc->encrypt('same-input', self::CTX);
        self::assertNotSame($a, $b);
        self::assertSame('same-input', $svc->decrypt($a, self::CTX));
    }

    public function testInvalidBase64KeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->make('not-valid-base64-32-bytes!!!')->encrypt('x', self::CTX);
    }

    public function testTamperingDetected(): void
    {
        $svc = $this->make(self::key());
        $cipher = $svc->encrypt('original', self::CTX);
        // Změním poslední znak ciphertextu → GCM tag se nesedí → exception
        $tampered = substr($cipher, 0, -1) . (substr($cipher, -1) === 'A' ? 'B' : 'A');
        $this->expectException(\RuntimeException::class);
        $svc->decrypt($tampered, self::CTX);
    }

    public function testValidateKey(): void
    {
        self::assertSame('secret_encryption_key chybí, používá se HKDF fallback z app.pepper (méně bezpečné).',
            $this->make('', 'pepper', 'development')->validateKey());
        self::assertStringStartsWith('secret_encryption_key chybí — v produkci', (string) $this->make('', 'pepper')->validateKey());
        self::assertSame('cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.',
            $this->make('not-valid-base64-32-bytes!!!')->validateKey());
        // 24B je častý omyl (AES-192), ale naše AES-256-GCM implementace vyžaduje 32B key.
        self::assertSame('cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.',
            $this->make(base64_encode(random_bytes(24)))->validateKey());
        self::assertStringContainsString('previous_keys', (string) $this->make(self::key(), previous: ['short'])->validateKey());
        self::assertNull($this->make(self::key())->validateKey());
    }
}
