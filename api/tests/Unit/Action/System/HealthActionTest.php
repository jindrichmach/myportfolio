<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Action\System;

use MyPortfolio\Action\System\HealthAction;
use MyPortfolio\Infrastructure\Cache\RedisProbe;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\PasskeyService;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Auth\SessionLockPolicy;
use MyPortfolio\Service\Update\VersionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class HealthActionTest extends TestCase
{
    public function testAuthenticatedHealthReportsUnavailablePasskeyConfiguration(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('ping')->willReturn(true);
        $redis = $this->createMock(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('validateKey')->willReturn(null);
        $version = $this->createMock(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('test');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::once())->method('isAvailable')->willReturn(false);
        $passkeys->expects(self::once())
            ->method('configurationError')
            ->willReturn('WebAuthn vyžaduje HTTPS.');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $action = new HealthAction(
            $db,
            $redis,
            $crypto,
            $version,
            $passkeys,
            $policy,
            new SessionLockPolicy(new Config([])),
        );

        $response = $action(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/health')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([[
            'code' => 'webauthn_configuration',
            'message' => 'WebAuthn vyžaduje HTTPS.',
        ]], $body['warnings']);
    }

    public function testAnonymousHealthDoesNotResolveSensitiveWarnings(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('ping')->willReturn(true);
        $redis = $this->createMock(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->expects(self::never())->method('validateKey');
        $version = $this->createMock(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('test');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::never())->method('isAvailable');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::never())->method('isMethodAllowed');
        $action = new HealthAction(
            $db,
            $redis,
            $crypto,
            $version,
            $passkeys,
            $policy,
            new SessionLockPolicy(new Config([])),
        );

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/health'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('warnings', $body);
    }

    public function testAuthenticatedHealthReportsInvalidSessionLockConfiguration(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('ping')->willReturn(true);
        $redis = $this->createMock(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('validateKey')->willReturn(null);
        $version = $this->createMock(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('test');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::never())->method('isAvailable');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())
            ->method('isMethodAllowed')
            ->with('passkey')
            ->willReturn(false);
        $action = new HealthAction(
            $db,
            $redis,
            $crypto,
            $version,
            $passkeys,
            $policy,
            new SessionLockPolicy(new Config([
                'session' => ['lock_after_minutes' => 'invalid'],
            ])),
        );

        $response = $action(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/health')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('session_lock_configuration', $body['warnings'][0]['code']);
        self::assertStringContainsString(
            'výchozí automatický zámek byl vypnut',
            $body['warnings'][0]['message'],
        );
    }
}
