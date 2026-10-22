<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\ParsedNotice;

/**
 * Banka CREDITAS (převzato z MyInvoice) — „Notifikace o změně na účtu" (info@creditas.cz):
 *   zůstatek na účtu 1000000005/2250 se zvýšil o částku 23 000,00 CZK (Příchozí úhrada).
 *   Disponibilní zůstatek 23.06.2026 18:41 je 96 163,53 CZK.
 *   - změna na účtu: 1000000005/2250
 *   - účet protistrany: 1900000007 - banka protistrany: 0800 - datum: 23.06.2026 18:41
 *   - částka: 23 000,00 CZK
 */
final class CreditasNoticeParser extends AbstractNoticeParser
{
    public function key(): string
    {
        return 'creditas';
    }

    public function senderDomain(): string
    {
        return 'creditas.cz';
    }

    protected function label(): string
    {
        return 'CREDITAS';
    }

    public function supports(NoticeMessage $message): bool
    {
        if (!$this->senderMatches($message)) {
            return false;
        }
        $subject = $this->compact(mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8'));
        if (!str_contains($subject, 'notifikace') || !str_contains($subject, 'zmene na uctu')) {
            return false;
        }
        $text = $this->compact(mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8'));
        return str_contains($text, 'zmena na uctu') && str_contains($text, 'castka')
            && (str_contains($text, 'snizil') || str_contains($text, 'zvysil'));
    }

    public function parse(NoticeMessage $message): ParsedNotice
    {
        $raw = $this->normalizeText($message->text);
        $text = $this->foldDiacritics($raw);

        $account = $this->required($text, '/zmena\s+na\s+uctu\s*:\s*(?<value>[0-9\-]+\/[0-9]{4})/iu', 'cílový účet');
        $amount = $this->match($text, '/(?:^|\R|-)\s*castka\s*:\s*(?<amount>[0-9][0-9 .]*,[0-9]{2})\s*(?<currency>[A-Za-z]{3})?/iu')
            ?? throw new \RuntimeException('CREDITAS parser nenašel částku.');
        // Směr z věty o zůstatku účtu („zůstatek na účtu … se zvýšil / snížil o částku"), ne z celého textu
        if (preg_match('/zustatek\s+na\s+uctu\s+[0-9\-]+\/[0-9]{4}\s+se\s+(?<d>snizil|zvysil)/iu', $text, $m) !== 1) {
            throw new \RuntimeException('CREDITAS parser nerozpoznal směr platby (snížil/zvýšil).');
        }
        $outgoing = strtolower($m['d']) === 'snizil';
        $postedAt = $this->optional($text, '/datum\s*:\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4})/iu')
            ?? $message->date?->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y')
            ?? throw new \RuntimeException('CREDITAS parser nenašel datum platby.');
        $cpAccount = $this->optional($text, '/ucet\s+protistrany\s*:\s*(?<value>[0-9\-]+(?:\/[0-9]{4})?)/iu');
        $cpBank = $this->optional($text, '/banka\s+protistrany\s*:\s*(?<value>[0-9]{4})/iu');
        if ($cpAccount !== null && !str_contains($cpAccount, '/') && $cpBank !== null) {
            $cpAccount .= '/' . $cpBank;
        }
        $balance = $this->optional($text, '/disponibilni\s+zustatek(?:\s+\d{1,2}\.\d{1,2}\.\d{4}(?:\s+\d{1,2}:\d{2})?)?\s*(?::|je)\s*(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu');

        return new ParsedNotice(
            amount: $this->signed($this->parseAmount($amount['amount']), $outgoing),
            currency: $this->normalizeCurrency($amount['currency'] ?? ''),
            postedAt: $this->parseDate($postedAt),
            account: $account,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
            counterpartyAccount: $cpAccount,
            movementType: $this->optional($raw, '/[0-9][0-9\x{00A0} .]*,[0-9]{2}\s*[A-Za-z]{3}\s*\((?<value>[^)]+)\)/u'),
        );
    }
}
