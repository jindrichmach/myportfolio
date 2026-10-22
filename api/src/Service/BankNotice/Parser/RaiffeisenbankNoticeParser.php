<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\ParsedNotice;

/**
 * Raiffeisenbank (převzato z MyInvoice) — „Pohyb na účtě" (info@rb.cz), labely nad hodnotami:
 *   na účtě byla provedena následující příchozí platba:
 *   Datum a čas / 01. 06. 2026 10:15
 *   Na účet / 123456789/5500Firma …     Z účtu / 987654321/5500Plátce …
 *   Částka v měně účtu / +1.234,56 CZK
 *   Disponibilní zůstatek po pohybu / +99.999,99 CZK
 * Platba kartou: „Účet / …", „Detaily / …".
 */
final class RaiffeisenbankNoticeParser extends AbstractNoticeParser
{
    public function key(): string
    {
        return 'raiffeisenbank';
    }

    public function senderDomain(): string
    {
        return 'rb.cz';
    }

    protected function label(): string
    {
        return 'Raiffeisenbank';
    }

    public function supports(NoticeMessage $message): bool
    {
        if (!$this->senderMatches($message)) {
            return false;
        }
        $subject = mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8');
        if (!str_contains($subject, 'pohyb na ucte')) {
            return false;
        }
        $text = mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8');
        return str_contains($text, 'castka v mene uctu')
            && (str_contains($text, 'na ucet') || str_contains($text, 'z uctu') || $this->isCard($text));
    }

    public function parse(NoticeMessage $message): ParsedNotice
    {
        $text = $this->normalizeText($message->text);
        $folded = mb_strtolower($this->foldDiacritics($text), 'UTF-8');

        $postedAt = $this->required($text, '/Datum\s+a\s+čas\s*(?<value>\d{1,2}\.\s*\d{1,2}\.\s*\d{4})/iu', 'datum');
        $amount = $this->match($text, '/Částka\s+v\s+měně\s+účtu\s*(?<amount>[+\-]?[0-9 .]+,[0-9]{2})\s*(?<currency>[A-Z]{3})/iu')
            ?? throw new \RuntimeException('Raiffeisenbank parser nenašel částku a měnu.');
        $value = $this->parseAmount($amount['amount']);
        $balance = $this->optional($text, '/Disponibilní\s+zůstatek(?:\s+po\s+pohybu)?\s*(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu');
        $common = [
            'currency' => strtoupper($amount['currency']),
            'postedAt' => $this->parseDate(str_replace(' ', '', $postedAt)),
            'balance'  => $balance !== null ? $this->parseAmount($balance) : null,
        ];

        if ($this->isCard($folded)) {
            return new ParsedNotice(
                ...$common,
                // Karta = výdaj; příjem (vrácení) jen s výslovným „+"
                amount: $this->signed($value, !str_starts_with(trim($amount['amount']), '+')),
                account: $this->required($text, '/(?:^|\R)\s*Účet\s*(?<value>[0-9\-]+\/[0-9]{4})/iu', 'vlastní účet'),
                counterpartyName: $this->optional($text, '/Detaily\s*(?<value>.*?)Disponibilní\s+zůstatek/isu'),
                movementType: 'Platba kartou',
            );
        }

        $to = $this->match($text, '/Na\s+účet\s*(?<account>[0-9\-]+\/[0-9]{4})(?<name>.*?)(?=Částka\s+v\s+měně\s+účtu|Variabilní\s+symbol|Typ\s+pohybu|Z\s+účtu|Zpráva|Disponibilní)/isu');
        $from = $this->match($text, '/Z\s+účtu\s*(?<account>[0-9\-]+\/[0-9]{4})(?<name>.*?)(?=Částka\s+v\s+měně\s+účtu|Variabilní\s+symbol|Typ\s+pohybu|Na\s+účet|Zpráva|Disponibilní)/isu');
        $outgoing = preg_match('/nasledujici\s+(?<d>prichozi|odchozi)\s+platba/u', $folded, $m) === 1
            ? $m['d'] === 'odchozi'
            : str_starts_with($value, '-');
        $own = $outgoing ? $from : $to;
        $counterparty = $outgoing ? $to : $from;
        if ($own === null) {
            throw new \RuntimeException('Raiffeisenbank parser nenašel vlastní účet.');
        }

        return new ParsedNotice(
            ...$common,
            amount: $this->signed($value, $outgoing),
            account: $own['account'],
            counterpartyAccount: $counterparty['account'] ?? null,
            counterpartyName: isset($counterparty['name']) ? $this->cleanNullable($counterparty['name']) : null,
            message: $this->optional($text, '/Zpráva\s+pro\s+příjemce\s*(?<value>.*?)Disponibilní\s+zůstatek/isu'),
            movementType: $this->optional($text, '/(?:^|\R)\s*Typ\s+pohybu\s*\R\s*(?<value>[^\r\n]+)/u'),
        );
    }

    private function isCard(string $folded): bool
    {
        $folded = mb_strtolower($this->foldDiacritics($folded), 'UTF-8');
        return str_contains($folded, 'odchozi karetni transakce') && str_contains($folded, 'platba kartou');
    }
}
