<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\BankNotice;

use MyPortfolio\Repository\BankNoticeRepository;
use MyPortfolio\Repository\ImapAccountRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\NoticeScanner;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Zpracování syntetických avíz Fio do ruční pozice (bez skutečné IMAP schránky). */
#[Group('integration')]
final class NoticeScannerTest extends ApiTestCase
{
    private const AUTH_OK = ['mx.example.invalid; dkim=pass header.d=fio.cz; dmarc=pass header.from=fio.cz'];

    private static function fio(string $date, string $direction, string $amount, string $balance, ?string $counterparty, string $type, string $account = '1234560005', array $auth = self::AUTH_OK): NoticeMessage
    {
        $cp = $counterparty !== null ? "Protistrana: $counterparty (Demo)\n" : '';
        $body = "zůstatek účtu $account se $date v 10:00 $direction o $amount CZK.\nTyp pohybu: $type\n{$cp}ID pokynu: "
            . md5($date . $amount . $account) . "\nAktuální zůstatek: $balance CZK";
        return new NoticeMessage(null, '<' . md5($body) . '@fio.example.invalid>', new \DateTimeImmutable(date('Y-m-d', strtotime($date)) . ' 10:00'),
            'Fio banka <automat@fio.cz>', 'Fio banka - zmena zustatku', $body, $auth);
    }

    /** @return array{position:int,settings:array<string,mixed>} */
    private function linkedPosition(): array
    {
        $bank = $this->call($this->userId, 'POST', '/api/institutions', ['name' => 'Synthetic Notice Bank', 'type' => 'bank']);
        $account = $this->call($this->userId, 'POST', '/api/accounts', ['institution_id' => $bank['body']['id'], 'name' => 'Savings']);
        $portfolio = $this->call($this->userId, 'POST', '/api/portfolios', ['name' => 'Notices']);
        $position = $this->call($this->userId, 'POST', '/api/positions', [
            'valuation_mode' => 'manual', 'portfolio_id' => $portfolio['body']['id'], 'account_id' => $account['body']['id'], 'name' => 'Spořák',
        ]);
        self::assertSame(201, $position['status'], json_encode($position['body']) ?: '');
        $id = $position['body']['id'];
        $this->container->get(PositionRepository::class)->setBankLink($this->userId, $id, '1234560005/2010', '2026-01-01');

        $imap = $this->container->get(ImapAccountRepository::class);
        $imapId = $imap->create($this->userId, [
            'name' => 'Test', 'enabled' => true, 'host' => 'imap.example.invalid', 'port' => 993, 'encryption' => 'ssl',
            'validate_cert' => true, 'username' => 'notices@example.invalid', 'password_enc' => 'unused', 'folder' => 'INBOX',
            'max_messages_per_run' => 50, 'require_email_auth' => true, 'auth_serv_id' => null,
        ]);
        return ['position' => $id, 'settings' => $imap->withSecret($this->userId, $imapId) ?? []];
    }

    public function testAmbiguousMaskedAccountIsNotGuessed(): void
    {
        ['position' => $pid, 'settings' => $settings] = $this->linkedPosition();
        // Dvě pozice s účty se stejnými okraji (238…891); MONETA posílá jen masku 238***891
        $repo = $this->container->get(PositionRepository::class);
        $repo->setBankLink($this->userId, $pid, '238456891/0600', '2026-01-01');
        $position = $this->call($this->userId, 'GET', "/api/positions/$pid")['body'];
        $second = $this->call($this->userId, 'POST', '/api/positions', ['valuation_mode' => 'manual',
            'portfolio_id' => $position['portfolio_id'], 'account_id' => $position['account_id'], 'name' => 'Druhý spořák'])['body']['id'];
        $repo->setBankLink($this->userId, $second, '238999891/0600', '2026-01-01');

        $masked = new NoticeMessage(null, '<masked@moneta.example.invalid>', new \DateTimeImmutable('2026-09-10 10:00'), 'infoservis@moneta.cz', 'Přišly peníze',
            "Účet:\n238***891\nDatum:\n10.09.2026\nČástka:\n100,00 Kč\n", ['mx.example.invalid; dmarc=pass header.from=moneta.cz']);
        $r = $this->container->get(NoticeScanner::class)->process($this->userId, $settings, [$masked]);
        self::assertSame(0, $r['imported']);
        self::assertSame('unmapped', $this->container->get(BankNoticeRepository::class)->list($this->userId)[0]['status']);
    }

    public function testNoticesBecomeFlowsAndValuationsIdempotently(): void
    {
        ['position' => $pid, 'settings' => $settings] = $this->linkedPosition();
        $messages = [
            self::fio('30.09.2026', 'snížil', '200,00', '1 005,00', '7654320005/0800', 'Odchozí platba'),
            self::fio('01.09.2026', 'zvýšil', '1 000,00', '1 000,00', '7654320005/0800', 'Příchozí platba'),
            self::fio('15.09.2026', 'zvýšil', '205,00', '1 205,00', null, 'Připsaný úrok'),
            self::fio('20.09.2026', 'zvýšil', '300,00', '1 505,00', null, 'Příchozí platba Eurokonto'),             // „urok" uvnitř slova ≠ úrok
            self::fio('01.12.2025', 'zvýšil', '50,00', '50,00', '7654320005/0800', 'Příchozí platba'),                 // před napojením
            self::fio('02.09.2026', 'zvýšil', '1,00', '1,00', '7654320005/0800', 'Příchozí platba', '9999990005'),      // cizí účet
            self::fio('03.09.2026', 'zvýšil', '9,00', '9,00', '7654320005/0800', 'Příchozí platba', '1234560005', []), // bez DKIM/DMARC
            new NoticeMessage(null, '<newsletter@example.invalid>', new \DateTimeImmutable('2026-09-05'), 'news@example.invalid', 'Novinky', 'Nic.'),
        ];
        $scanner = $this->container->get(NoticeScanner::class);

        $first = $scanner->process($this->userId, $settings, $messages);
        self::assertSame(['fetched' => 8, 'imported' => 4, 'known' => 0, 'ignored' => 1, 'failed' => 3], $first);

        $tx = $this->call($this->userId, 'GET', "/api/transactions?position_id=$pid")['body']['data'];
        $byType = array_column($tx, 'amount', 'type');
        ksort($byType);
        self::assertSame(['deposit' => '1000', 'interest' => '205', 'withdrawal' => '200'], $byType);
        self::assertSame(1, count(array_filter($tx, static fn (array $t) => $t['type'] === 'interest')));
        self::assertSame(['bank_notice'], array_values(array_unique(array_column($tx, 'source'))));

        $valuation = $this->call($this->userId, 'GET', "/api/positions/$pid/valuation")['body'];
        self::assertSame('1005.00', $valuation['value']);   // poslední zůstatek z avíza
        self::assertSame('1100.00', $valuation['invested']); // 1000 + 300 − 200; úrok je výnos
        self::assertSame('205.00', $valuation['income']);

        $log = array_count_values(array_column($this->container->get(BankNoticeRepository::class)->list($this->userId), 'status'));
        ksort($log);
        self::assertSame(['imported' => 4, 'security_rejected' => 1, 'skipped_old' => 1, 'unmapped' => 1], $log);

        $second = $scanner->process($this->userId, $settings, $messages);
        self::assertSame(['fetched' => 8, 'imported' => 0, 'known' => 7, 'ignored' => 1, 'failed' => 0], $second);
        self::assertCount(4, $this->call($this->userId, 'GET', "/api/transactions?position_id=$pid")['body']['data']);
        self::assertSame([], $this->container->get(BankNoticeRepository::class)->list($this->adminId));
    }
}
