<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Auth\PasskeyService;
use MyPortfolio\Service\Auth\PasskeyVerificationException;
use MyPortfolio\Service\Auth\WebAuthnConfig;
use MyPortfolio\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyServiceTest extends TestCase
{
    use OpensslConfigTrait;

    private PasskeyService $service;

    protected function setUp(): void
    {
        $config = new WebAuthnConfig(new Config(['app' => ['url' => 'https://invoice.example.cz']]));
        $this->service = new PasskeyService($config);
    }

    public function testRegistrationOptionsEnforceDiscoverableCredentialAndUserVerification(): void
    {
        $handle = random_bytes(32);
        $existing = $this->credential($handle);
        $challenge = random_bytes(32);

        $options = $this->service->registrationOptions(
            'user@example.invalid',
            'Synthetic User',
            $handle,
            [$existing],
            $challenge,
        );

        self::assertSame('MyPortfolio', $options['rp']['name']);
        self::assertSame('invoice.example.cz', $options['rp']['id']);
        self::assertSame('user@example.invalid', $options['user']['name']);
        self::assertSame('Synthetic User', $options['user']['displayName']);
        self::assertSame(self::base64url($handle), $options['user']['id']);
        self::assertSame(self::base64url($challenge), $options['challenge']);
        self::assertSame(
            [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            $options['pubKeyCredParams'],
        );
        self::assertSame('required', $options['authenticatorSelection']['residentKey']);
        self::assertTrue($options['authenticatorSelection']['requireResidentKey']);
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        self::assertSame('none', $options['attestation']);
        self::assertSame(self::base64url($existing->publicKeyCredentialId), $options['excludeCredentials'][0]['id']);
    }

    public function testAssertionOptionsAreRestrictedToKnownCredentialsAndRequireVerification(): void
    {
        $handle = random_bytes(32);
        $credential = $this->credential($handle);
        $challenge = random_bytes(32);

        $options = $this->service->assertionOptions([$credential], $challenge);

        self::assertSame('invoice.example.cz', $options['rpId']);
        self::assertSame('required', $options['userVerification']);
        self::assertSame(self::base64url($challenge), $options['challenge']);
        self::assertSame(self::base64url($credential->publicKeyCredentialId), $options['allowCredentials'][0]['id']);
        self::assertSame(['internal', 'hybrid'], $options['allowCredentials'][0]['transports']);
    }

    public function testAssertionOptionsRejectEmptyCredentialList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertionOptions([], random_bytes(32));
    }

    public function testDiscoverableAssertionOptionsOmitCredentialListAndRequireVerification(): void
    {
        $challenge = random_bytes(32);

        $options = $this->service->discoverableAssertionOptions($challenge);

        self::assertSame('invoice.example.cz', $options['rpId']);
        self::assertSame('required', $options['userVerification']);
        self::assertSame(self::base64url($challenge), $options['challenge']);
        self::assertSame([], $options['allowCredentials']);
    }

    public function testMalformedRegistrationPayloadIsMappedToSanitizedError(): void
    {
        $options = $this->service->registrationOptions(
            'user@example.invalid',
            'Synthetic User',
            random_bytes(32),
            [],
            random_bytes(32),
        );

        try {
            $this->service->verifyRegistration(
                ['rawId' => 'secret-credential-material', 'response' => ['attestationObject' => 'secret']],
                $options,
            );
            self::fail('Malformed payload must fail.');
        } catch (PasskeyVerificationException $e) {
            self::assertSame('Registraci passkey se nepodařilo ověřit.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testMalformedAssertionPayloadIsMappedToSanitizedError(): void
    {
        $credential = $this->credential(random_bytes(32));
        $options = $this->service->assertionOptions([$credential], random_bytes(32));

        try {
            $this->service->verifyAssertion(
                ['rawId' => 'secret-credential-material', 'response' => ['signature' => 'secret']],
                $options,
                $credential,
            );
            self::fail('Malformed payload must fail.');
        } catch (PasskeyVerificationException $e) {
            self::assertSame('Ověření passkey selhalo.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testDiscoverableAssertionRequiresMatchingReturnedUserHandle(): void
    {
        [$credential, $options, $payload] = $this->discoverableAssertion();

        $verified = $this->service->verifyDiscoverableAssertion(
            $payload,
            $options,
            $credential,
        );
        self::assertSame(1, $verified->counter);

        $payload['response']['userHandle'] = null;
        $this->expectException(PasskeyVerificationException::class);
        $this->service->verifyDiscoverableAssertion(
            $payload,
            $options,
            $credential,
        );
    }

    public function testCredentialIdDecodesUnpaddedBase64Url(): void
    {
        $rawId = random_bytes(32);
        self::assertSame($rawId, $this->service->credentialId([
            'rawId' => self::base64url($rawId),
        ]));
    }

    public function testCredentialIdRejectsMalformedOrOversizedValues(): void
    {
        foreach ([
            [],
            ['rawId' => '*not-base64url*'],
            ['rawId' => self::base64url(str_repeat('x', 1025))],
        ] as $payload) {
            try {
                $this->service->credentialId($payload);
                self::fail('Neplatné rawId musí být odmítnuto.');
            } catch (PasskeyVerificationException $e) {
                self::assertSame('Ověření passkey selhalo.', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
    }

    private function credential(string $handle): CredentialRecord
    {
        return CredentialRecord::create(
            random_bytes(32),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal', 'hybrid'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            random_bytes(77),
            $handle,
            0,
            null,
            true,
            false,
            true,
        );
    }

    /**
     * @return array{CredentialRecord,array<string,mixed>,array<string,mixed>}
     */
    private function discoverableAssertion(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ] + self::opensslConfigArgs());
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $privateKey);
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);
        // openssl_pkey_get_details() vrací souřadnice jako velké celé číslo, tedy
        // bez vedoucích nul. U P-256 proto zhruba každý 128. klíč vyjde kratší než
        // 32 bajtů. COSE souřadnice ale musí být přesně 32 bajtů, takže se doplní
        // zleva — bez toho by test náhodně padal (a padal, hned na prvním CI běhu).
        $x = str_pad((string) ($details['ec']['x'] ?? ''), 32, "\x00", STR_PAD_LEFT);
        $y = str_pad((string) ($details['ec']['y'] ?? ''), 32, "\x00", STR_PAD_LEFT);
        self::assertSame(32, strlen($x));
        self::assertSame(32, strlen($y));

        $credentialId = random_bytes(32);
        $userHandle = random_bytes(32);
        $credentialPublicKey = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20"
            . $x
            . "\x22\x58\x20"
            . $y;
        $credential = CredentialRecord::create(
            $credentialId,
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            $credentialPublicKey,
            $userHandle,
            0,
            null,
            false,
            false,
            true,
        );

        $challenge = random_bytes(32);
        $options = $this->service->discoverableAssertionOptions($challenge);
        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => self::base64url($challenge),
            'origin' => 'https://invoice.example.cz',
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $authenticatorData = hash('sha256', 'invoice.example.cz', true)
            . "\x05"
            . pack('N', 1);
        self::assertTrue(openssl_sign(
            $authenticatorData . hash('sha256', $clientDataJson, true),
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256,
        ));

        return [
            $credential,
            $options,
            [
                'id' => self::base64url($credentialId),
                'rawId' => self::base64url($credentialId),
                'type' => 'public-key',
                'authenticatorAttachment' => 'platform',
                'clientExtensionResults' => [],
                'response' => [
                    'clientDataJSON' => self::base64url($clientDataJson),
                    'authenticatorData' => self::base64url($authenticatorData),
                    'signature' => self::base64url($signature),
                    'userHandle' => self::base64url($userHandle),
                ],
            ],
        ];
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
