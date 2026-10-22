<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Sharing;

use DI\Container;
use MyPortfolio\Service\Auth\ApiTokenService;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Sdílení pro domácnost na syntetických datech. Vlastník = fixture `user` (portfolia A a B, v každém pozice),
 * partner = fixture `admin`. Izolace: bez sdílení 404, sdílení portfolia A nevidí B, čtení nesmí zapisovat,
 * sekce, audit zápisu partnera, odvolání, API token, pozvánka nového uživatele, domácnost.
 */
#[Group('integration')]
final class SharingTest extends ApiTestCase
{
    private const INVITED = 'sharing-invitee@example.invalid';

    /** @var list<array{0:string,1:list<string>,2:array<string,mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendTemplate')->willReturnCallback(function (string $code, string $locale, array $to, array $vars): string {
            $this->sent[] = [$code, $to, $vars];
            return '250 OK';
        });
        $container = $this->container;
        self::assertInstanceOf(Container::class, $container);
        $container->set(Mailer::class, $mailer);
        $this->db->pdo()->prepare("UPDATE users SET display_currency = 'CZK' WHERE id IN (?, ?)")->execute([$this->userId, $this->adminId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE s FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.email = ?')->execute([self::INVITED]);
            $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::INVITED]);
        }
        parent::tearDown();
    }

    /** Volání partnera v kontextu vlastníka. */
    private function as(string $method, string $path, ?array $body = null): array
    {
        return $this->call($this->adminId, $method, $path, $body, [], ['X-Owner' => (string) $this->userId]);
    }

    /** @return array{a:int,b:int,posA:int,posB:int,account:int} */
    private function holdings(): array
    {
        $inst = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Bank S', 'type' => 'bank'])['body']['id'];
        $acc = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Spoření', 'currency' => 'CZK'])['body']['id'];
        $accB = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $inst, 'name' => 'Jen B', 'currency' => 'CZK'])['body']['id'];
        $a = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Společné'])['body']['id'];
        $b = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Soukromé'])['body']['id'];
        $pos = fn (int $pf, int $account, string $name) => $this->call($this->userId, 'POST', '/api/positions',
            ['valuation_mode' => 'manual', 'portfolio_id' => $pf, 'account_id' => $account, 'name' => $name])['body']['id'];
        $posA = $pos($a, $acc, 'Spořák A');
        $posB = $pos($b, $accB, 'Spořák B');
        foreach ([[$posA, '1000'], [$posB, '5000']] as [$p, $amount]) {
            self::assertSame(201, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $p, 'type' => 'deposit',
                'executed_at' => '2026-01-05 10:00', 'amount' => $amount])['status']);
        }
        return ['a' => $a, 'b' => $b, 'posA' => $posA, 'posB' => $posB, 'account' => $acc];
    }

    private function adminEmail(): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$this->adminId]);
        return (string) $stmt->fetchColumn();
    }

    public function testPortfolioShareReadWriteSectionsAuditAndRevoke(): void
    {
        $h = $this->holdings();
        self::assertSame(404, $this->as('GET', '/api/dashboard')['status']);

        $share = $this->call($this->userId, 'POST', '/api/shares', ['email' => $this->adminEmail(), 'permission' => 'read',
            'scope' => 'portfolios', 'portfolio_ids' => [$h['a']], 'sections' => ['net_worth']]);
        self::assertSame(201, $share['status'], $share['raw']);
        self::assertFalse($share['body']['invited']);
        self::assertSame([], $share['body']['share']['sections']);  // sekce jen u sdílení celého účtu
        self::assertSame('share_granted', $this->sent[0][0]);
        $id = $share['body']['share']['id'];
        self::assertSame(409, $this->call($this->userId, 'POST', '/api/shares', ['email' => $this->adminEmail()])['status']);

        // Partner vidí jen portfolio A
        self::assertSame([$h['posA']], array_column($this->as('GET', '/api/positions')['body'], 'id'));
        self::assertSame([$h['a']], array_column($this->as('GET', '/api/portfolios')['body'], 'id'));
        self::assertSame([$h['account']], array_column($this->as('GET', '/api/accounts')['body'], 'id'));
        self::assertSame(1, $this->as('GET', '/api/transactions')['body']['meta']['total']);
        self::assertSame(404, $this->as('GET', "/api/positions/{$h['posB']}")['status']);
        self::assertSame(404, $this->as('GET', "/api/history?scope=portfolio&id={$h['b']}")['status']);
        self::assertSame('1000.00', $this->as('GET', '/api/dashboard')['body']['totals']['value']);
        self::assertSame('1000.00', $this->as('GET', '/api/allocation')['body']['total']);

        // Čtení: zápis, sekce a cesty mimo allowlist odmítnuty; nastavení zůstává vlastní
        self::assertSame('read_only', $this->as('POST', '/api/transactions', ['position_id' => $h['posA'], 'type' => 'deposit',
            'executed_at' => '2026-02-01 10:00', 'amount' => '1'])['body']['error']['code']);
        self::assertSame('section_not_shared', $this->as('GET', '/api/net-worth')['body']['error']['code']);
        self::assertSame('not_shared', $this->as('POST', '/api/accounts', ['institution_id' => 1, 'name' => 'X'])['body']['error']['code']);
        self::assertSame($this->adminEmail(), $this->as('GET', '/api/settings/profile')['body']['email']);
        self::assertSame(200, $this->as('GET', '/api/price-alerts')['status']);
        self::assertSame(200, $this->as('GET', '/api/bank-notices')['status']);

        // Zápis + celý účet + čisté jmění
        $upd = $this->call($this->userId, 'PUT', "/api/shares/$id", ['permission' => 'write', 'scope' => 'all', 'sections' => ['net_worth']]);
        self::assertSame(200, $upd['status'], $upd['raw']);
        $tx = $this->as('POST', '/api/transactions', ['position_id' => $h['posB'], 'type' => 'deposit', 'executed_at' => '2026-02-01 10:00', 'amount' => '250']);
        self::assertSame(201, $tx['status'], $tx['raw']);
        $stmt = $this->db->pdo()->prepare('SELECT user_id, created_by_user_id FROM transactions WHERE id = ?');
        $stmt->execute([$tx['body']['id']]);
        self::assertSame([$this->userId, $this->adminId], array_map('intval', $stmt->fetch(\PDO::FETCH_NUM)));
        self::assertNotNull($this->call($this->userId, 'GET', "/api/transactions/{$tx['body']['id']}")['body']['created_by_name']);
        $activity = $this->call($this->userId, 'GET', '/api/shares/activity')['body'];
        self::assertSame(['POST', '/api/transactions'], [$activity[0]['method'], $activity[0]['path']]);
        self::assertSame(200, $this->as('GET', '/api/net-worth')['status']);
        self::assertSame('section_not_shared', $this->as('GET', '/api/tax-report/years')['body']['error']['code']);
        self::assertSame(403, $this->as('PUT', '/api/allocation/targets', ['classes' => []])['status']);

        // Domácnost partnera: jeho data + sdílená
        $house = $this->call($this->adminId, 'GET', '/api/household')['body'];
        self::assertCount(2, $house['members']);
        self::assertSame('6250.00', array_column($house['members'], null, 'user_id')[$this->userId]['value']);

        // Partner sdílení opustí / vlastník odvolá → 404
        self::assertSame(1, count($this->call($this->adminId, 'GET', '/api/shares')['body']['received']));
        self::assertSame(204, $this->call($this->userId, 'DELETE', "/api/shares/$id")['status']);
        self::assertSame(404, $this->as('GET', '/api/dashboard')['status']);
    }

    public function testWriteShareCannotTouchHiddenTransferLegOrBankLink(): void
    {
        $h = $this->holdings();
        $ins = $this->call($this->userId, 'POST', '/api/instruments', ['asset_class' => 'crypto', 'symbol' => 'XTSH', 'name' => 'Synthetic XTSH',
            'quote_currency' => 'CZK', 'price_source' => 'manual'])['body']['id'];
        $market = fn (int $pf) => $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'market', 'portfolio_id' => $pf,
            'account_id' => $h['account'], 'instrument_id' => $ins])['body']['id'];
        [$inA, $inB] = [$market($h['a']), $market($h['b'])];
        self::assertSame(201, $this->call($this->userId, 'POST', '/api/transactions', ['position_id' => $inA, 'type' => 'buy',
            'executed_at' => '2026-01-05 10:00', 'quantity' => '2', 'amount' => '200'])['status']);
        $transfer = $this->call($this->userId, 'POST', '/api/transfers', ['from_position_id' => $inA, 'to_position_id' => $inB,
            'quantity' => '1', 'executed_at' => '2026-01-06 10:00']);
        self::assertSame(201, $transfer['status'], $transfer['raw']);
        $legOut = array_values(array_filter($transfer['body']['transactions'], static fn (array $t) => $t['type'] === 'transfer_out'))[0]['id'];
        $this->db->pdo()->prepare('UPDATE positions SET bank_account = ? WHERE id = ?')->execute(['123456789/0100', $h['posA']]);

        $this->call($this->userId, 'POST', '/api/shares', ['email' => $this->adminEmail(), 'permission' => 'write',
            'scope' => 'portfolios', 'portfolio_ids' => [$h['a']]]);

        // Druhá noha převodu je v nesdíleném portfoliu B → 404, nic se nesmaže
        self::assertSame(404, $this->as('DELETE', "/api/transactions/$legOut")['status']);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = ? AND transfer_group_id IS NOT NULL');
        $stmt->execute([$this->userId]);
        self::assertSame(2, (int) $stmt->fetchColumn());

        // Číslo účtu pro bankovní avíza partner nevidí ani nepřepíše
        $pos = $this->as('GET', "/api/positions/{$h['posA']}")['body'];
        self::assertArrayNotHasKey('bank_account', $pos);
        self::assertArrayNotHasKey('bank_account', $this->as('GET', '/api/positions')['body'][0]);
        $upd = $this->as('PUT', "/api/positions/{$h['posA']}", ['portfolio_id' => $h['a'], 'account_id' => $h['account'], 'name' => 'Spořák A',
            'bank_account' => '999/0300']);
        self::assertSame(200, $upd['status'], $upd['raw']);
        self::assertSame('123456789/0100', $this->call($this->userId, 'GET', "/api/positions/{$h['posA']}")['body']['bank_account']);
    }

    public function testBearerTokenCannotUseOwnerContext(): void
    {
        $this->call($this->userId, 'POST', '/api/shares', ['email' => $this->adminEmail(), 'permission' => 'read']);
        $token = $this->container->get(ApiTokenService::class)->generate($this->adminId, 'share', 'read')['plaintext'];
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/dashboard', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Authorization', "Bearer $token")->withHeader('Accept', 'application/json')->withHeader('X-Owner', (string) $this->userId);
        self::assertSame(403, $this->app->handle($request)->getStatusCode());
        $this->db->pdo()->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$this->adminId]);
    }

    public function testInviteCreatesAccountAndActivatesShare(): void
    {
        self::assertSame(400, $this->call($this->userId, 'POST', '/api/shares', ['email' => 'nope'])['status']);
        // Deaktivovaný účet: ne pozvánka (přijetí by spadlo na unikátní e-mail), ale validační chyba
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$this->adminId]);
        $inactive = $this->call($this->userId, 'POST', '/api/shares', ['email' => $this->adminEmail()]);
        $this->db->pdo()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$this->adminId]);
        self::assertSame(400, $inactive['status'], $inactive['raw']);
        $r = $this->call($this->userId, 'POST', '/api/shares', ['email' => self::INVITED, 'permission' => 'read']);
        self::assertSame(201, $r['status'], $r['raw']);
        self::assertTrue($r['body']['invited']);
        [$code, $to, $vars] = $this->sent[0];
        self::assertSame(['share_invite', [self::INVITED]], [$code, $to]);
        $token = substr((string) $vars['link'], strrpos((string) $vars['link'], '/') + 1);

        $public = function (string $method, ?array $body = null) use ($token): array {
            $request = (new ServerRequestFactory())->createServerRequest($method, "http://localhost/api/public/invite/$token", ['REMOTE_ADDR' => '127.0.0.1'])
                ->withHeader('Accept', 'application/json');
            if ($body !== null) {
                $request = $request->withHeader('Content-Type', 'application/json')
                    ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
            }
            $response = $this->app->handle($request);
            return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];
        };
        $info = $public('GET');
        self::assertSame([200, self::INVITED, false], [$info['status'], $info['body']['email'], $info['body']['account_exists']]);
        self::assertSame(400, $public('POST', ['name' => 'Partner', 'password' => 'x', 'password_confirm' => 'x'])['status']);
        $ok = $public('POST', ['name' => 'Partner', 'password' => 'Synthetic-Passw0rd-42!', 'password_confirm' => 'Synthetic-Passw0rd-42!']);
        self::assertSame(201, $ok['status'], json_encode($ok['body']) ?: '');
        self::assertSame(404, $public('GET')['status']);  // pozvánka použita

        $granted = $this->call($this->userId, 'GET', '/api/shares')['body']['granted'][0];
        self::assertFalse($granted['pending']);
        self::assertSame('Partner', $granted['grantee_name']);
    }
}
