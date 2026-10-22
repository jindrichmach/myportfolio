<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Mail;

use MyPortfolio\Service\Mail\Mailer;
use PHPUnit\Framework\TestCase;

/** SMTP přepis v debug logu nesmí obsahovat přihlašovací údaje (AUTH LOGIN / PLAIN v base64). */
final class MailerRedactTest extends TestCase
{
    public function testAuthLinesAreRedacted(): void
    {
        $log = Mailer::redactAuth(implode("\n", [
            '[t] > EHLO host', '[t] < 250 OK', '[t] > AUTH PLAIN AHVzZXIAc2VjcmV0', '[t] < 235 OK',
            '[t] > AUTH LOGIN', '[t] < 334 VXNlcm5hbWU6', '[t] > dXNlcg==', '[t] < 334 UGFzc3dvcmQ6', '[t] > c2VjcmV0', '[t] < 235 OK',
            '[t] > MAIL FROM:<a@example.invalid>',
        ]));
        self::assertStringNotContainsString('AHVzZXIAc2VjcmV0', $log);
        self::assertStringNotContainsString('dXNlcg==', $log);
        self::assertStringNotContainsString('c2VjcmV0', $log);
        self::assertStringContainsString('MAIL FROM:<a@example.invalid>', $log);
        self::assertStringContainsString('< 334 UGFzc3dvcmQ6', $log);
    }
}
