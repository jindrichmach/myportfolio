<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class WebAuthnDeploymentHeadersTest extends TestCase
{
    public function testAllSupportedWebServersAllowSameOriginWebAuthn(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            'Apache' => $root . '/.htaccess',
            'nginx' => $root . '/docker/nginx.conf',
        ] as $server => $path) {
            $configuration = file_get_contents($path);
            self::assertIsString($configuration, $server . ' konfiguraci nelze načíst.');
            self::assertStringContainsString(
                'publickey-credentials-create=(self)',
                $configuration,
                $server . ' musí povolit same-origin registraci passkey.',
            );
            self::assertStringContainsString(
                'publickey-credentials-get=(self)',
                $configuration,
                $server . ' musí povolit same-origin použití passkey.',
            );
        }
    }
}
