<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\PasskeyCredentialRepository;
use MyPortfolio\Service\Auth\MfaStepUpProofStore;
use MyPortfolio\Service\Auth\OneTimeTokenException;
use MyPortfolio\Service\Auth\PsrSecurityClock;
use MyPortfolio\Service\Auth\WebAuthnCeremonyStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

#[Group('integration')]
final class PasskeyPersistenceTest extends TestCase
{
    private Connection $db;
    private PasskeyCredentialRepository $credentials;
    private MutableClock $clock;
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing — skip live-DB test.');
        }

        try {
            $this->db = new Connection(Config::load($rootDir));
            if (!$this->db->hasTable('webauthn_credentials')
                || !$this->db->hasTable('webauthn_ceremonies')
                || !$this->db->hasTable('mfa_step_up_proofs')
            ) {
                $this->markTestSkipped('WebAuthn migration 0145 is not applied.');
            }

            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO users (email, password_hash, name, role, locale)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                'passkey-test-' . bin2hex(random_bytes(8)) . '@example.invalid',
                password_hash('Synthetic-test-password-42', PASSWORD_BCRYPT),
                'Synthetic Passkey Test',
                'admin',
                'cs',
            ]);
            $this->userId = (int) $this->db->pdo()->lastInsertId();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        $this->credentials = new PasskeyCredentialRepository($this->db);
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-07-24 12:00:00.000000 UTC'));
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->userId > 0) {
            $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testCredentialRoundTripRenameCounterUpdateAndRevoke(): void
    {
        $handle = $this->credentials->userHandle($this->userId);
        self::assertSame(32, strlen($handle));
        self::assertSame($handle, $this->credentials->userHandle($this->userId));

        $record = $this->credentialRecord($handle, 4);
        $id = $this->credentials->save($this->userId, $record, 'Telefon');

        $stored = $this->credentials->findActiveByCredentialId($record->publicKeyCredentialId);
        self::assertNotNull($stored);
        self::assertSame($id, $stored->id);
        self::assertSame($this->userId, $stored->userId);
        self::assertSame('Telefon', $stored->label);
        self::assertSame($record->credentialPublicKey, $stored->record->credentialPublicKey);
        self::assertSame(['internal', 'hybrid'], $stored->record->transports);
        self::assertTrue($stored->record->backupEligible);
        self::assertFalse($stored->record->backupStatus);
        self::assertSame($id, $this->credentials->findActiveForUserById($this->userId, $id)?->id);
        self::assertNull($this->credentials->findActiveForUserById($this->userId + 1, $id));
        self::assertSame(1, $this->credentials->countActiveForUser($this->userId));

        self::assertTrue($this->credentials->rename($this->userId, $id, 'Pixel'));
        self::assertSame('Pixel', $this->credentials->findAllForUser($this->userId)[0]->label);

        $stored = $this->credentials->findActiveByCredentialId($record->publicKeyCredentialId);
        self::assertNotNull($stored);
        $record->counter = 5;
        $record->backupStatus = true;
        self::assertTrue($this->credentials->updateAfterAssertion($stored, $record));
        self::assertFalse($this->credentials->updateAfterAssertion($stored, $record));

        $updated = $this->credentials->findActiveByCredentialId($record->publicKeyCredentialId);
        self::assertNotNull($updated);
        self::assertSame(5, $updated->record->counter);
        self::assertTrue($updated->record->backupStatus);
        self::assertNotNull($updated->lastUsedAt);

        self::assertTrue($this->credentials->revoke($this->userId, $id));
        self::assertFalse($this->credentials->revoke($this->userId, $id));
        self::assertNull($this->credentials->findActiveByCredentialId($record->publicKeyCredentialId));
        self::assertSame(0, $this->credentials->countActiveForUser($this->userId));
        self::assertNotNull($this->credentials->findAllForUser($this->userId, true)[0]->revokedAt);
    }

    public function testCredentialLimitIsEnforcedTransactionally(): void
    {
        $handle = $this->credentials->userHandle($this->userId);
        for ($i = 1; $i <= 10; $i++) {
            $this->credentials->save($this->userId, $this->credentialRecord($handle), "Key {$i}");
        }
        self::assertSame(10, $this->credentials->countActiveForUser($this->userId));

        $this->expectException(\DomainException::class);
        $this->credentials->save($this->userId, $this->credentialRecord($handle), 'Key 11');
    }

    public function testCeremonyCanBeConsumedOnlyOnce(): void
    {
        $store = new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock));
        $challenge = random_bytes(32);
        $token = $store->create(
            WebAuthnCeremonyStore::PURPOSE_REGISTER,
            $this->userId,
            'session-token',
            null,
            $challenge,
            ['challenge' => 'encoded-challenge'],
            '127.0.0.1',
            'PHPUnit',
        );

        $flow = $store->consumeRegistration(
            $token,
            $this->userId,
            'session-token',
        );
        self::assertSame($challenge, $flow->challenge);
        self::assertSame(['challenge' => 'encoded-challenge'], $flow->options);

        $this->expectException(OneTimeTokenException::class);
        $store->consumeRegistration(
            $token,
            $this->userId,
            'session-token',
        );
    }

    public function testTwoPreissuedFirstPasskeyFlowsCanStoreOnlyOneCredential(): void
    {
        $store = new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock));
        $tokens = [];
        for ($i = 0; $i < 2; $i++) {
            $tokens[] = $store->create(
                WebAuthnCeremonyStore::PURPOSE_REGISTER,
                $this->userId,
                'session-token',
                WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
                random_bytes(32),
                ['challenge' => "first-passkey-{$i}"],
                '127.0.0.1',
                'PHPUnit',
            );
        }

        $handle = $this->credentials->userHandle($this->userId);
        $first = $store->consumeRegistration($tokens[0], $this->userId, 'session-token');
        self::assertSame(
            WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
            $first->operation,
        );
        $this->credentials->save(
            $this->userId,
            $this->credentialRecord($handle),
            'First key',
            true,
        );

        $second = $store->consumeRegistration($tokens[1], $this->userId, 'session-token');
        self::assertSame(
            WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
            $second->operation,
        );
        try {
            $this->credentials->save(
                $this->userId,
                $this->credentialRecord($handle),
                'Second key',
                true,
            );
            self::fail('Druhé předem vydané first-passkey flow nesmí uložit credential.');
        } catch (\DomainException) {
        }

        self::assertSame(1, $this->credentials->countActiveForUser($this->userId));
    }

    public function testRegistrationRejectsUnknownInternalConstraint(): void
    {
        $store = new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock));

        $this->expectException(\InvalidArgumentException::class);
        $store->create(
            WebAuthnCeremonyStore::PURPOSE_REGISTER,
            $this->userId,
            'session-token',
            'unknown_registration_constraint',
            random_bytes(32),
            ['challenge' => 'invalid-constraint'],
            '127.0.0.1',
            'PHPUnit',
        );
    }

    public function testRegistrationConstraintRequiresSpecializedConsumer(): void
    {
        $store = new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock));
        $token = $store->create(
            WebAuthnCeremonyStore::PURPOSE_REGISTER,
            $this->userId,
            'session-token',
            WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
            random_bytes(32),
            ['challenge' => 'first-passkey-only'],
            '127.0.0.1',
            'PHPUnit',
        );

        try {
            $store->consume(
                $token,
                WebAuthnCeremonyStore::PURPOSE_REGISTER,
                $this->userId,
                'session-token',
                WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
            );
            self::fail('Interní registrační constraint nesmí přijmout obecný consumer.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(
            WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
            $store->consumeRegistration($token, $this->userId, 'session-token')->operation,
        );
    }

    public function testCeremonyContextMismatchConsumesToken(): void
    {
        $store = new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock));
        $token = $store->create(
            WebAuthnCeremonyStore::PURPOSE_STEP_UP,
            $this->userId,
            'session-token',
            'api-token.create',
            random_bytes(32),
            ['challenge' => 'encoded-challenge'],
            '::1',
            'PHPUnit',
        );

        try {
            $store->consume(
                $token,
                WebAuthnCeremonyStore::PURPOSE_STEP_UP,
                $this->userId,
                'session-token',
                'passkey.delete:10',
            );
            self::fail('Mismatched operation must fail.');
        } catch (OneTimeTokenException) {
        }

        $this->expectException(OneTimeTokenException::class);
        $store->consume(
            $token,
            WebAuthnCeremonyStore::PURPOSE_STEP_UP,
            $this->userId,
            'session-token',
            'api-token.create',
        );
    }

    public function testSessionLockCancellationConsumesRegisterAndStepUpFlowsOnly(): void
    {
        $store = new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock));
        $register = $store->create(
            WebAuthnCeremonyStore::PURPOSE_REGISTER,
            $this->userId,
            'session-token',
            null,
            random_bytes(32),
            ['challenge' => 'register'],
            '127.0.0.1',
            'PHPUnit',
        );
        $stepUp = $store->create(
            WebAuthnCeremonyStore::PURPOSE_STEP_UP,
            $this->userId,
            'session-token',
            'api_token.create',
            random_bytes(32),
            ['challenge' => 'step-up'],
            '127.0.0.1',
            'PHPUnit',
        );
        $login = $store->create(
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            $this->userId,
            null,
            null,
            random_bytes(32),
            ['challenge' => 'login'],
            '127.0.0.1',
            'PHPUnit',
        );

        self::assertSame(2, $store->cancelForSession('session-token'));
        foreach ([
            [$register, WebAuthnCeremonyStore::PURPOSE_REGISTER, null],
            [$stepUp, WebAuthnCeremonyStore::PURPOSE_STEP_UP, 'api_token.create'],
        ] as [$token, $purpose, $operation]) {
            try {
                $store->consume($token, $purpose, $this->userId, 'session-token', $operation);
                self::fail('Cancelled flow must not be consumable.');
            } catch (OneTimeTokenException) {
            }
        }
        self::assertSame($this->userId, $store->consumeLogin($login)->userId);
    }

    public function testExpiredCeremonyIsRejected(): void
    {
        $store = new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock));
        $token = $store->create(
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            $this->userId,
            null,
            null,
            random_bytes(32),
            ['challenge' => 'encoded-challenge'],
            '127.0.0.1',
            'PHPUnit',
            1,
        );
        $this->clock->advance('+2 seconds');

        $this->expectException(OneTimeTokenException::class);
        $store->consume(
            $token,
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            $this->userId,
            null,
            null,
        );
    }

    public function testAnonymousLoginCeremonyResolvesBoundUserAndIsOneTime(): void
    {
        $store = new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock));
        $token = $store->create(
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            $this->userId,
            null,
            null,
            random_bytes(32),
            ['challenge' => 'encoded-challenge'],
            '127.0.0.1',
            'PHPUnit',
        );

        $ceremony = $store->consumeLogin($token);
        self::assertSame($this->userId, $ceremony->userId);
        self::assertSame(WebAuthnCeremonyStore::PURPOSE_LOGIN, $ceremony->purpose);

        $this->expectException(OneTimeTokenException::class);
        $store->consumeLogin($token);
    }

    public function testDiscoverableLoginCeremonyHasNoBoundUserAndIsOneTime(): void
    {
        $store = new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock));
        $token = $store->create(
            WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN,
            null,
            null,
            null,
            random_bytes(32),
            ['challenge' => 'encoded-challenge', 'allowCredentials' => []],
            '127.0.0.1',
            'PHPUnit',
        );

        self::assertSame([
            'purpose' => WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN,
            'user_id' => null,
        ], $store->peekLoginContext($token));
        $ceremony = $store->consumeDiscoverableLogin($token);
        self::assertNull($ceremony->userId);
        self::assertSame(WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN, $ceremony->purpose);

        $this->expectException(OneTimeTokenException::class);
        $store->consumeDiscoverableLogin($token);
    }

    public function testStepUpProofIsBoundAndOneTime(): void
    {
        $record = $this->credentialRecord($this->credentials->userHandle($this->userId));
        $credentialId = $this->credentials->save($this->userId, $record, 'Test key');
        $store = new MfaStepUpProofStore($this->db, new PsrSecurityClock($this->clock));
        $token = $store->issue(
            $this->userId,
            'session-token',
            'api-token.create',
            'passkey',
            $credentialId,
        );

        $proof = $store->consume($token, $this->userId, 'session-token', 'api-token.create');
        self::assertSame($this->userId, $proof->userId);
        self::assertSame('api-token.create', $proof->operation);
        self::assertSame('passkey', $proof->authMethod);
        self::assertSame($credentialId, $proof->authCredentialId);

        $this->expectException(OneTimeTokenException::class);
        $store->consume($token, $this->userId, 'session-token', 'api-token.create');
    }

    public function testStepUpSessionMismatchConsumesProof(): void
    {
        $store = new MfaStepUpProofStore($this->db, new PsrSecurityClock($this->clock));
        $token = $store->issue($this->userId, 'session-a', 'api-token.create', 'totp');

        try {
            $store->consume($token, $this->userId, 'session-b', 'api-token.create');
            self::fail('Mismatched session must fail.');
        } catch (OneTimeTokenException) {
        }

        $this->expectException(OneTimeTokenException::class);
        $store->consume($token, $this->userId, 'session-a', 'api-token.create');
    }

    private function credentialRecord(string $userHandle, int $counter = 0): CredentialRecord
    {
        return CredentialRecord::create(
            random_bytes(32),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal', 'hybrid', 'internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            random_bytes(77),
            $userHandle,
            $counter,
            null,
            true,
            false,
            true,
        );
    }
}

final class MutableClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $current) {}

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(string $modify): void
    {
        $this->current = $this->current->modify($modify);
    }
}
