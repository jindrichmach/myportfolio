<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\BankNotice;

use MyPortfolio\Service\BankNotice\ImapHostGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImapHostGuardTest extends TestCase
{
    /** @return array<string,array{0:string,1:bool}> */
    public static function addresses(): array
    {
        return [
            'public v4'      => ['203.0.113.10', true],
            'public v6'      => ['2a00:1450:4001::1', true],
            'loopback'       => ['127.0.0.1', false],
            'private 10'     => ['10.1.2.3', false],
            'private 192'    => ['192.168.1.1', false],
            'cgnat'          => ['100.64.1.1', false],
            'metadata'       => ['169.254.169.254', false],
            'zero'           => ['0.0.0.0', false],
            'v6 loopback'    => ['::1', false],
            'v6 ula'         => ['fd00::1', false],
            'v6 link-local'  => ['fe80::1', false],
            'v4-mapped loop' => ['::ffff:127.0.0.1', false],
        ];
    }

    #[DataProvider('addresses')]
    public function testIsPublic(string $ip, bool $public): void
    {
        self::assertSame($public, ImapHostGuard::isPublic($ip));
    }
}
