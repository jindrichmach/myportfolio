<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Auth\SessionLockPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionLockPolicyTest extends TestCase
{
    public function testDefaultTimeout(): void
    {
        $policy = new SessionLockPolicy(new Config([]));

        self::assertFalse($policy->isEnabled());
        self::assertSame(0, $policy->timeoutMinutes());
        self::assertSame(0, $policy->timeoutSeconds());
        self::assertSame(1440, $policy->maximumUserTimeoutMinutes());
        self::assertSame(0, $policy->effectiveTimeoutMinutes(null));
        self::assertSame(15, $policy->effectiveTimeoutMinutes(15));
    }

    public function testZeroDisablesAutomaticLock(): void
    {
        $policy = new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 0]]));

        self::assertFalse($policy->isEnabled());
        self::assertSame(0, $policy->timeoutSeconds());
    }

    public function testPositiveAdminTimeoutIsDefaultAndMaximumForUserPreference(): void
    {
        $policy = new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 30]]));

        self::assertSame(30, $policy->maximumUserTimeoutMinutes());
        self::assertSame(30, $policy->effectiveTimeoutMinutes(null));
        self::assertSame(5, $policy->effectiveTimeoutMinutes(5));
        self::assertSame(30, $policy->effectiveTimeoutMinutes(60));
        $policy->assertUserTimeoutAllowed(30);

        $this->expectException(\InvalidArgumentException::class);
        $policy->assertUserTimeoutAllowed(31);
    }

    public function testCanonicalNumericStringIsAccepted(): void
    {
        $policy = new SessionLockPolicy(new Config([
            'session' => ['lock_after_minutes' => '15'],
        ]));

        self::assertSame(15, $policy->timeoutMinutes());
        self::assertNull($policy->configurationWarning());
    }

    #[DataProvider('invalidUserTimeoutProvider')]
    public function testInvalidUserTimeoutIsRejected(int $timeout): void
    {
        $policy = new SessionLockPolicy(new Config([]));

        $this->expectException(\InvalidArgumentException::class);
        $policy->assertUserTimeoutAllowed($timeout);
    }

    #[DataProvider('invalidTimeoutProvider')]
    public function testInvalidTimeoutFailsSoftWithWarning(mixed $timeout): void
    {
        $policy = new SessionLockPolicy(new Config([
            'session' => ['lock_after_minutes' => $timeout],
        ]));

        self::assertFalse($policy->isEnabled());
        self::assertSame(0, $policy->timeoutMinutes());
        self::assertNotNull($policy->configurationWarning());
    }

    /**
     * @return iterable<string,array{mixed}>
     */
    public static function invalidTimeoutProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'too high' => [SessionLockPolicy::MAX_TIMEOUT_MINUTES + 1];
        yield 'non-canonical string' => ['015'];
        yield 'string with unit' => ['15 minutes'];
        yield 'float' => [15.5];
        yield 'null' => [null];
    }

    /**
     * @return iterable<string,array{int}>
     */
    public static function invalidUserTimeoutProvider(): iterable
    {
        yield 'zero is represented by inherited disabled policy' => [0];
        yield 'negative' => [-1];
        yield 'too high' => [SessionLockPolicy::MAX_TIMEOUT_MINUTES + 1];
    }
}
