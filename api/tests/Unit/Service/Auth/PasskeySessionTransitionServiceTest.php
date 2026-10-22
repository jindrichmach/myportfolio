<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\PasskeyCredentialRepository;
use MyPortfolio\Service\Auth\MfaStepUpProofStore;
use MyPortfolio\Service\Auth\MfaStepUpService;
use MyPortfolio\Service\Auth\PasskeyService;
use MyPortfolio\Service\Auth\PasskeySessionTransitionService;
use MyPortfolio\Service\Auth\PasskeyVerificationException;
use MyPortfolio\Service\Auth\SecurityClock;
use MyPortfolio\Service\Auth\SecurityTime;
use MyPortfolio\Service\Auth\SessionManager;
use MyPortfolio\Service\Auth\WebAuthnCeremony;
use MyPortfolio\Service\Auth\WebAuthnCeremonyStore;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PasskeySessionTransitionServiceTest extends TestCase
{
    public function testFailureBeforeBoundCeremonyStillMarksAttemptUsed(): void
    {
        $flowToken = str_repeat('a', 43);
        $cutoff = SecurityTime::fromDateTime(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $userStatement = $this->createMock(PDOStatement::class);
        $userStatement->expects(self::once())->method('execute')->with([17])->willReturn(true);
        $userStatement->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);
        $pdo = $this->transactionPdo($userStatement);

        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::never())->method('consumeInTransaction');
        $ceremonies->expects(self::once())
            ->method('markAttemptUsedInTransaction')
            ->with($pdo, $cutoff, $flowToken)
            ->willReturn(true);

        try {
            $this->service($pdo, $cutoff, $ceremonies)->completeLogin(
                $flowToken,
                ['rawId' => 'synthetic'],
                '127.0.0.1',
                'PHPUnit',
                17,
            );
            self::fail('Neaktivní uživatel musí passkey login odmítnout.');
        } catch (PasskeyVerificationException) {
        }
    }

    public function testFailureAfterCeremonyRollsBackToSavepointAndKeepsAttemptUsed(): void
    {
        $flowToken = str_repeat('a', 43);
        $cutoff = SecurityTime::fromDateTime(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $userStatement = $this->createMock(PDOStatement::class);
        $userStatement->expects(self::once())->method('execute')->with([17])->willReturn(true);
        $userStatement->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([
                'id' => 17,
                'email' => 'synthetic@example.invalid',
                'name' => 'Synthetic User',
                'role' => 'admin',
                'locale' => 'cs',
                'password_hash' => 'synthetic',
                'totp_enabled' => 0,
            ]);
        $pdo = $this->transactionPdo($userStatement);
        $savepointCalls = 0;
        $pdo->expects(self::exactly(2))
            ->method('exec')
            ->willReturnCallback(static function (string $statement) use (&$savepointCalls): int {
                $expected = $savepointCalls++ === 0
                    ? 'SAVEPOINT webauthn_ceremony_consumed'
                    : 'ROLLBACK TO SAVEPOINT webauthn_ceremony_consumed';
                TestCase::assertSame($expected, $statement);
                return 0;
            });

        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::once())
            ->method('consumeInTransaction')
            ->willReturn(new WebAuthnCeremony(
                WebAuthnCeremonyStore::PURPOSE_LOGIN,
                17,
                null,
                random_bytes(32),
                ['challenge' => 'synthetic'],
            ));
        $ceremonies->expects(self::never())->method('markAttemptUsedInTransaction');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('credentialId')->willThrowException(
            new PasskeyVerificationException('Ověření passkey selhalo.'),
        );

        try {
            $this->service($pdo, $cutoff, $ceremonies, $passkeys)->completeLogin(
                $flowToken,
                ['rawId' => 'synthetic'],
                '127.0.0.1',
                'PHPUnit',
                17,
            );
            self::fail('Neplatná credential musí passkey login odmítnout.');
        } catch (PasskeyVerificationException) {
        }

        self::assertSame(2, $savepointCalls);
    }

    public function testUnknownDiscoverableCredentialConsumesAnonymousCeremony(): void
    {
        $flowToken = str_repeat('a', 43);
        $cutoff = SecurityTime::fromDateTime(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('prepare');
        $pdo->expects(self::never())->method('rollBack');
        $savepointCalls = 0;
        $pdo->expects(self::exactly(2))
            ->method('exec')
            ->willReturnCallback(static function (string $statement) use (&$savepointCalls): int {
                $expected = $savepointCalls++ === 0
                    ? 'SAVEPOINT webauthn_ceremony_consumed'
                    : 'ROLLBACK TO SAVEPOINT webauthn_ceremony_consumed';
                TestCase::assertSame($expected, $statement);
                return 0;
            });

        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::once())
            ->method('consumeInTransaction')
            ->with(
                $pdo,
                $cutoff,
                $flowToken,
                WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN,
                null,
                null,
                null,
            )
            ->willReturn(new WebAuthnCeremony(
                WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN,
                null,
                null,
                random_bytes(32),
                ['challenge' => 'synthetic'],
            ));
        $ceremonies->expects(self::never())->method('markAttemptUsedInTransaction');

        try {
            $this->service($pdo, $cutoff, $ceremonies)->completeDiscoverableLogin(
                $flowToken,
                ['rawId' => 'unknown'],
                '127.0.0.1',
                'PHPUnit',
                null,
            );
            self::fail('Neznámá discoverable credential musí být odmítnuta.');
        } catch (PasskeyVerificationException) {
        }

        self::assertSame(2, $savepointCalls);
    }

    private function transactionPdo(PDOStatement $userStatement): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($userStatement);
        $pdo->expects(self::once())->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        return $pdo;
    }

    private function service(
        PDO $pdo,
        SecurityTime $cutoff,
        WebAuthnCeremonyStore $ceremonies,
        ?PasskeyService $passkeys = null,
    ): PasskeySessionTransitionService {
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $clock = $this->createMock(SecurityClock::class);
        $clock->expects(self::once())->method('capture')->with($pdo)->willReturn($cutoff);

        return new PasskeySessionTransitionService(
            $db,
            $clock,
            $passkeys ?? $this->createMock(PasskeyService::class),
            $this->createMock(PasskeyCredentialRepository::class),
            $ceremonies,
            $this->createMock(SessionManager::class),
            $this->createMock(MfaStepUpService::class),
            $this->createMock(MfaStepUpProofStore::class),
        );
    }
}
