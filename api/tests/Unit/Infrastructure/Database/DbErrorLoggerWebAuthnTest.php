<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Infrastructure\Database;

use MyPortfolio\Infrastructure\Database\DbErrorLogger;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DbErrorLoggerWebAuthnTest extends TestCase
{
    public function testWebAuthnCredentialParamsAreRedacted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('DB error'),
                self::callback(static function (array $context): bool {
                    return $context['params'] === [
                        '__redacted__' => '*** params hidden (sensitive column referenced) ***',
                    ];
                }),
            );

        DbErrorLogger::log(
            $logger,
            new PDOException('synthetic failure'),
            'INSERT INTO webauthn_credentials (credential_id, public_key) VALUES (?, ?)',
            ['secret-credential-id', 'secret-public-key'],
        );
    }
}
