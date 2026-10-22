<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\BankNotice;

use MyPortfolio\Service\BankNotice\AccountNumber;
use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\Parser\AirBankNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\CeskaSporitelnaNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\CreditasNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\CsobNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\FioNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\MonetaNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\RaiffeisenbankNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\UnicreditNoticeParser;
use PHPUnit\Framework\TestCase;

/** Syntetická avíza (převzatá z testů MyInvoice, čísla účtů smyšlená). */
final class NoticeParsersTest extends TestCase
{
    public static function message(string $sender, string $subject, string $body, string $date = '2026-10-01 09:30:00'): NoticeMessage
    {
        return new NoticeMessage(1, '<' . md5($body) . '@example.invalid>', new \DateTimeImmutable($date), $sender, $subject, $body);
    }

    public function testFioLegacyIncomingAndOutgoing(): void
    {
        $parser = new FioNoticeParser();
        $in = self::message('automat@fio.cz', 'Fio banka - prijem na konte', <<<TEXT
Příjem na kontě: 1234560005
Částka: 3 898,00
VS: 26006439
Zpráva příjemci: test potvrzeni
Aktuální zůstatek: 168 459,32
Protiúčet: 7654320005/0800
TEXT, '2026-10-01 09:30:00');
        self::assertTrue($parser->supports($in));
        $n = $parser->parse($in);
        self::assertSame(['3898.00', 'CZK', '2026-10-01', '1234560005/2010', '168459.32', '7654320005/0800', 'test potvrzeni'],
            [$n->amount, $n->currency, $n->postedAt, $n->account, $n->balance, $n->counterpartyAccount, $n->message]);

        $out = $parser->parse(self::message('automat@fio.cz', 'Fio banka - vydaj na konte', <<<TEXT
Výdaj na kontě: 1234560005
Částka: 5 000,00
US: Najem
Aktuální zůstatek: 17 449,23
Protiúčet: 7654320005/3030
TEXT));
        self::assertSame(['-5000.00', '17449.23'], [$out->amount, $out->balance]);
        self::assertFalse($out->isIncoming());
    }

    public function testFioProseNotice(): void
    {
        $parser = new FioNoticeParser();
        $message = self::message('Fio banka <automat@fio.cz>', 'Fio banka - zmena zustatku', <<<TEXT
Dobrý den,

zůstatek účtu 2100000016 se 24.08.2026 v 12:43 zvýšil o 10,00 CZK.

Další parametry
Typ pohybu: Okamžitá příchozí platba
Protistrana: 7654320005/0800 (ing. Novák)
ID pokynu: 4711
Zpráva pro příjemce: Faktura 26006439

Aktuální zůstatek: 1 234,75 CZK
TEXT);
        self::assertTrue($parser->supports($message));
        $n = $parser->parse($message);
        self::assertSame(['10.00', '2026-08-24', '2100000016/2010', '1234.75', '7654320005/0800', 'ing. Novák', 'Okamžitá příchozí platba', '4711'],
            [$n->amount, $n->postedAt, $n->account, $n->balance, $n->counterpartyAccount, $n->counterpartyName, $n->movementType, $n->bankRef]);

        $out = $parser->parse(self::message('automat@fio.cz', 'Fio banka', <<<TEXT
zůstatek účtu 2100000016 se 1. 9. 2026 v 8:05 snížil o 5 000,00 CZK.
Typ pohybu: Odchozí platba
Protistrana: 7654320005/3030
Aktuální zůstatek: 17 449,23 CZK
TEXT));
        self::assertSame(['-5000.00', '2026-09-01'], [$out->amount, $out->postedAt]);
    }

    public function testAirBankIncomingAndOutgoing(): void
    {
        $parser = new AirBankNoticeParser();
        $in = self::message('Air Bank <info@airbank.cz>', 'Zvýšení zůstatku na účtu Spořicí účet', <<<TEXT
zůstatek na účtu Spořicí účet číslo 1000000005/3030 se zvýšil o částku 1,00 CZK. Dostupný zůstatek k 24.07.2026 v 15:20 je 2,00 CZK.

Příchozí úhrada z účtu Jan Demo číslo 2000000099/0300
Částka: 1,00 CZK
Datum zaúčtování: 24.07.2026
Kód transakce: 100000000001
TEXT);
        self::assertTrue($parser->supports($in));
        $n = $parser->parse($in);
        self::assertSame(['1.00', 'CZK', '2026-07-24', '1000000005/3030', '2.00', '2000000099/0300', 'Jan Demo', '100000000001'],
            [$n->amount, $n->currency, $n->postedAt, $n->account, $n->balance, $n->counterpartyAccount, $n->counterpartyName, $n->bankRef]);

        $out = $parser->parse(self::message('info@airbank.cz', 'Snížení zůstatku na účtu Spořicí účet', <<<TEXT
zůstatek na účtu Spořicí účet číslo 1000000005/3030 se snížil o částku 500,00 CZK. Dostupný zůstatek k 02.08.2026 v 09:00 je 9 500,00 CZK.

Odchozí úhrada na účet Demo číslo 19-2000000099/0800
Částka: 500,00 CZK
Datum zaúčtování: 02.08.2026
TEXT));
        self::assertSame(['-500.00', '9500.00', '19-2000000099/0800'], [$out->amount, $out->balance, $out->counterpartyAccount]);
    }

    public function testMonetaMaskedAccountAndBookedBalance(): void
    {
        $parser = new MonetaNoticeParser();
        $message = self::message('infoservis@moneta.cz', 'Přišly peníze', <<<TEXT
Přišly peníze

Účet:
238***891

Datum:
01.07.2026

Částka:
35 000,00 Kč

Popis:
OKAMŽITÁ ÚHRADA DEMO

Od:
100200300/0300

Disponibilní zůstatek:
64 800,00 Kč

Účetní zůstatek:
64 831,42 Kč
TEXT);
        self::assertTrue($parser->supports($message));
        $n = $parser->parse($message);
        self::assertSame(['35000.00', 'CZK', '2026-07-01', '238***891/0600', '64831.42', '100200300/0300', 'OKAMŽITÁ ÚHRADA DEMO'],
            [$n->amount, $n->currency, $n->postedAt, $n->account, $n->balance, $n->counterpartyAccount, $n->message]);
        self::assertTrue(AccountNumber::matches($n->account, '238456891/0600'));
    }

    public function testRaiffeisenbankIncomingAndOutgoing(): void
    {
        $parser = new RaiffeisenbankNoticeParser();
        $in = self::message('Raiffeisenbank <info@rb.cz>', 'Pohyb na účtě', <<<TEXT
Vážená klientko, vážený kliente,
na účtě byla provedena následující příchozí platba:
Datum a čas
01. 06. 2026 10:15
Na účet
123456789/5500Spořicí účet
Částka v měně účtu
+1.234,56 CZK
Z účtu
987654321/5500Plátce Demo
Zpráva pro příjemce
Spoření
Disponibilní zůstatek po pohybu
+99.999,99 CZK
TEXT);
        self::assertTrue($parser->supports($in));
        $n = $parser->parse($in);
        self::assertSame(['1234.56', 'CZK', '2026-06-01', '123456789/5500', '99999.99', '987654321/5500', 'Plátce Demo', 'Spoření'],
            [$n->amount, $n->currency, $n->postedAt, $n->account, $n->balance, $n->counterpartyAccount, $n->counterpartyName, $n->message]);

        $out = $parser->parse(self::message('info@rb.cz', 'Pohyb na účtě', <<<TEXT
na účtě byla provedena následující odchozí platba:
Datum a čas
02. 08. 2026 16:07
Z účtu
123456789/5500Spořicí účet
Částka v měně účtu
-1.234,56 CZK
Typ pohybu
Odchozí úhrada
Na účet
2000000018/0800Příjemce Demo
Disponibilní zůstatek po pohybu
+98.765,43 CZK
TEXT));
        self::assertSame(['-1234.56', '123456789/5500', '2000000018/0800', 'Odchozí úhrada', '98765.43'],
            [$out->amount, $out->account, $out->counterpartyAccount, $out->movementType, $out->balance]);
    }

    public function testCsobSignedAmountAndBalance(): void
    {
        $parser = new CsobNoticeParser();
        $message = self::message('ČSOB <noreply@csob.cz>', 'Moje info - Avízo', <<<TEXT
Dobrý den,
dne 31.5.2026 byla na účtu 123456789 zaúčtována transakce typu: Odchozí úhrada okamžitá.

Parametry platby
Účet
123456789/0300
Účet protistrany
35-1000000005/0100
Datum účtování
31.5.2026
Částka
-10 000,00 CZK
Zůstatek na účtu po zaúčtování transakce: +2 500,00 CZK.

Vaše ČSOB
TEXT);
        self::assertTrue($parser->supports($message));
        $n = $parser->parse($message);
        self::assertSame(['-10000.00', 'CZK', '2026-05-31', '123456789/0300', '2500.00', '35-1000000005/0100', 'Odchozí úhrada okamžitá'],
            [$n->amount, $n->currency, $n->postedAt, $n->account, $n->balance, $n->counterpartyAccount, $n->movementType]);
    }

    public function testCeskaSporitelnaIncomingAndOutgoing(): void
    {
        $parser = new CeskaSporitelnaNoticeParser();
        $in = self::message('Česká spořitelna <automat@csas.cz>', 'Avízo o pohybu na účtu', <<<TEXT
na účet SPOŘICÍ ÚČET 1234560005/0800 právě dorazila platba ve výši 10,00 Kč.

Na účtu je nově k dispozici 1 510,00 Kč (včetně kontokorentu 1 510,00 Kč).

Informace o transakci

Směr platby: příchozí
Číslo účtu: 1234560005/0800

Číslo účtu protistrany: 7654320005/2010

Částka v měně účtu: 10,00 Kč
TEXT, '2026-09-21 08:00:00');
        self::assertTrue($parser->supports($in));
        $n = $parser->parse($in);
        self::assertSame(['10.00', 'CZK', '2026-09-21', '1234560005/0800', '1510.00', '7654320005/2010'],
            [$n->amount, $n->currency, $n->postedAt, $n->account, $n->balance, $n->counterpartyAccount]);

        // Novější šablona „Odešla platba" bez řádku „Číslo účtu:".
        $out = $parser->parse(self::message('automat@csas.cz', 'Odešla platba', <<<TEXT
Informace o transakci
Směr platby: odchozí
Z účtu: 1234560005/0800
Na účet: 7654320005/2010
Částka v měně účtu: 1 234,50 Kč
TEXT));
        self::assertSame(['-1234.50', '1234560005/0800', '7654320005/2010', null], [$out->amount, $out->account, $out->counterpartyAccount, $out->balance]);
    }

    public function testCreditasIncoming(): void
    {
        $parser = new CreditasNoticeParser();
        $message = self::message('Banka CREDITAS <info@creditas.cz>', 'Notifikace o změně na účtu', <<<TEXT
zůstatek na účtu 1000000005/2250 se zvýšil o částku 23 000,00 CZK (Příchozí úhrada). Disponibilní zůstatek 23.06.2026 18:41 je 96 163,53 CZK.

Detail platby:
- změna na účtu: 1000000005/2250
- účet protistrany: 1900000007 - banka protistrany: 0800 - datum: 23.06.2026 18:41
- částka: 23 000,00 CZK
- disponibilní zůstatek: 96 163,53 CZK

Vaše Banka CREDITAS
TEXT);
        self::assertTrue($parser->supports($message));
        $n = $parser->parse($message);
        self::assertSame(['23000.00', 'CZK', '2026-06-23', '1000000005/2250', '96163.53', '1900000007/0800', 'Příchozí úhrada'],
            [$n->amount, $n->currency, $n->postedAt, $n->account, $n->balance, $n->counterpartyAccount, $n->movementType]);
    }

    public function testDirectionNotTakenFromCounterpartyText(): void
    {
        // MONETA: popis od protistrany „Odešly peníze…" nesmí otočit příchozí platbu
        $moneta = (new MonetaNoticeParser())->parse(self::message('infoservis@moneta.cz', 'Přišly peníze', <<<TEXT
Účet:
238456891
Datum:
01.07.2026
Částka:
35 000,00 Kč
Popis:
Odešly peníze zpět — vratka
TEXT));
        self::assertSame('35000.00', $moneta->amount);

        // CREDITAS: „snížil" jinde v textu nepřebije větu o zůstatku účtu
        $creditas = (new CreditasNoticeParser())->parse(self::message('info@creditas.cz', 'Notifikace o změně na účtu', <<<TEXT
zůstatek na účtu 1000000005/2250 se zvýšil o částku 500,00 CZK (Příchozí úhrada).
Detail platby:
- změna na účtu: 1000000005/2250
- datum: 23.06.2026
- částka: 500,00 CZK
Tip: pokud se Vám limit karty snížil, upravíte ho v aplikaci.
TEXT));
        self::assertSame('500.00', $creditas->amount);

        // Raiffeisenbank: karetní transakce bez znaménka je výdaj
        $card = (new RaiffeisenbankNoticeParser())->parse(self::message('info@rb.cz', 'Pohyb na účtě', <<<TEXT
Odchozí karetní transakce
Datum a čas
03. 08. 2026 12:00
Účet
123456789/5500
Částka v měně účtu
1.234,56 CZK
Typ: Platba kartou
Detaily
OBCHOD DEMO
Disponibilní zůstatek
1.000,00 CZK
TEXT));
        self::assertSame('-1234.56', $card->amount);
    }

    public function testUnicreditHtmlNotice(): void
    {
        $parser = new UnicreditNoticeParser();
        $message = self::message('UniCredit Bank <unicreditbank@unicreditgroup.cz>', 'Informace_o_pohybu_na_účtu', (new \MyPortfolio\Service\BankNotice\EmailNoticeTextNormalizer())->normalize(<<<HTML
<html>Dobrý den,<br/>
na Vašem účtu č. 123456789 (CZK) Spoření právě proběhla transakce s následujícími údaji:<br/>
<br/>
Částka: 20,546.00 CZK<br/>
Číslo účtu protistrany: 0-987654321/0600<br/>
Název účtu protistrany: PLÁTCE DEMO<br/>
Variabilní symbol: <br/>
Detaily transakce: Spoření<br/>
Datum: 25.05.2026 13:02:31<br/>
<br/>
Vaše UniCredit Bank<br/>
</html>
HTML));
        self::assertTrue($parser->supports($message));
        $n = $parser->parse($message);
        self::assertSame(['20546.00', 'CZK', '2026-05-25', '123456789/2700', null, '0-987654321/0600', 'PLÁTCE DEMO', 'Spoření'],
            [$n->amount, $n->currency, $n->postedAt, $n->account, $n->balance, $n->counterpartyAccount, $n->counterpartyName, $n->message]);
    }

    public function testSpoofedSenderDomainIsNotSupported(): void
    {
        $body = "Příjem na kontě: 1234560005\nČástka: 1,00\nAktuální zůstatek: 1,00";
        self::assertFalse((new FioNoticeParser())->supports(self::message('Fio <automat@fio.cz.evil.com>', 'Fio banka - prijem na konte', $body)));
        self::assertFalse((new MonetaNoticeParser())->supports(self::message('x@moneta.cz.example.invalid', 'Přišly peníze', "Účet: 1\nČástka: 1,00 Kč")));
        self::assertFalse((new CeskaSporitelnaNoticeParser())->supports(self::message('automat@csas.cz.example.invalid', 'Avízo', "Směr platby: příchozí\nČástka v měně účtu: 1,00 Kč")));
        self::assertFalse((new RaiffeisenbankNoticeParser())->supports(self::message('info@notrb.cz', 'Pohyb na účtě', "Částka v měně účtu\n+1,00 CZK\nNa účet\n1/5500")));
    }

    public function testAccountNumberMatching(): void
    {
        self::assertTrue(AccountNumber::matches('1234560005/2010', '1234560005/2010'));
        self::assertTrue(AccountNumber::matches('0001234560005/2010', '1234560005/2010'));
        self::assertFalse(AccountNumber::matches('1234560005/2010', '1234560005/0800'));
        self::assertTrue(AccountNumber::matches('238***891/0600', '238456891/0600'));
        self::assertFalse(AccountNumber::matches('238***891/0600', '239456891/0600'));
        self::assertFalse(AccountNumber::matches('238***891/0600', '2384567891/0600'));
        self::assertSame('19-2000000099/0800', AccountNumber::normalize(' 19-2000000099/0800 '));
        self::assertNull(AccountNumber::normalize(''));
        $this->expectException(\InvalidArgumentException::class);
        AccountNumber::normalize('123');
    }
}
