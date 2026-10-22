<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\SecurityClock;
use MyPortfolio\Service\Auth\SessionLockPolicy;
use MyPortfolio\Service\Auth\SessionLockService;
use MyPortfolio\Service\Auth\WebAuthnCeremonyStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SessionLockDisabledPolicyTest extends TestCase
{
    public function testEvaluateUsesReadOnlyPathWhenAutomaticLockIsDisabled(): void
    {
        $token = str_repeat('a', 64);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([$token])->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn([
            'last_user_activity_at' => '2026-07-24 12:00:00.000000',
            'locked_at' => null,
            'lock_reason' => null,
            'session_lock_after_minutes' => null,
            'evaluated_at' => '2026-07-24 12:05:00.000000',
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::once())
            ->method('prepare')
            ->with(self::callback(
                static fn (string $sql): bool => !str_contains($sql, 'FOR UPDATE'),
            ))
            ->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);
        $clock = $this->createMock(SecurityClock::class);
        $clock->expects(self::never())->method('capture');
        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::never())->method('cancelForSessionAt');

        $result = (new SessionLockService(
            $db,
            new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 0]])),
            $clock,
            $ceremonies,
        ))->evaluate($token);

        self::assertTrue($result->sessionExists);
        self::assertFalse($result->locked);
        self::assertFalse($result->transitioned);
        self::assertSame(
            '2026-07-24 12:05:00.000000',
            $result->evaluatedAt?->format('Y-m-d H:i:s.u'),
        );
    }
}
