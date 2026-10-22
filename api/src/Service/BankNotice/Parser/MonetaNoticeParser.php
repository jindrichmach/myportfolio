<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\ParsedNotice;

/**
 * MONETA Money Bank (převzato z MyInvoice) — Info Servis „Přišly peníze" / „Odešly peníze"
 * (infoservis@moneta.cz). HTML šablona se zploští na labely:
 *   Účet: 238***891          (maskovaný — viz AccountNumber)
 *   Datum: 01.07.2026
 *   Částka: 35 000,00 Kč
 *   Popis: …
 *   Od: 100200300/0300
 *   Disponibilní zůstatek: 64 831,42 Kč
 *   Účetní zůstatek: 64 831,42 Kč
 * Kód banky vlastního účtu chybí → /0600.
 */
final class MonetaNoticeParser extends AbstractNoticeParser
{
    private const BANK_CODE = '0600';

    public function key(): string
    {
        return 'moneta';
    }

    public function senderDomain(): string
    {
        return 'moneta.cz';
    }

    protected function label(): string
    {
        return 'MONETA Money Bank';
    }

    public function supports(NoticeMessage $message): bool
    {
        if (!$this->senderMatches($message)) {
            return false;
        }
        $subject = $this->compact(mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8'));
        if (!str_contains($subject, 'prisly penize') && !str_contains($subject, 'odesly penize')) {
            return false;
        }
        $text = $this->compact(mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8'));
        return str_contains($text, 'ucet:') && str_contains($text, 'castka:');
    }

    public function parse(NoticeMessage $message): ParsedNotice
    {
        $text = $this->normalizeText($message->text);
        $folded = $this->foldDiacritics($text);

        $account = $this->required($folded, '/(?:^|\R)\s*Ucet:\s*(?<value>[0-9*\-]+(?:\/[0-9]{4})?)/iu', 'cílový účet');
        $postedAt = $this->required($folded, '/(?:^|\R)\s*Datum:\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4})/iu', 'datum');
        $amount = $this->match($folded, '/(?:^|\R)\s*Castka:\s*(?<amount>[+\-]?[0-9][0-9 ]*,[0-9]{2})\s*(?<currency>Kc|CZK|EUR|USD|€|[A-Z]{3})/iu')
            ?? throw new \RuntimeException('MONETA parser nenašel částku a měnu.');
        // Účetní zůstatek (bez blokací) je pro ocenění přesnější; starší šablona má jen disponibilní.
        $balance = $this->optional($folded, '/(?:^|\R)\s*Ucetni\s+zustatek:\s*(?<value>[+\-]?[0-9][0-9 ]*,[0-9]{2})/iu')
            ?? $this->optional($folded, '/(?:^|\R)\s*Disponibilni\s+zustatek:\s*(?<value>[+\-]?[0-9][0-9 ]*,[0-9]{2})/iu');
        // Směr jen z předmětu — popis platby píše protistrana a může obsahovat cokoli
        $outgoing = str_contains($this->compact(mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8')), 'odesly penize');

        return new ParsedNotice(
            amount: $this->signed($this->parseAmount($amount['amount']), $outgoing),
            currency: $this->normalizeCurrency($amount['currency']),
            postedAt: $this->parseDate($postedAt),
            account: str_contains($account, '/') ? $account : $account . '/' . self::BANK_CODE,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
            counterpartyAccount: $this->optional($text, '/(?:^|\R)\s*(?:Od|Komu):\s*(?<value>[0-9\-]+\/[0-9]{4})/iu'),
            message: $this->optional($text, '/(?:^|\R)\s*Popis:\s*(?<value>.+?)(?=\n\s*(?:Od|Komu):|\n\s*Disponibiln|\z)/isu'),
        );
    }
}
