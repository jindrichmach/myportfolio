<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\ParsedNotice;

/**
 * ČSOB (převzato z MyInvoice) — „Moje info - Avízo" (noreply@csob.cz), labely na samostatných řádcích:
 *   Účet / 123456789/0300, Účet protistrany / …, Název protistrany / …, Datum účtování / 31.5.2026,
 *   Částka / +10 000,00 CZK (znaménko = směr),
 *   Zůstatek na účtu po zaúčtování transakce: +10 000,00 CZK.
 */
final class CsobNoticeParser extends AbstractNoticeParser
{
    public function key(): string
    {
        return 'csob';
    }

    public function senderDomain(): string
    {
        return 'csob.cz';
    }

    protected function label(): string
    {
        return 'ČSOB';
    }

    public function supports(NoticeMessage $message): bool
    {
        if (!$this->senderMatches($message)) {
            return false;
        }
        $subject = $this->compact(mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8'));
        if (!str_contains($subject, 'moje info') || !str_contains($subject, 'avizo')) {
            return false;
        }
        $text = $this->compact(mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8'));
        return str_contains($text, 'parametry platby') && str_contains($text, 'csob') && str_contains($text, 'castka');
    }

    public function parse(NoticeMessage $message): ParsedNotice
    {
        $text = $this->normalizeText($message->text);

        $account = $this->required($text, '/(?:^|\R)\s*Účet\s*\R\s*(?<value>[0-9\-]+\/[0-9]{4})/u', 'cílový účet');
        $postedAt = $this->required($text, '/(?:^|\R)\s*Datum\s+účtování\s*\R\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4})/u', 'datum účtování');
        $amount = $this->match($text, '/(?:^|\R)\s*Částka\s*\R\s*(?<amount>[+\-]?[0-9 ]+,[0-9]{2})\s*(?<currency>[A-Z]{3})/u')
            ?? throw new \RuntimeException('ČSOB parser nenašel částku a měnu.');
        $balance = $this->optional($text, '/Zůstatek\s+na\s+účtu\s+po\s+zaúčtování\s+transakce\s*:\s*(?<value>[+\-]?[0-9][0-9 ]*,[0-9]{2})/u');

        return new ParsedNotice(
            amount: $this->parseAmount($amount['amount']),
            currency: strtoupper($amount['currency']),
            postedAt: $this->parseDate($postedAt),
            account: $account,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
            counterpartyAccount: $this->optional($text, '/(?:^|\R)\s*Účet\s+protistrany\s*\R\s*(?<value>[0-9\-]+\/[0-9]{4})/u'),
            counterpartyName: $this->optional($text, '/(?:^|\R)\s*Název\s+protistrany\s*\R\s*(?<value>[^\r\n]+)/u'),
            movementType: $this->optional($text, '/transakce\s+typu\s*:\s*(?<value>[^\r\n.]+)/u'),
        );
    }
}
