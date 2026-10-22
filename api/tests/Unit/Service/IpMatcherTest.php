<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service;

use MyPortfolio\Service\IpMatcher;
use PHPUnit\Framework\TestCase;

final class IpMatcherTest extends TestCase
{
    private IpMatcher $m;

    protected function setUp(): void
    {
        $this->m = (new \ReflectionClass(IpMatcher::class))->newInstanceWithoutConstructor();
    }

    public function testIPv4ExactMatch(): void
    {
        self::assertTrue($this->m->matches('192.168.1.10', ['192.168.1.10']));
        self::assertFalse($this->m->matches('192.168.1.11', ['192.168.1.10']));
    }

    public function testIPv4Cidr(): void
    {
        self::assertTrue($this->m->matches('192.168.1.55', ['192.168.1.0/24']));
        self::assertFalse($this->m->matches('192.168.2.55', ['192.168.1.0/24']));
        self::assertTrue($this->m->matches('10.0.0.1', ['10.0.0.0/8']));
    }

    public function testIPv6ExactAndCidr(): void
    {
        self::assertTrue($this->m->matches('::1', ['::1']));
        self::assertTrue($this->m->matches('2001:db8::1', ['2001:db8::/32']));
        self::assertFalse($this->m->matches('2001:dba::1', ['2001:db8::/32']));
    }

    public function testEmptyRulesAlwaysFalse(): void
    {
        self::assertFalse($this->m->matches('1.2.3.4', []));
    }

    public function testInvalidIpReturnsFalse(): void
    {
        self::assertFalse($this->m->matches('not-an-ip', ['1.2.3.4']));
    }
}
