<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\ParsedNotice;
use MyPortfolio\Service\BankNotice\SenderDomain;

/**
 * UniCredit Bank (převzato z MyInvoice) — „Informace o pohybu na účtu":
 *   na Vašem účtu č. 123456789 (CZK) … proběhla transakce s následujícími údaji:
 *   Částka: 20,546.00 CZK          (záporná = odchozí)
 *   Číslo účtu protistrany: 0-987654321/0600
 *   Název účtu protistrany: …
 *   Detaily transakce: …
 *   Datum: 25.05.2026 13:02:31
 * Vlastní účet je bez kódu banky → /2700. Zůstatek avízo neuvádí.
 */
final class UnicreditNoticeParser extends AbstractNoticeParser
{
    private const BANK_CODE = '2700';

    public function key(): string
    {
        return 'unicredit';
    }

    public function senderDomain(): string
    {
        return 'unicreditgroup.cz';
    }

    protected function label(): string
    {
        return 'UniCredit Bank';
    }

    protected function senderMatches(NoticeMessage $message): bool
    {
        return SenderDomain::matches($message->sender, 'unicreditgroup.cz', 'unicredit.eu');
    }

    public function supports(NoticeMessage $message): bool
    {
        if (!$this->senderMatches($message)) {
            return false;
        }
        $subject = $this->compact(mb_strtolower($this->foldDiacritics(str_replace('_', ' ', $message->subject)), 'UTF-8'));
        if (!str_contains($subject, 'informace o pohybu na uctu')) {
            return false;
        }
        $text = $this->compact(mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8'));
        return str_contains($text, 'castka:') && str_contains($text, 'unicredit bank');
    }

    public function parse(NoticeMessage $message): ParsedNotice
    {
        $text = $this->normalizeText($message->text);

        $account = $this->required($text, '/na\s+Va[šs]em\s+[úu][čc]tu\s+[čc]\.\s*(?<value>[0-9\-]+(?:\/[0-9]{4})?)/iu', 'cílový účet');
        $amount = $this->match($text, '/[ČC]ástka:\s*(?<amount>[+\-]?[0-9,. ]+)\s*(?<currency>[A-Z]{3})/u')
            ?? throw new \RuntimeException('UniCredit Bank parser nenašel částku a měnu.');
        $postedAt = $this->required($text, '/Datum:\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4})/u', 'datum');

        return new ParsedNotice(
            amount: $this->parseAmount($amount['amount']),
            currency: strtoupper($amount['currency']),
            postedAt: $this->parseDate($postedAt),
            account: str_contains($account, '/') ? $account : $account . '/' . self::BANK_CODE,
            counterpartyAccount: $this->optional($text, '/[ČC]íslo\s+[úu][čc]tu\s+protistrany:\s*(?<value>[0-9\-]+\/[0-9]{4})/iu'),
            counterpartyName: $this->optional($text, '/N[áa]zev\s+[úu][čc]tu\s+protistrany:\s*(?<value>[^\r\n]*?)\s*(?:\R|Variabiln[íi]\s+symbol:)/isu'),
            message: $this->optional($text, '/Detaily\s+transakce:\s*(?<value>.*?)\s*Datum:/isu'),
        );
    }
}
