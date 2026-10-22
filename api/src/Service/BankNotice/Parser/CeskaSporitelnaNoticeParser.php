<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\ParsedNotice;

/**
 * Česká spořitelna (v MyInvoice regex provider „ceska-sporitelna" + fallbacky Regex parseru):
 *   na účet NÁZEV 123456789/0800 právě dorazila platba ve výši 10,00 Kč.
 *   Na účtu je nově k dispozici 1 234,56 Kč (včetně kontokorentu …).
 *   Směr platby: příchozí | odchozí
 *   Číslo účtu: 123456789/0800            (novější „Odešla platba": Z účtu: / Na účet:)
 *   Číslo účtu protistrany: 2800000007/2010
 *   Částka v měně účtu: 10,00 Kč
 * Datum zaúčtování avízo neuvádí → datum doručení e-mailu.
 */
final class CeskaSporitelnaNoticeParser extends AbstractNoticeParser
{
    public function key(): string
    {
        return 'ceska_sporitelna';
    }

    public function senderDomain(): string
    {
        return 'csas.cz';
    }

    protected function label(): string
    {
        return 'Česká spořitelna';
    }

    public function supports(NoticeMessage $message): bool
    {
        if (!$this->senderMatches($message)) {
            return false;
        }
        $text = mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8');
        return str_contains($text, 'smer platby') && str_contains($text, 'castka v mene uctu');
    }

    public function parse(NoticeMessage $message): ParsedNotice
    {
        $folded = $this->foldDiacritics($this->normalizeText($message->text));

        $direction = $this->required($folded, '/Smer\s+platby:\s*(?<value>prichozi|odchozi)/iu', 'směr platby');
        $outgoing = strtolower($direction) === 'odchozi';
        $amount = $this->match($folded, '/Castka\s+v\s+mene\s+uctu:\s*(?<amount>[0-9 .]+,[0-9]{2})\s*(?<currency>Kc|CZK|EUR|USD|€)/iu')
            ?? throw new \RuntimeException('Česká spořitelna parser nenašel částku a měnu.');
        $from = $this->optional($folded, '/Z\s+uctu:\s*(?<value>\d[\d\-]*\/\d{4})/iu');
        $to = $this->optional($folded, '/Na\s+ucet:\s*(?<value>\d[\d\-]*\/\d{4})/iu');
        $account = $this->optional($folded, '/Cislo\s+uctu:\s*(?<value>[0-9\-]+\/[0-9]{4})/iu')
            ?? ($outgoing ? $from : $to)
            ?? $this->optional($folded, '/(?:z\s+uctu|na\s+ucet)\s+[^\n]{0,120}?(?<value>\d[\d\-]*\/\d{4})/iu')
            ?? throw new \RuntimeException('Česká spořitelna parser nenašel cílový účet.');
        if ($message->date === null) {
            throw new \RuntimeException('Česká spořitelna parser nenašel datum e-mailu.');
        }
        $balance = $this->optional($folded, '/k\s+dispozici\s+(?<value>[+\-]?\d[\d .]*,\d{2})\s*(?:Kc|CZK|EUR|USD|€)/iu');

        return new ParsedNotice(
            amount: $this->signed($this->parseAmount($amount['amount']), $outgoing),
            currency: $this->normalizeCurrency($amount['currency']),
            postedAt: $message->date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d'),
            account: $account,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
            counterpartyAccount: $this->optional($folded, '/Cislo\s+uctu\s+protistrany:\s*(?<value>[0-9\-]+\/[0-9]{4})/iu') ?? ($outgoing ? $to : $from),
        );
    }
}
