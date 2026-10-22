<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\ParsedNotice;

/**
 * Air Bank (převzato z MyInvoice) — „Zvýšení/Snížení zůstatku na účtu …" (info@airbank.cz):
 *   zůstatek na účtu Spořicí účet číslo 1000000005/3030 se zvýšil o částku 1,00 CZK.
 *   Dostupný zůstatek k 24.07.2026 v 15:20 je 1,00 CZK.
 *   Příchozí úhrada z účtu Jméno číslo 2000000099/0300
 *   Částka: 1,00 CZK
 *   Datum zaúčtování: 24.07.2026
 *   Kód transakce: 100000000001
 * Směr nese předmět (Zvýšení = příchozí, Snížení = odchozí).
 */
final class AirBankNoticeParser extends AbstractNoticeParser
{
    public function key(): string
    {
        return 'airbank';
    }

    public function senderDomain(): string
    {
        return 'airbank.cz';
    }

    protected function label(): string
    {
        return 'Air Bank';
    }

    public function supports(NoticeMessage $message): bool
    {
        if (!$this->senderMatches($message)) {
            return false;
        }
        $subject = $this->compact(mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8'));
        if (!str_contains($subject, 'zvyseni zustatku') && !str_contains($subject, 'snizeni zustatku')) {
            return false;
        }
        $text = $this->compact(mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8'));
        return (str_contains($text, 'se zvysil o castku') || str_contains($text, 'se snizil o castku'))
            && (str_contains($text, 'castka:') || str_contains($text, 'cislo '));
    }

    public function parse(NoticeMessage $message): ParsedNotice
    {
        $text = $this->normalizeText($message->text);
        $folded = $this->foldDiacritics($text);

        $account = $this->required($folded, '/zustatek\s+na\s+uctu.*?cislo\s+(?<value>[0-9\-]+\/[0-9]{4})/iu', 'cílový účet');
        $amount = $this->match($folded, '/(?:^|\R)\s*Castka:\s*(?<amount>[+\-]?[0-9][0-9 .]*,[0-9]{2})\s*(?<currency>[A-Za-z]{3}|Kc|€)/iu')
            ?? $this->match($folded, '/se\s+(?:zvysil|snizil)\s+o\s+castku\s+(?<amount>[+\-]?[0-9][0-9 .]*,[0-9]{2})\s*(?<currency>[A-Za-z]{3}|Kc|€)/iu')
            ?? throw new \RuntimeException('Air Bank parser nenašel částku a měnu.');
        $postedAt = $this->optional($folded, '/Datum\s+zauctovani:\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4})/iu')
            ?? $message->date?->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y')
            ?? throw new \RuntimeException('Air Bank parser nenašel datum.');
        $balance = $this->optional($folded, '/Dostupny\s+zustatek.*?je\s+(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu');
        $counterparty = $this->match($folded, '/(?:Prichozi\s+uhrada\s+z\s+uctu|Odchozi\s+uhrada\s+na\s+ucet)\s+(?<name>.+?)\s+cislo\s+(?<account>[0-9\-]+\/[0-9]{4})/iu');
        $subject = mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8');

        return new ParsedNotice(
            amount: $this->signed($this->parseAmount($amount['amount']), str_contains($subject, 'snizeni zustatku')),
            currency: $this->normalizeCurrency($amount['currency']),
            postedAt: $this->parseDate($postedAt),
            account: $account,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
            counterpartyAccount: $counterparty['account'] ?? null,
            counterpartyName: isset($counterparty['name']) ? $this->cleanNullable($counterparty['name']) : null,
            bankRef: $this->optional($folded, '/Kod\s+transakce:\s*(?<value>[0-9]+)/iu'),
        );
    }
}
