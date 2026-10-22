<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Action\Auth;

use MyPortfolio\Action\Auth\Tokens\CreateTokenAction;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Repository\PasskeyCredentialRepository;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\ApiTokenService;
use MyPortfolio\Service\Auth\BruteForceGuard;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\MfaProtectedOperationService;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Auth\TotpService;
use MyPortfolio\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class CreateTokenStepUpTest extends TestCase
{
    public function testPasskeyOnlyUserCannotCreateTokenWithoutPurposeBoundProof(): void
    {
        $db = $this->createMock(Connection::class);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([17])->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn([
            'totp_secret' => null,
            'totp_enabled' => 0,
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())
            ->method('isMethodAllowed')
            ->with('passkey')
            ->willReturn(true);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())->method('countActiveForUser')->with(17)->willReturn(1);
        $tokens = $this->createMock(ApiTokenService::class);
        $tokens->expects(self::never())->method('generate');
        $protectedOperations = $this->createMock(MfaProtectedOperationService::class);
        $protectedOperations->expects(self::never())->method('createApiToken');

        $action = new CreateTokenAction(
            $db,
            $tokens,
            $this->createMock(TotpService::class),
            $this->createMock(SecretEncryption::class),
            $this->createMock(ActivityLogger::class),
            $this->createMock(IpMatcher::class),
            $credentials,
            $policy,
            $this->createMock(BruteForceGuard::class),
            $protectedOperations,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/tokens')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withParsedBody(['name' => 'Synthetic integration token']);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('step_up_required', $body['error']['code']);
        self::assertSame(['passkey'], $body['error']['methods']);
    }
}
