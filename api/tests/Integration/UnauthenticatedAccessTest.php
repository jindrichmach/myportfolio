<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Black-box integration test — bez session cookie.
 * Ověřuje že chráněné endpointy vrací 401, public 200/204.
 *
 * Vyžaduje běžící dev server na env MYPORTFOLIO_TEST_URL (default http://127.0.0.1:8080).
 * Spustit s: MYPORTFOLIO_TEST_URL=http://127.0.0.1:8080 vendor/bin/phpunit --testsuite=Integration
 */
#[Group('integration')]
final class UnauthenticatedAccessTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim((string) (getenv('MYPORTFOLIO_TEST_URL') ?: 'http://127.0.0.1:8080'), '/');
        // Skip pokud server nedostupný (CI bez deploye)
        $h = $this->request('GET', '/api/health');
        if ($h['status'] === 0) {
            $this->markTestSkipped("Dev server {$this->baseUrl} nedostupný — preskakuji integration testy.");
        }
    }

    /** @return array<string,array{string,string}> */
    public static function protectedEndpoints(): array
    {
        return [
            'me'            => ['GET', '/api/auth/me'],
            'profile'       => ['GET', '/api/settings/profile'],
            'api tokens'    => ['GET', '/api/auth/tokens'],
            'admin users'   => ['GET', '/api/admin/users'],
            'admin cron'    => ['GET', '/api/admin/cron-jobs'],
            'admin update'  => ['GET', '/api/admin/update'],
            'activity log'  => ['GET', '/api/admin/activity-log'],
            'institutions'  => ['GET', '/api/institutions'],
            'instruments'   => ['GET', '/api/instruments'],
            'accounts'      => ['GET', '/api/accounts'],
            'portfolios'    => ['GET', '/api/portfolios'],
            'positions'     => ['GET', '/api/positions'],
            'transactions'  => ['GET', '/api/transactions'],
            'transfer'      => ['POST', '/api/transfers'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function testProtectedEndpointReturns401(string $method, string $path): void
    {
        $r = $this->request($method, $path);
        self::assertSame(401, $r['status'], "$method $path bez session musí vrátit 401");
    }

    public function testPublicHealthOk(): void
    {
        $r = $this->request('GET', '/api/health');
        self::assertSame(200, $r['status']);
    }

    public function testPublicSetupStatusOk(): void
    {
        $r = $this->request('GET', '/api/auth/setup-status');
        self::assertSame(200, $r['status']);
    }

    public function testCsrfRequiredForMutation(): void
    {
        $r = $this->request('PUT', '/api/settings/profile', '{}');
        self::assertContains($r['status'], [401, 403], 'PUT bez auth ani CSRF nesmí projít');
    }

    /**
     * @return array{status:int, body:string}
     */
    private function request(string $method, string $path, ?string $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => false, // dev self-signed
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['status' => (int) $status, 'body' => is_string($resp) ? $resp : ''];
    }
}
