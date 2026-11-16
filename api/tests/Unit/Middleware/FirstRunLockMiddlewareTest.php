<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Middleware;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\FirstRunLockMiddleware;
use MyPortfolio\Service\Auth\SetupToken;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Čerstvá instalace: zápisy průvodce /setup jen s instalačním kódem. */
final class FirstRunLockMiddlewareTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/mp-setup-token-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function respond(string $method, string $path, ?string $token = null): int
    {
        $pdo = $this->createStub(\PDO::class);
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(0);
        $pdo->method('query')->willReturn($stmt);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($token !== null) {
            $request = $request->withHeader(SetupToken::HEADER, $token);
        }
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
        return (new FirstRunLockMiddleware($db, new ResponseFactory(), new SetupToken($this->file)))
            ->process($request, $handler)->getStatusCode();
    }

    public function testSetupRequiresInstallCode(): void
    {
        $code = (new SetupToken($this->file))->ensure();

        self::assertSame(403, $this->respond('POST', '/api/auth/setup'));
        self::assertSame(403, $this->respond('POST', '/api/auth/setup', str_repeat('0', 32)));
        self::assertSame(403, $this->respond('POST', '/api/auth/setup/smtp-test'));
        self::assertSame(204, $this->respond('POST', '/api/auth/setup', $code));
        self::assertSame(204, $this->respond('POST', '/api/auth/setup/smtp-test', " $code "));
    }

    public function testSetupStatusCreatesMissingCode(): void
    {
        self::assertFileDoesNotExist($this->file);
        self::assertSame(204, $this->respond('GET', '/api/auth/setup-status'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', trim((string) file_get_contents($this->file)));
        self::assertSame(423, $this->respond('GET', '/api/portfolios'));
    }

    public function testClearedCodeNoLongerMatches(): void
    {
        $token = new SetupToken($this->file);
        $code = $token->ensure();
        self::assertSame($code, $token->ensure(), 'ensure kód nemění');
        $token->clear();
        self::assertFalse($token->matches($code));
    }
}
