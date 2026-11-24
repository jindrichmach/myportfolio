<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Admin;

use MyPortfolio\Action\Admin\BackupsAction;
use MyPortfolio\Action\Admin\RestoreBackupAction;
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
use MyPortfolio\Service\Auth\StepUpGate;
use MyPortfolio\Service\Auth\StepUpOperationException;
use MyPortfolio\Service\Auth\TotpVerifier;
use MyPortfolio\Service\Backup\RestorePreflight;
use MyPortfolio\Service\IpMatcher;
use MyPortfolio\Service\Backup\RestoreState;
use MyPortfolio\Service\Update\UpdateState;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use ZipArchive;

#[AllowMockObjectsWithoutExpectations]
final class BackupsActionTest extends TestCase
{
    private const FILE = 'myportfolio-2026-11-20_03-00.zip';

    private string $dir;
    /** @var list<array{string,list<string>}> */
    private array $spawned = [];
    /** @var array<string,mixed> */
    private array $userRow = ['totp_secret' => null, 'totp_enabled' => 0];
    private bool $totpOk = true;
    private bool $zipLoaded = true;
    private string $password = '';
    private MfaProtectedOperationService $protectedOps;
    private ActivityLogger $activity;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/backups-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/backup', 0775, true);
        $this->spawned = [];
        $this->protectedOps = $this->createMock(MfaProtectedOperationService::class);
        $this->activity = $this->createMock(ActivityLogger::class);
        $this->makeZip(self::FILE, false);
        file_put_contents($this->dir . '/backup/myportfolio-pdf-2026-11-20_03-00.zip', 'x');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function makeZip(string $name, bool $encrypted): void
    {
        $zip = new ZipArchive();
        $zip->open($this->dir . '/backup/' . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('myportfolio-2026-11-20.sql', 'SELECT 1;');
        if ($encrypted) {
            $zip->setEncryptionName('myportfolio-2026-11-20.sql', ZipArchive::EM_AES_256, 'pw');
        }
        $zip->close();
    }

    private function config(): Config
    {
        return new Config([
            'storage' => ['backup_dir' => $this->dir . '/backup'],
            'cron' => ['backup' => ['password' => $this->password]],
        ]);
    }

    private function preflight(): RestorePreflight
    {
        return new RestorePreflight(
            $this->state(),
            new UpdateState($this->dir . '/update-state.json'),
            restoreLock: $this->dir . '/restore.lock',
            updateLock: $this->dir . '/update.lock',
            storageDir: $this->dir,
            zipLoaded: $this->zipLoaded,
        );
    }

    private function state(): RestoreState
    {
        return new RestoreState($this->dir . '/restore-state.json');
    }

    private function gate(): StepUpGate
    {
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

        return new StepUpGate(
            $db, $totp, $crypto, $this->createMock(BruteForceGuard::class), $this->createMock(MfaPolicyService::class),
            $this->createMock(PasskeyCredentialRepository::class), $this->protectedOps,
        );
    }

    private function restore(): RestoreBackupAction
    {
        return new RestoreBackupAction($this->config(), $this->preflight(), $this->state(), $this->gate(),
            startLock: $this->dir . '/restore-start.lock', logFile: $this->dir . '/restore.log',
            spawner: function (string $script, array $args): bool {
                $this->spawned[] = [$script, $args];
                return true;
            });
    }

    private function list(): BackupsAction
    {
        return new BackupsAction($this->config(), $this->preflight(), $this->state(), $this->gate(), $this->activity, $this->createMock(IpMatcher::class), $this->dir . '/restore.log');
    }

    private function request(string $method, string $role, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/admin/backups')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5, 'role' => $role, 'email' => 'admin@example.invalid'])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withParsedBody($body);
    }

    /** @return array{int, array<string,mixed>} */
    private function callRestore(string $role, array $body): array
    {
        $r = $this->restore()($this->request('POST', $role, $body), (new ResponseFactory())->createResponse());
        return [$r->getStatusCode(), json_decode((string) $r->getBody(), true)];
    }

    /** @param array<string,mixed> $body */
    private function callDownload(string $name, array $body = [], string $role = 'admin'): \Psr\Http\Message\ResponseInterface
    {
        return $this->list()->download($this->request('POST', $role, $body), (new ResponseFactory())->createResponse(), ['name' => $name]);
    }

    private function good(): array
    {
        return ['file' => self::FILE, 'confirm' => self::FILE];
    }

    public function testListShowsBackupsAndReasons(): void
    {
        $this->makeZip('myportfolio-2026-11-21_03-00.zip', true);
        $this->zipLoaded = false;
        $r = $this->list()($this->request('GET', 'admin'), (new ResponseFactory())->createResponse());
        $data = json_decode((string) $r->getBody(), true);

        self::assertSame(200, $r->getStatusCode());
        self::assertSame(['myportfolio-2026-11-21_03-00.zip', self::FILE], array_column($data['backups'], 'name'));
        self::assertSame([false, true], array_column($data['backups'], 'restorable'));
        self::assertContains('no_zip', $data['restore']['reasons']);
        self::assertSame('idle', $data['restore']['last_run']['status']);
    }

    public function testListWithPasswordMakesEncryptedRestorable(): void
    {
        $this->makeZip('myportfolio-2026-11-21_03-00.zip', true);
        $this->password = 'pw';
        $r = $this->list()($this->request('GET', 'admin'), (new ResponseFactory())->createResponse());
        $data = json_decode((string) $r->getBody(), true);
        self::assertSame([true, true], array_column($data['backups'], 'restorable'));
    }

    public function testListLastRunTailReplacesAppRoot(): void
    {
        $this->state()->write(['status' => 'failed', 'file' => self::FILE, 'error_step' => 'import']);
        file_put_contents($this->dir . '/restore.log', \MyPortfolio\Bootstrap::rootDir() . "/x\nkonec\n");
        $r = $this->list()($this->request('GET', 'admin'), (new ResponseFactory())->createResponse());
        $run = json_decode((string) $r->getBody(), true)['restore']['last_run'];
        self::assertSame(['<app>/x', 'konec'], $run['log_tail']);
        self::assertFalse($run['interrupted']);
    }

    public function testListMarksDeadRunningAsInterrupted(): void
    {
        $this->state()->write(['status' => 'running', 'started_at' => date(\DateTimeInterface::ATOM, time() - 600)]);
        $r = $this->list()($this->request('GET', 'admin'), (new ResponseFactory())->createResponse());
        self::assertTrue(json_decode((string) $r->getBody(), true)['restore']['last_run']['interrupted']);
    }

    public function testNonAdminGets403(): void
    {
        [$status] = $this->callRestore('user', $this->good());
        self::assertSame(403, $status);
        self::assertSame(403, $this->list()($this->request('GET', 'user'), (new ResponseFactory())->createResponse())->getStatusCode());
        self::assertSame(403, $this->callDownload(self::FILE, [], 'user')->getStatusCode());
        self::assertSame([], $this->spawned);
    }

    public function testUnknownOrInvalidFileGets404(): void
    {
        foreach (['myportfolio-2026-01-01.zip', '../backup/' . self::FILE, '/etc/passwd', 'myportfolio-pdf-2026-11-20_03-00.zip', ''] as $f) {
            [$status] = $this->callRestore('admin', ['file' => $f, 'confirm' => $f]);
            self::assertSame(404, $status, $f);
        }
        self::assertSame([], $this->spawned);
    }

    public function testConfirmMismatchGets422(): void
    {
        [$status, $body] = $this->callRestore('admin', ['file' => self::FILE, 'confirm' => 'jine.zip']);
        self::assertSame(422, $status);
        self::assertSame('confirm_mismatch', $body['error']['code']);
        self::assertSame([], $this->spawned);
    }

    public function testPreflightReasonsGive409(): void
    {
        $this->zipLoaded = false;
        [$status, $body] = $this->callRestore('admin', $this->good());
        self::assertSame(409, $status);
        self::assertContains('no_zip', $body['error']['reasons']);
        self::assertSame([], $this->spawned);
    }

    public function testRunningRestoreGives409(): void
    {
        $h = fopen($this->dir . '/restore.lock', 'c');
        flock($h, LOCK_EX);
        [$status, $body] = $this->callRestore('admin', $this->good());
        fclose($h);
        self::assertSame(409, $status);
        self::assertContains('restore_running', $body['error']['reasons']);
    }

    public function testRunningUpdateGives409(): void
    {
        (new UpdateState($this->dir . '/update-state.json'))->write(['status' => 'running', 'started_at' => date(\DateTimeInterface::ATOM)]);
        [$status, $body] = $this->callRestore('admin', $this->good());
        self::assertSame(409, $status);
        self::assertContains('update_running', $body['error']['reasons']);
    }

    public function testEncryptedWithoutPasswordGives409(): void
    {
        $this->makeZip('myportfolio-2026-11-21_03-00.zip', true);
        $f = 'myportfolio-2026-11-21_03-00.zip';
        [$status, $body] = $this->callRestore('admin', ['file' => $f, 'confirm' => $f]);
        self::assertSame(409, $status);
        self::assertContains('no_password', $body['error']['reasons']);
    }

    public function testStartsRunnerAndReturns202(): void
    {
        [$status, $body] = $this->callRestore('admin', $this->good());
        self::assertSame(202, $status);
        self::assertSame(self::FILE, $body['file']);
        self::assertCount(1, $this->spawned);
        self::assertStringEndsWith('/api/bin/restore-backup.php', $this->spawned[0][0]);
        self::assertSame([self::FILE, '--yes', '--web', '--actor=5'], $this->spawned[0][1]);
        self::assertSame('running', $this->state()->read()['status']);
    }

    public function testSpawnFailureMarksFailed(): void
    {
        $action = new RestoreBackupAction($this->config(), $this->preflight(), $this->state(), $this->gate(), startLock: $this->dir . '/restore-start.lock', logFile: $this->dir . '/restore.log', spawner: static fn (): bool => false);
        $r = $action($this->request('POST', 'admin', $this->good()), (new ResponseFactory())->createResponse());
        self::assertSame(500, $r->getStatusCode());
        self::assertSame('failed', $this->state()->read()['status']);
    }

    public function testStepUpMissingProofIsRejected(): void
    {
        $this->userRow = ['totp_secret' => 'enc', 'totp_enabled' => 1];
        [$status, $body] = $this->callRestore('admin', $this->good());
        self::assertSame(403, $status);
        self::assertSame('step_up_required', $body['error']['code']);
        self::assertSame([], $this->spawned);
    }

    public function testStepUpValidTotpStarts(): void
    {
        $this->userRow = ['totp_secret' => 'enc', 'totp_enabled' => 1];
        [$status] = $this->callRestore('admin', $this->good() + ['totp_code' => '123456']);
        self::assertSame(202, $status);
    }

    public function testStepUpInvalidTotpIsRejected(): void
    {
        $this->userRow = ['totp_secret' => 'enc', 'totp_enabled' => 1];
        $this->totpOk = false;
        [$status, $body] = $this->callRestore('admin', $this->good() + ['totp_code' => '000000']);
        self::assertSame(403, $status);
        self::assertSame('step_up_proof_invalid', $body['error']['code']);
        self::assertSame([], $this->spawned);
    }

    public function testProofIsConsumedForRestoreOperation(): void
    {
        $this->protectedOps->expects(self::once())->method('consumeProof')
            ->with(5, str_repeat('a', 64), 'proof', MfaStepUpService::OPERATION_BACKUP_RESTORE);
        [$status] = $this->callRestore('admin', $this->good() + ['step_up_token' => 'proof']);
        self::assertSame(202, $status);
    }

    public function testReusedAndRecoveryProofsAreRejected(): void
    {
        foreach ([new OneTimeTokenException('použito'), new StepUpOperationException('Záložní kód nelze.')] as $e) {
            $this->protectedOps = $this->createMock(MfaProtectedOperationService::class);
            $this->protectedOps->method('consumeProof')->willThrowException($e);
            [$status, $body] = $this->callRestore('admin', $this->good() + ['step_up_token' => 'x']);
            self::assertSame(403, $status);
            self::assertSame('step_up_proof_invalid', $body['error']['code']);
        }
        self::assertSame([], $this->spawned);
    }

    public function testDownloadStreamsZip(): void
    {
        $r = $this->callDownload(self::FILE);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('application/zip', $r->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment; filename="' . self::FILE . '"', $r->getHeaderLine('Content-Disposition'));
        self::assertSame(file_get_contents($this->dir . '/backup/' . self::FILE), (string) $r->getBody());
    }

    public function testDownloadTraversalAndUnknownGet404(): void
    {
        foreach (['../backup/' . self::FILE, '/etc/passwd', 'myportfolio-2026-01-01.zip', 'myportfolio-pdf-2026-11-20_03-00.zip'] as $n) {
            self::assertSame(404, $this->callDownload($n)->getStatusCode(), $n);
        }
    }

    public function testDownloadRequiresStepUp(): void
    {
        $this->userRow = ['totp_secret' => 'enc', 'totp_enabled' => 1];
        self::assertSame(403, $this->callDownload(self::FILE)->getStatusCode());
        $this->protectedOps->expects(self::once())->method('consumeProof')
            ->with(5, str_repeat('a', 64), 'proof', MfaStepUpService::OPERATION_BACKUP_DOWNLOAD);
        self::assertSame(200, $this->callDownload(self::FILE, ['step_up_token' => 'proof'])->getStatusCode());
    }

    public function testConcurrentStartHoldingLockGives409(): void
    {
        $h = fopen($this->dir . '/restore-start.lock', 'c');
        flock($h, LOCK_EX);
        [$status, $body] = $this->callRestore('admin', $this->good());
        fclose($h);
        self::assertSame(409, $status);
        self::assertSame(['restore_running'], $body['error']['reasons']);
        self::assertSame([], $this->spawned);
    }

    public function testStartWritesActorEmailAndClearsOldLog(): void
    {
        file_put_contents($this->dir . '/restore.log', "stary beh\n");
        $this->callRestore('admin', $this->good());
        self::assertSame('admin@example.invalid', $this->state()->read()['actor_email']);
        self::assertSame('', file_get_contents($this->dir . '/restore.log'));
    }

    public function testStartingStateHidesLogTail(): void
    {
        file_put_contents($this->dir . '/restore.log', "stary beh\n");
        $this->state()->write(['status' => 'running', 'step' => 'starting', 'started_at' => date(\DateTimeInterface::ATOM)]);
        $r = $this->list()($this->request('GET', 'admin'), (new ResponseFactory())->createResponse());
        self::assertSame([], json_decode((string) $r->getBody(), true)['restore']['last_run']['log_tail']);
    }

    public function testDownloadIsLogged(): void
    {
        $this->activity->expects(self::once())->method('log')
            ->with('admin.backup.download', 5, null, null, ['file' => self::FILE]);
        self::assertSame(200, $this->callDownload(self::FILE)->getStatusCode());
    }

    public function testDeniedDownloadIsNotLogged(): void
    {
        $this->activity->expects(self::never())->method('log');
        $this->userRow = ['totp_secret' => 'enc', 'totp_enabled' => 1];
        self::assertSame(403, $this->callDownload(self::FILE)->getStatusCode());
    }
}
