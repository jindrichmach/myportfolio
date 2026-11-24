<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Admin;

use MyPortfolio\Action\Admin\RunUpdateAction;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Repository\PasskeyCredentialRepository;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\BruteForceGuard;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\MfaProtectedOperationService;
use MyPortfolio\Service\Auth\MfaStepUpService;
use MyPortfolio\Service\Auth\OneTimeTokenException;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Auth\StepUpOperationException;
use MyPortfolio\Service\Auth\TotpVerifier;
use MyPortfolio\Service\IpMatcher;
use MyPortfolio\Service\Update\UpdateChecker;
use MyPortfolio\Service\Update\UpdateState;
use MyPortfolio\Service\Update\VersionService;
use MyPortfolio\Service\Update\WebUpdatePreflight;
use MyPortfolio\Tests\Support\FakeHttpFetcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class RunUpdateActionTest extends TestCase
{
    private string $dir;
    /** @var list<array{string,list<string>}> */
    private array $spawned = [];
    /** @var list<string> */
    private array $preflightReasons = [];
    /** @var array<string,mixed> */
    private array $userRow = ['totp_secret' => null, 'totp_enabled' => 0];
    private bool $totpOk = true;
    private string $newer = '99.0.0';
    private string $method = 'session';
    private MfaProtectedOperationService $protectedOps;
    private ActivityLogger $activity;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runupd-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->spawned = [];
        $this->preflightReasons = [];
        $this->protectedOps = $this->createMock(MfaProtectedOperationService::class);
        $this->activity = $this->createMock(ActivityLogger::class);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function action(): RunUpdateAction
    {
        $current = (new VersionService())->getCurrentVersion();
        $newer = $this->newer;
        file_put_contents($this->dir . '/check.json', json_encode([
            'repo' => 'example/repo', 'checked_at' => date(\DateTimeInterface::ATOM),
            'releases' => [['version' => $newer, 'name' => $newer, 'published_at' => null, 'url' => '', 'notes' => '', 'prerelease' => false]],
        ]));
        $config = new Config(['update' => ['github_repo' => 'example/repo']]);
        $checker = new UpdateChecker(new FakeHttpFetcher([]), new VersionService(), $config, $this->dir . '/check.json');

        $preflight = $this->createMock(WebUpdatePreflight::class);
        $preflight->method('check')->willReturn($this->preflightReasons);
        $preflight->method('lockFile')->willReturn($this->dir . '/update.lock');

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($this->userRow);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $totp = $this->createMock(TotpVerifier::class);
        $totp->method('verify')->willReturn($this->totpOk);
        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('SECRET');
        $policy = $this->createMock(MfaPolicyService::class);
        $creds = $this->createMock(PasskeyCredentialRepository::class);
        $ip = $this->createMock(IpMatcher::class);

        self::assertNotSame($current, $newer);

        return new RunUpdateAction(
            $checker, $preflight, new UpdateState($this->dir . '/update-state.json'), $config, $db,
            $this->activity, $ip, $totp, $crypto, $this->createMock(BruteForceGuard::class), $policy, $creds, $this->protectedOps,
            spawner: function (string $script, array $args): bool {
                $this->spawned[] = [$script, $args];
                return true;
            },
        );
    }

    /** @param array<string,mixed> $body */
    private function call(string $role, array $body): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/update/run')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $this->method)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withParsedBody($body);
        $response = $this->action()($request, (new ResponseFactory())->createResponse());
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    public function testNonAdminGets403(): void
    {
        [$status] = $this->call('user', ['version' => '99.0.0']);
        self::assertSame(403, $status);
        self::assertSame([], $this->spawned);
    }

    public function testForeignVersionGets422(): void
    {
        [$status] = $this->call('admin', ['version' => '98.0.0']);
        self::assertSame(422, $status);
        [$status] = $this->call('admin', ['version' => '99.0.0; rm -rf /']);
        self::assertSame(422, $status);
        self::assertSame([], $this->spawned);
    }

    public function testPrereleaseLatestIsAllowed(): void
    {
        $this->newer = '99.0.0-rc1';
        [$status] = $this->call('admin', ['version' => '99.0.0-rc1']);
        self::assertSame(202, $status);
        self::assertContains('--target=99.0.0-rc1', $this->spawned[0][1]);
    }

    public function testMalformedVersionGets422(): void
    {
        foreach (['1.2', '1.2.0;rm'] as $v) {
            [$status, $body] = $this->call('admin', ['version' => $v]);
            self::assertSame(422, $status, $v);
            self::assertSame('invalid_version', $body['error']['code']);
        }
        self::assertSame([], $this->spawned);
    }

    public function testBearerTokenGets403(): void
    {
        $this->method = 'bearer';
        [$status, $body] = $this->call('admin', ['version' => '99.0.0']);
        self::assertSame(403, $status);
        self::assertSame('forbidden_via_token', $body['error']['code']);
        self::assertSame([], $this->spawned);
    }

    public function testReusedProofIsRejected(): void
    {
        $this->protectedOps->method('consumeProof')->willThrowException(new OneTimeTokenException('použito'));
        [$status, $body] = $this->call('admin', ['version' => '99.0.0', 'step_up_token' => 'used']);
        self::assertSame(403, $status);
        self::assertSame('step_up_proof_invalid', $body['error']['code']);
        self::assertSame([], $this->spawned);
    }

    public function testPreflightReasonsGive409(): void
    {
        $this->preflightReasons = ['dirty_tree'];
        [$status, $body] = $this->call('admin', ['version' => '99.0.0']);
        self::assertSame(409, $status);
        self::assertSame(['dirty_tree'], $body['error']['reasons']);
        self::assertSame([], $this->spawned);
    }

    public function testRunningStateGives409(): void
    {
        (new UpdateState($this->dir . '/update-state.json'))->write(['status' => 'running', 'started_at' => date(\DateTimeInterface::ATOM)]);
        [$status, $body] = $this->call('admin', ['version' => '99.0.0']);
        self::assertSame(409, $status);
        self::assertSame(['running'], $body['error']['reasons']);
    }

    public function testStartsRunnerAndReturns202(): void
    {
        $this->activity->expects(self::once())->method('log')
            ->with('admin.update.run', 5, null, null, self::callback(static fn (array $p) => $p['target'] === '99.0.0' && isset($p['from'])));
        [$status] = $this->call('admin', ['version' => '99.0.0']);

        self::assertSame(202, $status);
        self::assertCount(1, $this->spawned);
        [$script, $args] = $this->spawned[0];
        self::assertSame($this->dir . '/update/self-update.php', $script);
        self::assertFileExists($script);
        self::assertContains('--target=99.0.0', $args);
        self::assertContains('--state=' . $this->dir . '/update-state.json', $args);
        self::assertContains('--maintenance=' . $this->dir . '/maintenance.json', $args);
        self::assertContains('--lock=' . $this->dir . '/update.lock', $args);
        self::assertSame('running', (new UpdateState($this->dir . '/update-state.json'))->read()['status']);
    }

    public function testStepUpMissingProofIsRejected(): void
    {
        $this->userRow = ['totp_secret' => 'enc', 'totp_enabled' => 1];
        [$status, $body] = $this->call('admin', ['version' => '99.0.0']);
        self::assertSame(403, $status);
        self::assertSame('step_up_required', $body['error']['code']);
        self::assertSame([], $this->spawned);
    }

    public function testStepUpValidTotpStarts(): void
    {
        $this->userRow = ['totp_secret' => 'enc', 'totp_enabled' => 1];
        [$status] = $this->call('admin', ['version' => '99.0.0', 'totp_code' => '123456']);
        self::assertSame(202, $status);
    }

    public function testStepUpInvalidTotpIsRejected(): void
    {
        $this->userRow = ['totp_secret' => 'enc', 'totp_enabled' => 1];
        $this->totpOk = false;
        [$status, $body] = $this->call('admin', ['version' => '99.0.0', 'totp_code' => '000000']);
        self::assertSame(403, $status);
        self::assertSame('step_up_proof_invalid', $body['error']['code']);
        self::assertSame([], $this->spawned);
    }

    public function testStepUpProofIsConsumedForUpdateOperation(): void
    {
        $this->protectedOps->expects(self::once())->method('consumeProof')
            ->with(5, str_repeat('a', 64), 'proof-token', MfaStepUpService::OPERATION_UPDATE_RUN);
        [$status] = $this->call('admin', ['version' => '99.0.0', 'step_up_token' => 'proof-token']);
        self::assertSame(202, $status);
    }

    public function testRecoveryCodeProofIsRejected(): void
    {
        $this->protectedOps->method('consumeProof')->willThrowException(new StepUpOperationException('Záložní kód tuhle operaci potvrdit nemůže.'));
        [$status, $body] = $this->call('admin', ['version' => '99.0.0', 'step_up_token' => 'recovery-proof']);
        self::assertSame(403, $status);
        self::assertSame('step_up_proof_invalid', $body['error']['code']);
        self::assertSame([], $this->spawned);
    }
}
