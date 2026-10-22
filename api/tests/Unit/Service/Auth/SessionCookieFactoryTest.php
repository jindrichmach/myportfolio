<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Auth\SessionCookieFactory;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class SessionCookieFactoryTest extends TestCase
{
    public function testCookieUsesRemainingLifetimeAndAbsoluteExpiry(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable('@1800000000'));
        $factory = new SessionCookieFactory(
            new Config([
                'session' => [
                    'cookie_name' => '__Host-myportfolio_session',
                    'cookie_secure' => true,
                    'cookie_samesite' => 'Lax',
                ],
            ]),
            $clock,
        );

        $cookie = $factory->create(str_repeat('a', 64), 1_800_003_600);

        self::assertStringContainsString('Max-Age=3600', $cookie);
        self::assertStringContainsString('Expires=Fri, 15 Jan 2027 09:00:00 GMT', $cookie);
        self::assertStringEndsWith('SameSite=Lax; Secure', $cookie);
    }
}
