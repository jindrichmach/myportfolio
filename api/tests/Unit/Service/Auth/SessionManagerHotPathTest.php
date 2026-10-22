<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\SecurityClock;
use MyPortfolio\Service\Auth\SessionManager;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SessionManagerHotPathTest extends TestCase
{
    public function testAuthenticationContextLoadsSessionAndUserWithOneStatement(): void
    {
        $token = str_repeat('a', 64);
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([$token])->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'id' => $token,
            'user_id' => '17',
            'csrf_token' => str_repeat('b', 64),
            'ip' => inet_pton('127.0.0.1'),
            'user_agent' => 'PHPUnit',
            'created_at' => '1721820000',
            'last_seen' => '1721822100',
            'expires_at' => '1724412000',
            'auth_method' => 'password_passkey',
            'assurance_level' => 'strong',
            'mfa_verified_at' => '2026-07-24 12:00:00.000000',
            'auth_credential_id' => '42',
            'last_user_activity_at' => '2026-07-24 12:00:00.000000',
            'locked_at' => null,
            'lock_reason' => null,
            'last_unlock_at' => null,
            'last_unlock_method' => null,
            'session_family_id' => str_repeat('C', 64),
            'generation' => '1',
            'replaced_at' => null,
            'revoked_at' => null,
            'evaluated_at' => '2026-07-24 12:05:00.000000',
            'evaluated_at_epoch' => '1721822400',
            'account_id' => '17',
            'account_email' => 'synthetic@example.invalid',
            'account_name' => 'Synthetic User',
            'account_role' => 'admin',
            'account_locale' => 'cs',
            'account_is_active' => '1',
            'account_totp_enabled' => '0',
            'account_session_lock_after_minutes' => '15',
        ]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->with(self::callback(
                static fn (string $sql): bool => str_contains($sql, 'JOIN users u')
                    && str_contains($sql, 'UTC_TIMESTAMP(6) AS evaluated_at'),
            ))
            ->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $context = $this->manager($db)->loadAuthenticationContext($token);

        self::assertNotNull($context);
        self::assertSame(17, $context['session']['user_id']);
        self::assertSame('127.0.0.1', $context['session']['ip']);
        self::assertSame(42, $context['session']['auth_credential_id']);
        self::assertSame(1_721_822_400, $context['session']['evaluated_at_epoch']);
        self::assertSame(str_repeat('c', 64), $context['session']['session_family_id']);
        self::assertSame(17, $context['user']['id']);
        self::assertTrue($context['user']['is_active']);
        self::assertFalse($context['user']['totp_enabled']);
        self::assertSame(15, $context['user']['session_lock_after_minutes']);
    }

    public function testRecentLastSeenDoesNotOpenDatabaseConnection(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $this->manager($db)->touchIfStale(
            str_repeat('a', 64),
            1_721_822_101,
            1_721_822_400,
        );
    }

    public function testStaleLastSeenUsesTimestampCompatibleDatabaseClock(): void
    {
        $token = str_repeat('a', 64);
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([$token])->willReturn(true);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->with(self::callback(
                static fn (string $sql): bool => str_contains(
                    $sql,
                    'SET last_seen = CURRENT_TIMESTAMP(6)',
                )
                    && str_contains(
                        $sql,
                        'last_seen <= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 5 MINUTE)',
                    )
                    && !str_contains($sql, 'UTC_TIMESTAMP'),
            ))
            ->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $this->manager($db)->touchIfStale($token, 1_721_822_000, 1_721_822_400);
    }

    private function manager(Connection $db): SessionManager
    {
        return new SessionManager(
            $db,
            new Config(['session' => ['lifetime_days' => 30]]),
            $this->createMock(SecurityClock::class),
        );
    }
}
