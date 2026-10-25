<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Admin;

use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Filtry activity logu: hledání (q), datumové rozmezí (from, to) a jejich kombinace. */
#[Group('integration')]
final class ActivityLogFilterTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanLog();
        $insert = $this->db->pdo()->prepare('INSERT INTO activity_log (user_id, action, created_at) VALUES (?, ?, ?)');
        $insert->execute([$this->userId, 'test.fltr.alpha', '2001-03-10 23:30:00']);
        $insert->execute([$this->adminId, 'test.fltr.beta', '2001-04-01 00:00:00']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanLog();
        }
        parent::tearDown();
    }

    public function testDateRangeIncludesWholeLastDay(): void
    {
        $res = $this->log(['q' => 'fltr', 'from' => '2001-03-10', 'to' => '2001-03-10']);

        self::assertSame(200, $res['status'], $res['raw']);
        self::assertSame(1, $res['body']['total'], 'Záznam z 23:30 posledního dne se má započítat.');
        self::assertSame('test.fltr.alpha', $res['body']['data'][0]['action']);
    }

    public function testTextSearchMatchesActionAndUserEmail(): void
    {
        self::assertSame(2, $this->log(['q' => 'fltr'])['body']['total']);
        self::assertSame(1, $this->log(['q' => 'alpha'])['body']['total']);
        self::assertSame(1, $this->log(['q' => 'fixture-user@example.invalid', 'from' => '2001-01-01', 'to' => '2001-12-31'])['body']['total']);
    }

    public function testSearchTreatsWildcardsAsText(): void
    {
        self::assertSame(0, $this->log(['q' => '%'])['body']['total']);
    }

    public function testInvalidDatesAreIgnored(): void
    {
        $res = $this->log(['q' => 'fltr', 'from' => 'nedatum']);

        self::assertSame(200, $res['status']);
        self::assertSame(2, $res['body']['total']);
    }

    /** @param array<string,string> $query */
    private function log(array $query): array
    {
        return $this->call($this->adminId, 'GET', '/api/admin/activity-log?' . http_build_query($query));
    }

    private function cleanLog(): void
    {
        $this->db->pdo()->exec("DELETE FROM activity_log WHERE action LIKE 'test.fltr.%'");
    }
}
