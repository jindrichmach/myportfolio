<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\SecurityClock;
use MyPortfolio\Service\Auth\SecurityTime;
use MyPortfolio\Service\Auth\SessionLockPolicy;
use MyPortfolio\Service\Auth\SessionLockService;
use MyPortfolio\Service\Auth\WebAuthnCeremonyStore;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SessionLockRequestEvaluationTest extends TestCase
{
    public function testActiveSnapshotDoesNotOpenDatabaseConnectionOrTransaction(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $clock = $this->createMock(SecurityClock::class);
        $clock->expects(self::never())->method('capture');
        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::never())->method('cancelForSessionAt');

        $result = (new SessionLockService(
            $db,
            new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 15]])),
            $clock,
            $ceremonies,
        ))->evaluateForRequest(
            str_repeat('a', 64),
            [
                'last_user_activity_at' => '2026-07-24 12:00:01.000000',
                'locked_at' => null,
                'lock_reason' => null,
                'evaluated_at' => '2026-07-24 12:15:00.000000',
            ],
            null,
        );

        self::assertTrue($result->sessionExists);
        self::assertFalse($result->locked);
        self::assertFalse($result->transitioned);
        self::assertSame(
            '2026-07-24 12:15:00.000000',
            $result->evaluatedAt?->format('Y-m-d H:i:s.u'),
        );
    }

    public function testExpiredSnapshotFallsBackToLockedTransition(): void
    {
        $token = str_repeat('a', 64);
        $cutoff = SecurityTime::fromDateTime(
            new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
        );
        $select = $this->createMock(PDOStatement::class);
        $select->expects(self::once())
            ->method('execute')
            ->with([$token, $cutoff->epochSeconds])
            ->willReturn(true);
        $select->expects(self::once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([
                'last_user_activity_at' => '2026-07-24 12:00:00.000000',
                'locked_at' => null,
                'lock_reason' => null,
                'session_lock_after_minutes' => null,
                'has_active_passkey' => 0,
            ]);
        $update = $this->createMock(PDOStatement::class);
        $update->expects(self::once())
            ->method('execute')
            ->with([$cutoff->utcSql, 'idle', $token])
            ->willReturn(true);
        $update->expects(self::once())->method('rowCount')->willReturn(1);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnCallback(static function (string $sql) use ($select, $update): PDOStatement {
                if (str_contains($sql, 'FOR UPDATE')) {
                    return $select;
                }
                if (str_contains($sql, 'SET locked_at = ?')) {
                    return $update;
                }
                throw new \LogicException('Neočekávaný SQL dotaz v testu session locku.');
            });
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);
        $clock = $this->createMock(SecurityClock::class);
        $clock->expects(self::once())->method('capture')->with($pdo)->willReturn($cutoff);
        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::once())
            ->method('cancelForSessionAt')
            ->with($token, $cutoff->utcSql);

        $result = (new SessionLockService(
            $db,
            new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 15]])),
            $clock,
            $ceremonies,
        ))->evaluateForRequest(
            $token,
            [
                'last_user_activity_at' => '2026-07-24 12:00:00.000000',
                'locked_at' => null,
                'lock_reason' => null,
                'evaluated_at' => '2026-07-24 12:15:00.000000',
            ],
            null,
        );

        self::assertTrue($result->sessionExists);
        self::assertTrue($result->locked);
        self::assertTrue($result->transitioned);
        self::assertSame('idle', $result->reason);
        self::assertSame($cutoff->utcSql, $result->lockedAt?->format('Y-m-d H:i:s.u'));
    }
}
