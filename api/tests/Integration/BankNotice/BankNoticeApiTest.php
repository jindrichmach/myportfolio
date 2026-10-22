<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\BankNotice;

use MyPortfolio\Repository\ImapAccountRepository;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class BankNoticeApiTest extends ApiTestCase
{
    private const ACCOUNT = [
        'name' => 'Avíza', 'host' => '203.0.113.10', 'port' => 993, 'encryption' => 'ssl',
        'username' => 'notices@example.invalid', 'password' => 'synthetic-secret', 'folder' => 'INBOX',
    ];

    public function testImapAccountCrudNeverReturnsPassword(): void
    {
        $created = $this->call($this->userId, 'POST', '/api/settings/imap-accounts', self::ACCOUNT);
        self::assertSame(201, $created['status'], json_encode($created['body']) ?: '');
        self::assertArrayNotHasKey('password', $created['body']);
        self::assertArrayNotHasKey('password_enc', $created['body']);
        self::assertTrue($created['body']['require_email_auth']);
        $id = $created['body']['id'];

        $stored = $this->container->get(ImapAccountRepository::class)->withSecret($this->userId, $id)['password_enc'] ?? '';
        self::assertStringNotContainsString('synthetic-secret', $stored);
        self::assertSame('synthetic-secret', $this->container->get(SecretEncryption::class)->decrypt($stored));

        // Úprava bez hesla heslo zachová.
        $updated = $this->call($this->userId, 'PUT', "/api/settings/imap-accounts/$id", array_merge(self::ACCOUNT, ['password' => '', 'folder' => 'Banka', 'enabled' => false]));
        self::assertSame(200, $updated['status'], json_encode($updated['body']) ?: '');
        self::assertSame(['Banka', false], [$updated['body']['folder'], $updated['body']['enabled']]);
        self::assertSame($stored, $this->container->get(ImapAccountRepository::class)->withSecret($this->userId, $id)['password_enc'] ?? '');
        self::assertSame(409, $this->call($this->userId, 'POST', "/api/settings/imap-accounts/$id/scan")['status']);

        // Validace a izolace.
        // IMAP jen na veřejnou adresu (SSRF): loopback, metadata cloudu, privátní síť a nepřeložitelný host odmítnuty
        foreach (['127.0.0.1', '169.254.169.254', '10.0.0.5', 'imap.example.invalid'] as $host) {
            $denied = $this->call($this->userId, 'POST', '/api/settings/imap-accounts', array_merge(self::ACCOUNT, ['host' => $host]));
            self::assertSame(400, $denied['status'], $host);
            self::assertArrayHasKey('host', $denied['body']['error']['fields']);
        }
        $bad = $this->call($this->userId, 'POST', '/api/settings/imap-accounts', array_merge(self::ACCOUNT, ['password' => '', 'port' => 0]));
        self::assertSame(400, $bad['status']);
        self::assertArrayHasKey('password', $bad['body']['error']['fields']);
        self::assertArrayHasKey('port', $bad['body']['error']['fields']);
        self::assertSame([], $this->call($this->adminId, 'GET', '/api/settings/imap-accounts')['body']);
        self::assertSame(404, $this->call($this->adminId, 'PUT', "/api/settings/imap-accounts/$id", self::ACCOUNT)['status']);
        self::assertSame(404, $this->call($this->adminId, 'POST', "/api/settings/imap-accounts/$id/test")['status']);
        self::assertSame(404, $this->call($this->adminId, 'DELETE', "/api/settings/imap-accounts/$id")['status']);
        self::assertSame(204, $this->call($this->userId, 'DELETE', "/api/settings/imap-accounts/$id")['status']);
        self::assertSame([], $this->call($this->userId, 'GET', '/api/bank-notices')['body']);
    }

    public function testManualPositionBankLink(): void
    {
        $bank = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Link Bank', 'type' => 'bank']);
        $account = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $bank['body']['id'], 'name' => 'Savings']);
        $portfolio = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Link']);
        $base = ['portfolio_id' => $portfolio['body']['id'], 'account_id' => $account['body']['id'], 'name' => 'Spořák'];
        $pid = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'manual'] + $base)['body']['id'];

        $bad = $this->call($this->userId, 'PUT', "/api/positions/$pid", $base + ['bank_account' => '12345']);
        self::assertSame(400, $bad['status']);
        self::assertArrayHasKey('bank_account', $bad['body']['error']['fields']);

        $ok = $this->call($this->userId, 'PUT', "/api/positions/$pid", $base + ['bank_account' => ' 1234560005/2010', 'bank_notices_from' => '2026-09-19']);
        self::assertSame(200, $ok['status'], json_encode($ok['body']) ?: '');
        self::assertSame(['1234560005/2010', '2026-09-19'], [$ok['body']['bank_account'], $ok['body']['bank_notices_from']]);

        // Bez data navazuje na poslední ruční ocenění.
        $this->call($this->userId, 'POST', "/api/positions/$pid/valuations", ['date' => '2026-03-31', 'value' => '1000']);
        self::assertSame('2026-04-01', $this->call($this->userId, 'PUT', "/api/positions/$pid", $base + ['bank_account' => '1234560005/2010'])['body']['bank_notices_from']);

        // Bez pole bank_account se napojení nemění; prázdné ho zruší.
        self::assertSame('1234560005/2010', $this->call($this->userId, 'PUT', "/api/positions/$pid", $base)['body']['bank_account']);
        $off = $this->call($this->userId, 'PUT', "/api/positions/$pid", $base + ['bank_account' => '']);
        self::assertSame([null, null], [$off['body']['bank_account'], $off['body']['bank_notices_from']]);
    }
}
