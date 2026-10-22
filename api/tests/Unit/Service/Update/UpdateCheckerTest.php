<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Update;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Market\MarketDataException;
use MyPortfolio\Service\Update\UpdateChecker;
use MyPortfolio\Service\Update\VersionService;
use MyPortfolio\Tests\Support\FakeHttpFetcher;
use PHPUnit\Framework\TestCase;

final class UpdateCheckerTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/mp-update-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cache);
    }

    private const RELEASES = '[
        {"tag_name":"v0.5.0-dev","name":"draft","draft":true,"prerelease":true},
        {"tag_name":"v0.4.0-dev","name":"0.4.0-dev — novinky","draft":false,"prerelease":true,
         "published_at":"2026-11-01T10:00:00Z","html_url":"https://github.com/example/repo/releases/tag/v0.4.0-dev","body":"### Přidáno\n- něco"},
        {"tag_name":"v0.3.0-dev","name":"0.3.0-dev","draft":false,"prerelease":true},
        {"tag_name":"nightly","draft":false}
    ]';

    public function testPicksHighestPublishedReleaseIncludingPrerelease(): void
    {
        $latest = UpdateChecker::pickLatest(self::RELEASES);

        self::assertNotNull($latest);
        self::assertSame('0.4.0-dev', $latest['version']);
        self::assertSame('0.4.0-dev — novinky', $latest['name']);
        self::assertTrue($latest['prerelease']);
        self::assertStringContainsString('### Přidáno', $latest['notes']);
    }

    public function testStableReleaseIsNewerThanItsDevVersion(): void
    {
        $latest = UpdateChecker::pickLatest('[{"tag_name":"v0.4.0-dev"},{"tag_name":"v0.4.0"}]');

        self::assertSame('0.4.0', $latest['version'] ?? null);
    }

    public function testInvalidResponseThrows(): void
    {
        $this->expectException(MarketDataException::class);
        UpdateChecker::pickLatest('{"message":"Not Found"}');
    }

    public function testReportsAvailableUpdateAndCachesResult(): void
    {
        $http = new FakeHttpFetcher(['repos/example/repo/releases' => self::RELEASES]);
        $checker = $this->checker($http, '0.3.0-dev');

        $first = $checker->check();
        $second = $checker->check();

        self::assertTrue($first['update_available']);
        self::assertSame('0.4.0-dev', $first['latest']['version'] ?? null);
        self::assertNull($first['error']);
        self::assertSame($first, $second);
        self::assertCount(1, $http->requested, 'druhá kontrola jde z cache');

        $checker->check(refresh: true);
        self::assertCount(2, $http->requested, 'refresh cache obchází');
    }

    public function testUpToDateWhenCurrentIsLatest(): void
    {
        $result = $this->checker(new FakeHttpFetcher(['releases' => self::RELEASES]), '0.4.0-dev')->check();

        self::assertFalse($result['update_available']);
    }

    public function testUnavailableSourceKeepsLastKnownResult(): void
    {
        $this->checker(new FakeHttpFetcher(['releases' => self::RELEASES]), '0.3.0-dev')->check();

        $result = $this->checker(new FakeHttpFetcher(['releases' => null]), '0.3.0-dev')->check(refresh: true);

        self::assertSame('unavailable', $result['error']);
        self::assertSame('0.4.0-dev', $result['latest']['version'] ?? null);
        self::assertTrue($result['update_available']);
    }

    public function testInvalidRepoIsNotFetched(): void
    {
        $http = new FakeHttpFetcher([]);
        $result = $this->checker($http, '0.3.0-dev', 'není repo')->check();

        self::assertSame('not_configured', $result['error']);
        self::assertSame([], $http->requested);
    }

    private function checker(FakeHttpFetcher $http, string $current, string $repo = 'example/repo'): UpdateChecker
    {
        $version = $this->createStub(VersionService::class);
        $version->method('getCurrentVersion')->willReturn($current);

        return new UpdateChecker($http, $version, new Config(['update' => ['github_repo' => $repo]]), $this->cache);
    }
}
