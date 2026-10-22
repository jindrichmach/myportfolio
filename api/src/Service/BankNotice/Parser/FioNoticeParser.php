<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice\Parser;

use MyPortfolio\Service\BankNotice\NoticeMessage;
use MyPortfolio\Service\BankNotice\ParsedNotice;

/**
 * Fio banka (převzato z MyInvoice) — dva formáty avíza, oba z domény fio.cz:
 *
 * A) „Fio banka - prijem/vydaj na konte", řádky `Pole: hodnota`:
 *      Příjem na kontě: 2100000016      (nebo „Výdaj na kontě:")
 *      Částka: 3 898,00
 *      Aktuální zůstatek: 168 459,32
 *      Protiúčet: 1234567890/0800
 *    Bez data (bere se z hlavičky Date) a měny (CZK, pokud za částkou není kód).
 *
 * B) Prozaické avízo:
 *      zůstatek účtu 1111 se 24.08.2026 v 12:43 zvýšil o 10,00 CZK.
 *      Typ pohybu: Okamžitá příchozí platba
 *      Protistrana: 1234567890/0800 (ing. Novák)
 *      ID pokynu: 1111
 *      Aktuální zůstatek: 1,75 CZK
 *
 * Vlastní účet je v avízu bez kódu banky — doplní se /2010.
 */
final class FioNoticeParser extends AbstractNoticeParser
{
    private const BANK_CODE = '2010';

    private const PROSE_HEADER = '/z[ůu]statek\s+[úu][čc]tu\s*(?<account>[0-9\-]+(?:\/[0-9]{4})?)'
        . '\s+se\s+(?<date>\d{1,2}\.\s*\d{1,2}\.\s*\d{4})(?:\s+v\s+(?<time>\d{1,2}:\d{2}))?'
        . '\s+(?<direction>zv[ýy][šs]il|sn[íi][žz]il)\s+o\s*(?<amount>[+\-]?[0-9][0-9 .]*,[0-9]{2})'
        . '\s*(?<currency>[A-Za-z]{3}|Kč|Kc)?/u';

    public function key(): string
    {
        return 'fio';
    }

    public function senderDomain(): string
    {
        return 'fio.cz';
    }

    protected function label(): string
    {
        return 'Fio banka';
    }

    public function supports(NoticeMessage $message): bool
    {
        if (!$this->senderMatches($message)) {
            return false;
        }
        $text = $this->normalizeText($message->text);
        if (preg_match(self::PROSE_HEADER, $text) === 1) {
            return true;
        }
        $subject = $this->compact(mb_strtolower($message->subject, 'UTF-8'));
        if (!str_contains($subject, 'fio banka') || preg_match('/(?:prijem|vydaj|příjem|výdaj)\s+na\s+kont[ěe]/u', $subject) !== 1) {
            return false;
        }
        $text = $this->compact(mb_strtolower($text, 'UTF-8'));
        return preg_match('/(?:příjem|výdaj|prijem|vydaj)\s+na\s+kont[ěe]\s*:/u', $text) === 1
            && (str_contains($text, 'částka') || str_contains($text, 'castka'));
    }

    public function parse(NoticeMessage $message): ParsedNotice
    {
        $text = $this->normalizeText($message->text);
        if (preg_match(self::PROSE_HEADER, $text) === 1) {
            return $this->parseProse($text, $message);
        }

        $header = $this->match($text, '/(?:^|\R)\s*(?<direction>Příjem|Prijem|Výdaj|Vydaj)\s+na\s+kont[ěe]\s*:\s*(?<account>[0-9\-]+(?:\/[0-9]{4})?)/iu')
            ?? throw new \RuntimeException('Fio banka parser nenašel cílový účet.');
        $amount = $this->match($text, '/(?:^|\R)\s*Částka\s*:\s*(?<amount>[+\-]?[0-9][0-9 .]*,[0-9]{2})(?:\s*(?<currency>[A-Za-z]{3}|Kč))?/u')
            ?? throw new \RuntimeException('Fio banka parser nenašel částku.');
        if ($message->date === null) {
            throw new \RuntimeException('Fio banka parser nenašel datum e-mailu.');
        }
        $balance = $this->optional($this->foldDiacritics($text), '/(?:^|\R)\s*Aktualni\s+zustatek\s*:\s*(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu');
        $outgoing = preg_match('/^v[ýy]daj$/iu', $header['direction']) === 1;

        return new ParsedNotice(
            amount: $this->signed($this->parseAmount($amount['amount']), $outgoing),
            currency: $this->normalizeCurrency($amount['currency'] ?? ''),
            postedAt: $message->date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d'),
            account: $this->ownAccount($header['account']),
            balance: $balance !== null ? $this->parseAmount($balance) : null,
            counterpartyAccount: $this->optional($text, '/(?:^|\R)\s*Protiúčet\s*:\s*(?<value>[0-9\-]+(?:\/[0-9]{4})?)/u'),
            message: $this->optional($text, '/(?:^|\R)\s*(?:Zpráva\s+příjemci|US)\s*:\s*(?<value>[^\r\n]+)/u'),
        );
    }

    private function parseProse(string $text, NoticeMessage $message): ParsedNotice
    {
        $header = $this->match($text, self::PROSE_HEADER) ?? throw new \RuntimeException('Fio banka parser nenašel částku.');
        $balance = $this->optional($this->foldDiacritics($text), '/(?:^|\R)\s*Aktualni\s+zustatek\s*:\s*(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu');
        [$cpAccount, $cpName] = $this->counterparty($text);
        $outgoing = preg_match('/^sn[íi][žz]il$/u', $header['direction']) === 1;

        return new ParsedNotice(
            amount: $this->signed($this->parseAmount($header['amount']), $outgoing),
            currency: $this->normalizeCurrency($header['currency'] ?? ''),
            postedAt: $this->parseDate($header['date']),
            account: $this->ownAccount($header['account']),
            balance: $balance !== null ? $this->parseAmount($balance) : null,
            counterpartyAccount: $cpAccount,
            counterpartyName: $cpName,
            message: $this->optional($text, '/(?:^|\R)\s*Zpr[áa]va\s+pro\s+p[řr][íi]jemce\s*:\s*(?<value>[^\r\n]+)/u')
                ?? $this->optional($text, '/(?:^|\R)\s*Zpr[áa]va\s+pro\s+m[ěe]\s*:\s*(?<value>[^\r\n]+)/u'),
            movementType: $this->optional($text, '/(?:^|\R)\s*Typ\s+pohybu\s*:\s*(?<value>[^\r\n]+)/u'),
            bankRef: $this->optional($text, '/(?:^|\R)\s*ID\s+pokynu\s*:\s*(?<value>[^\r\n]+)/u'),
        );
    }

    /**
     * „Protistrana: 1234567890/0800 (ing. Novák)" → [účet, název]; účet může být maskovaný
     * nebo chybět (zahraniční protistrana jen s názvem).
     *
     * @return array{0:?string,1:?string}
     */
    private function counterparty(string $text): array
    {
        $value = $this->optional($text, '/(?:^|\R)\s*Protistrana\s*:\s*(?<value>[^\r\n]+)/u');
        if ($value === null) {
            return [null, null];
        }
        $name = null;
        if (preg_match('/\s*\((?<name>[^)]*)\)\s*$/u', $value, $m) === 1) {
            $name = $this->cleanNullable($m['name']);
            $value = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $value));
        }
        return preg_match('/^[0-9\-]+\/[0-9]{1,4}$/', $value) === 1 ? [$value, $name] : [null, $name ?? $this->cleanNullable($value)];
    }

    private function ownAccount(string $account): string
    {
        return str_contains($account, '/') ? $account : $account . '/' . self::BANK_CODE;
    }
}
