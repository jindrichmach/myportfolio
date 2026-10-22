<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Mail;

use MyPortfolio\Service\Mail\SmtpSettings;
use PHPUnit\Framework\TestCase;

final class SmtpSettingsDsnTest extends TestCase
{
    private const BASE = ['host' => 'smtp.example.invalid', 'port' => 587, 'auth_enabled' => true, 'username' => 'user@example.invalid',
        'password' => 'p@ss:w/rd', 'verify_peer' => true];

    public function testDsnVariants(): void
    {
        self::assertSame('smtp://user%40example.invalid:p%40ss%3Aw%2Frd@smtp.example.invalid:587?require_tls=true',
            SmtpSettings::dsn(['encryption' => 'tls'] + self::BASE));
        self::assertSame('smtps://user%40example.invalid:p%40ss%3Aw%2Frd@smtp.example.invalid:465',
            SmtpSettings::dsn(['encryption' => 'ssl', 'port' => 465] + self::BASE));
        self::assertSame('smtp://smtp.example.invalid:25?auto_tls=false&verify_peer=0',
            SmtpSettings::dsn(['encryption' => 'none', 'port' => 25, 'auth_enabled' => false] + self::BASE));
        self::assertSame('smtps://user%40example.invalid:p%40ss%3Aw%2Frd@smtp.example.invalid:465?verify_peer=0',
            SmtpSettings::dsn(['encryption' => 'ssl', 'port' => 465, 'verify_peer' => false] + self::BASE));
    }
}
