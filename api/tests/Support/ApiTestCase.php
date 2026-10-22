<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Support;

use MyPortfolio\Bootstrap;
use MyPortfolio\Infrastructure\Cache\RedisFactory;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\SessionAuthContext;
use MyPortfolio\Service\Auth\SessionManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * In-process HTTP testy celým Slim stackem (middleware + routy) proti testovací DB.
 *
 * Předpoklad: DB `myportfolio_test` (phpunit.xml) s migracemi a `bin/ci-seed.php`
 * (admin + user). Každý test si doménová data obou fixture uživatelů na začátku smaže.
 */
abstract class ApiTestCase extends TestCase
{
    protected App $app;
    protected ContainerInterface $container;
    protected Connection $db;
    protected int $adminId = 0;
    protected int $userId = 0;

    /** @var array<int,array{token:string,csrf:string}> */
    private array $sessions = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->app = Bootstrap::buildApp();
            $this->container = $this->app->getContainer() ?? throw new \RuntimeException('DI container chybí.');
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('transactions')) {
            self::markTestSkipped('Migrace 0003_domain neproběhla.');
        }
        $pdo = $this->db->pdo();
        $this->adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'fixture@example.invalid'")->fetchColumn();
        $this->userId = (int) $pdo->query("SELECT id FROM users WHERE email = 'fixture-user@example.invalid'")->fetchColumn();
        if ($this->adminId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí fixture uživatelé — spusť api/bin/ci-seed.php.');
        }
        $this->cleanDomainData();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanDomainData();
            $this->db->close();
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,\Psr\Http\Message\UploadedFileInterface> $files multipart upload
     * @param array<string,string> $headers další hlavičky (např. X-Owner pro sdílení)
     * @return array{status:int,body:mixed,raw:string,headers:array<string,list<string>>}
     */
    protected function call(int $asUserId, string $method, string $path, ?array $body = null, array $files = [], array $headers = []): array
    {
        $session = $this->sessions[$asUserId] ??= $this->login($asUserId);
        $config = $this->container->get(Config::class);
        $uri = parse_url($path);
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([(string) $config->get('session.cookie_name', '__Host-myportfolio_session') => $session['token']])
            ->withHeader('Origin', rtrim((string) $config->get('app.url'), '/'))
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-Token', $session['csrf']);
        if (isset($uri['query'])) {
            parse_str($uri['query'], $query);
            $request = $request->withQueryParams($query);
        }
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        if ($files !== []) {
            $request = $request->withUploadedFiles($files);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $response = $this->app->handle($request);
        $raw = (string) $response->getBody();
        $type = $response->getHeaderLine('Content-Type');
        $json = $raw !== '' && !str_starts_with($type, 'image/') && !str_starts_with($type, 'text/html') && !str_starts_with($type, 'text/csv');
        return [
            'status'  => $response->getStatusCode(),
            'body'    => $json ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null,
            'raw'     => $raw,
            'headers' => $response->getHeaders(),
        ];
    }

    /** @return array{token:string,csrf:string} */
    private function login(int $userId): array
    {
        $session = $this->container->get(SessionManager::class)
            ->create($userId, '127.0.0.1', 'PHPUnit', SessionAuthContext::basic('password'));
        return ['token' => (string) $session['token'], 'csrf' => (string) $session['csrf_token']];
    }

    private function cleanDomainData(): void
    {
        $pdo = $this->db->pdo();
        $ids = implode(',', [$this->adminId, $this->userId]);
        foreach (['api_tokens', 'net_worth_entries', 'net_worth_items', 'allocation_targets', 'exchange_connections', 'alert_log', 'price_alerts', 'import_previews', 'bank_notice_messages', 'imap_accounts', 'daily_snapshots', 'snapshot_dirty', 'tax_lots', 'manual_valuations', 'transactions', 'import_batches', 'positions', 'portfolios', 'accounts'] as $table) {
            $pdo->exec("DELETE FROM $table WHERE user_id IN ($ids)");
        }
        $pdo->exec("DELETE FROM shares WHERE owner_user_id IN ($ids) OR grantee_user_id IN ($ids)");
        $pdo->exec("DELETE FROM instruments WHERE created_by_user_id IN ($ids)");
        $pdo->exec("DELETE FROM institutions WHERE created_by_user_id IN ($ids)");
        $pdo->exec("DELETE FROM sessions WHERE user_id IN ($ids)");
        $this->sessions = [];

        // Per-uživatelské rate limity (jen s Redisem, např. v CI) — testy jedou za sebou pod stejnými fixture
        // uživateli a jinak by se během minuty vyčerpal limit mutací / čtení a další testy by dostaly 429.
        $redis = $this->container->get(RedisFactory::class)->client();
        foreach ([$this->adminId, $this->userId] as $id) {
            $redis?->del(["rl:mut:user:$id", "rl:read:user:$id"]);
        }
    }
}
