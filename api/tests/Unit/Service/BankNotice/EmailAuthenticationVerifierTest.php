<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\BankNotice;

use MyPortfolio\Service\BankNotice\EmailAuthenticationVerifier;
use PHPUnit\Framework\TestCase;

/** Důvěřuje se jen horní hlavičce Authentication-Results; authserv-id přesně; DKIM jen na doménu banky a její subdomény. */
final class EmailAuthenticationVerifierTest extends TestCase
{
    public function testOnlyTopHeaderWithExactAuthServId(): void
    {
        $v = new EmailAuthenticationVerifier();
        $headers = ['mx.seznam.cz; dmarc=fail header.from=fio.cz', 'mx.example.com.evil.invalid; dmarc=pass header.from=fio.cz'];
        // pin mx.example.com: horní hlavička má jiné authserv-id → odmítnuto (spodní podvržená se nehledá)
        self::assertSame('authserv_id_mismatch', $v->verify($headers, 'fio.cz', 'mx.example.com')['detail']);
        // bez pinu: horní hlavička říká dmarc=fail
        self::assertFalse($v->verify($headers, 'fio.cz')['pass']);
        // podřetězec authserv-id nestačí
        self::assertFalse($v->verify(['mx.example.com.evil.invalid; dmarc=pass'], 'fio.cz', 'mx.example.com')['pass']);
        // přesná shoda (i s verzí za id) projde
        self::assertTrue($v->verify(['MX.example.com 1; dmarc=pass header.from=fio.cz'], 'fio.cz', 'mx.example.com')['pass']);
    }

    public function testDkimAlignsOnlyDownwardsFromBankDomain(): void
    {
        $v = new EmailAuthenticationVerifier();
        self::assertTrue($v->verify(['mx; dkim=pass header.d=fio.cz'], 'fio.cz')['pass']);
        self::assertTrue($v->verify(['mx; dkim=pass header.d=mail.fio.cz'], 'fio.cz')['pass']);
        self::assertFalse($v->verify(['mx; dkim=pass header.d=cz'], 'fio.cz')['pass']);
        self::assertFalse($v->verify(['mx; dkim=pass header.d=evilfio.cz'], 'fio.cz')['pass']);
    }
}
