<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Action\Auth;

use MyPortfolio\Action\Auth\SetupStatusAction;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Middleware\FirstRunLockMiddleware;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\PasskeyService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class SetupStatusActionTest extends TestCase
{
    public function testPasswordlessLoginIsExposedOnlyWhenConfiguredAndAvailable(): void
    {
        $lock = $this->createMock(FirstRunLockMiddleware::class);
        $lock->method('needsSetup')->willReturn(false);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::once())->method('isAvailable')->willReturn(true);
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $action = new SetupStatusAction(
            $lock,
            new Config([
                'auth' => ['passwordless_login' => ['enabled' => true]],
                'captcha' => ['provider' => 'none'],
                'app' => ['timezone' => 'UTC'],
            ]),
            $passkeys,
            $policy,
        );

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/setup-status'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['passwordless_login_enabled']);
        self::assertSame('UTC', $body['timezone']);
    }

    public function testPasswordlessLoginDefaultsToHiddenWithoutProbingWebAuthn(): void
    {
        $lock = $this->createMock(FirstRunLockMiddleware::class);
        $lock->method('needsSetup')->willReturn(false);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::never())->method('isAvailable');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::never())->method('isMethodAllowed');
        $action = new SetupStatusAction(
            $lock,
            new Config(['captcha' => ['provider' => 'none']]),
            $passkeys,
            $policy,
        );

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/setup-status'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($body['passwordless_login_enabled']);
        self::assertSame('Europe/Prague', $body['timezone']);
    }
}
