<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Admin;

use MyPortfolio\Action\Admin\UpdateStatusAction;
use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Config\Config;
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
final class UpdateStatusActionTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $reasons = ['disabled'];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/updstat-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->reasons = ['disabled'];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string,mixed> */
    private function get(): array
    {
        file_put_contents($this->dir . '/check.json', json_encode([
            'repo' => 'example/repo', 'checked_at' => date(\DateTimeInterface::ATOM), 'releases' => [],
        ]));
        $config = new Config(['update' => ['github_repo' => 'example/repo']]);
        $checker = new UpdateChecker(new FakeHttpFetcher([]), new VersionService(), $config, $this->dir . '/check.json');
        $preflight = $this->createMock(WebUpdatePreflight::class);
        $preflight->method('check')->willReturn($this->reasons);
        $preflight->method('warnings')->willReturn(['git_checkout']);

        $action = new UpdateStatusAction($checker, $config, $preflight, new UpdateState($this->dir . '/state.json'), $this->dir . '/update.log');
        $response = $action((new ServerRequestFactory())->createServerRequest('GET', '/api/admin/update'), (new ResponseFactory())->createResponse());

        return json_decode((string) $response->getBody(), true);
    }

    /** @param array<string,mixed> $state */
    private function state(array $state): void
    {
        (new UpdateState($this->dir . '/state.json'))->write($state);
    }

    public function testExposesPreflightReasons(): void
    {
        $web = $this->get()['web_update'];
        self::assertSame(['disabled'], $web['reasons']);
        self::assertSame(['git_checkout'], $web['warnings']);
    }

    public function testIdleWithoutStateFile(): void
    {
        $run = $this->get()['last_run'];
        self::assertSame('idle', $run['status']);
        self::assertSame([], $run['log_tail']);
        self::assertFalse($run['stale']);
    }

    public function testStateWithoutStatusKeyIsIdle(): void
    {
        $this->state(['target' => '1.2.0']);
        self::assertSame([], $this->get()['last_run']['log_tail']);
    }

    public function testPidIsNotExposed(): void
    {
        $this->state(['status' => 'failed', 'pid' => 4242, 'target' => '1.2.0']);
        $run = $this->get()['last_run'];
        self::assertSame('failed', $run['status']);
        self::assertArrayNotHasKey('pid', $run);
    }

    public function testLogTailIsLimitedAndRootReplaced(): void
    {
        $this->state(['status' => 'failed']);
        $lines = [];
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = $i % 2 === 0 ? "řádek $i " . Bootstrap::rootDir() . '/api' : "řádek $i";
        }
        file_put_contents($this->dir . '/update.log', implode("\n", $lines) . "\n");

        $tail = $this->get()['last_run']['log_tail'];
        self::assertCount(50, $tail);
        self::assertSame('řádek 11', $tail[0]);
        self::assertSame('řádek 60 <app>/api', $tail[49]);
        self::assertStringNotContainsString(Bootstrap::rootDir(), implode("\n", $tail));
    }

    public function testOldRunningWithoutLockIsStale(): void
    {
        $this->state(['status' => 'running', 'started_at' => date(\DateTimeInterface::ATOM, time() - 120)]);
        self::assertTrue($this->get()['last_run']['stale']);
    }

    public function testFreshRunningOrHeldLockIsNotStale(): void
    {
        $this->state(['status' => 'running', 'started_at' => date(\DateTimeInterface::ATOM)]);
        self::assertFalse($this->get()['last_run']['stale']);

        $this->state(['status' => 'running', 'started_at' => date(\DateTimeInterface::ATOM, time() - 120)]);
        $this->reasons = ['running'];
        self::assertFalse($this->get()['last_run']['stale']);
    }
}
