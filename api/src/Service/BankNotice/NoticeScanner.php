<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\BankNoticeRepository;
use MyPortfolio\Repository\ImapAccountRepository;
use MyPortfolio\Repository\ImportBatchRepository;
use MyPortfolio\Repository\ManualValuationRepository;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\BankNotice\Parser\AirBankNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\CeskaSporitelnaNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\CreditasNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\CsobNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\FioNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\MonetaNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\NoticeParserInterface;
use MyPortfolio\Service\BankNotice\Parser\RaiffeisenbankNoticeParser;
use MyPortfolio\Service\BankNotice\Parser\UnicreditNoticeParser;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Portfolio\TransactionService;

/**
 * Načte avíza ze schránek uživatele a zapíše je do ručních pozic:
 * pohyb → vklad / výběr / úrok / poplatek, zůstatek po pohybu → ruční ocenění k datu zaúčtování.
 * Každá rozpoznaná zpráva se zapíše do protokolu (deduplikace); e-maily, které nejsou avízem
 * žádné podporované banky, se jen přeskočí.
 */
final class NoticeScanner
{
    public const SOURCE = 'bank_notice';

    /** @var list<NoticeParserInterface> */
    private readonly array $parsers;

    public function __construct(
        private readonly Connection $db,
        private readonly ImapAccountRepository $accounts,
        private readonly BankNoticeRepository $notices,
        private readonly PositionRepository $positions,
        private readonly TransactionRepository $transactions,
        private readonly TransactionService $transactionService,
        private readonly ManualValuationRepository $valuations,
        private readonly SnapshotRepository $snapshots,
        private readonly ImportBatchRepository $batches,
        private readonly SecretEncryption $secrets,
        private readonly ImapClientInterface $imap,
        private readonly EmailAuthenticationVerifier $auth,
    ) {
        $this->parsers = [
            new FioNoticeParser(), new AirBankNoticeParser(), new MonetaNoticeParser(), new RaiffeisenbankNoticeParser(),
            new CsobNoticeParser(), new CeskaSporitelnaNoticeParser(), new CreditasNoticeParser(), new UnicreditNoticeParser(),
        ];
    }

    /**
     * @return list<array<string,mixed>> souhrn per schránka
     */
    public function scanUser(int $userId, ?int $imapAccountId = null): array
    {
        $out = [];
        foreach ($this->accounts->withSecretsEnabled($userId) as $settings) {
            if ($imapAccountId === null || $settings['id'] === $imapAccountId) {
                $out[] = $this->scanAccount($userId, $settings);
            }
        }
        return $out;
    }

    /**
     * Zpracuje už stažené zprávy jedné schránky (scanAccount + testy).
     *
     * @param array<string,mixed> $settings řádek imap_accounts
     * @param list<NoticeMessage> $messages
     * @return array<string,int>
     */
    public function process(int $userId, array $settings, array $messages): array
    {
        // Od nejstarší — u více avíz za den zůstane ocenění z posledního.
        usort($messages, static fn (NoticeMessage $a, NoticeMessage $b) => [$a->date, $a->uid] <=> [$b->date, $b->uid]);
        $counts = ['fetched' => count($messages), 'imported' => 0, 'known' => 0, 'ignored' => 0, 'failed' => 0];
        $batch = ['id' => null, 'imported' => 0];
        $linked = $this->positions->withBankAccount($userId);
        foreach ($messages as $message) {
            $status = $this->processMessage($userId, $settings, $message, $linked, $batch);
            match ($status) {
                'imported'      => $counts['imported']++,
                'known'         => $counts['known']++,
                'ignored'       => $counts['ignored']++,
                default         => $counts['failed']++,
            };
        }
        if ($batch['id'] !== null) {
            $batch['imported'] > 0
                ? $this->batches->finish($userId, $batch['id'], $batch['imported'], $batch['imported'], 0)
                : $this->batches->delete($userId, $batch['id']);
        }
        return $counts;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function scanAccount(int $userId, array $settings): array
    {
        $summary = ['imap_account_id' => $settings['id'], 'name' => $settings['name']];
        try {
            $settings['password'] = $this->secrets->decrypt((string) $settings['password_enc']);
            $messages = $this->imap->latest($settings, (int) $settings['max_messages_per_run']);
            $summary += $this->process($userId, $settings, $messages);
            $this->accounts->updateScanStatus($userId, $settings['id'], 'ok', sprintf(
                'Načteno %d, zapsáno %d, chyb %d.', $summary['fetched'], $summary['imported'], $summary['failed'],
            ));
        } catch (\Throwable $e) {
            $summary['error'] = $e->getMessage();
            $this->accounts->updateScanStatus($userId, $settings['id'], 'error', $e->getMessage());
        }
        return $summary;
    }

    /**
     * @param array<string,mixed> $settings
     * @param list<array<string,mixed>> $linked ruční pozice s číslem účtu
     * @param array{id:?int,imported:int} $batch
     * @return string imported | known | ignored | skipped_old | unmapped | parse_failed | security_rejected | import_failed
     */
    private function processMessage(int $userId, array $settings, NoticeMessage $message, array $linked, array &$batch): string
    {
        $hash = $message->fallbackHash();
        if ($this->notices->isKnown($userId, $settings['id'], $message->messageId, $hash)) {
            return 'known';
        }
        $parser = null;
        foreach ($this->parsers as $candidate) {
            if ($candidate->supports($message)) {
                $parser = $candidate;
                break;
            }
        }
        if ($parser === null) {
            return 'ignored';
        }

        $base = [
            'imap_account_id' => $settings['id'],
            'message_id'      => $message->messageId,
            'fallback_hash'   => $hash,
            'message_date'    => $message->date?->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
            'sender'          => $message->sender,
            'subject'         => $message->subject,
            'parser'          => $parser->key(),
        ];

        if ($settings['require_email_auth']) {
            $verdict = $this->auth->verify($message->authResults, $parser->senderDomain(), $settings['auth_serv_id'] ?: null);
            if (!$verdict['pass']) {
                return $this->fail($userId, $base, 'security_rejected', 'E-mail neprošel ověřením DKIM/DMARC: ' . $verdict['detail']);
            }
        }

        try {
            $notice = $parser->parse($message);
        } catch (\RuntimeException $e) {
            return $this->fail($userId, $base, 'parse_failed', $e->getMessage());
        }
        $base['payload'] = $notice->toArray();

        $matches = array_values(array_filter($linked, static fn (array $c) => $c['currency'] === $notice->currency
            && AccountNumber::matches($notice->account, (string) $c['bank_account'])));
        if ($matches === []) {
            return $this->fail($userId, $base, 'unmapped', "Účet {$notice->account} ({$notice->currency}) není napojený na žádnou ruční pozici.");
        }
        // Maskované číslo (238***891) může sedět na víc účtů se stejnými okraji — pak nehádat, ať se nezapíše jinam
        if (count($matches) > 1) {
            return $this->fail($userId, $base, 'unmapped', "Maskované číslo účtu {$notice->account} odpovídá víc napojeným pozicím — nejde jednoznačně přiřadit.");
        }
        $position = $matches[0];
        $base['position_id'] = $position['id'];
        if ($position['bank_notices_from'] !== null && $notice->postedAt < $position['bank_notices_from']) {
            return $this->fail($userId, $base, 'skipped_old', "Avízo z {$notice->postedAt} je před začátkem napojení ({$position['bank_notices_from']}).");
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if ($batch['id'] === null) {
                $batch['id'] = $this->batches->create($userId, self::SOURCE, (string) $settings['name']);
            }
            $externalId = $parser->key() . ':' . ($notice->bankRef ?? $message->messageId ?? $hash);
            $inserted = $this->transactionService->import($userId, $position['id'], [[
                'external_id'    => $externalId,
                'type'           => $this->type($notice),
                'executed_at'    => $notice->postedAt,
                'amount'         => ltrim($notice->amount, '-'),
                'price_currency' => $notice->currency,
                'note'           => $this->note($notice),
            ]], self::SOURCE, $batch['id']);
            $batch['imported'] += $inserted;
            // Hotovost = součet toků (zůstatek nastavuje CashBalanceService); ocenění z výpisu by ji počítalo dvakrát
            if ($notice->balance !== null && empty($position['is_cash'])) {
                $this->valuations->upsert($userId, $position['id'], $notice->postedAt, $notice->balance, $notice->currency, 'Bankovní avízo');
                $this->snapshots->markDirty($userId, $position['id'], $notice->postedAt);
            }
            $this->notices->record($userId, $base + [
                'status'         => 'imported',
                'transaction_id' => $this->transactions->idByExternalId($userId, self::SOURCE, $externalId),
            ]);
            $pdo->commit();
            return 'imported';
        } catch (DomainError $e) {
            $pdo->rollBack();
            return $this->fail($userId, $base, 'import_failed', $e->getMessage());
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Úrok od banky (bez protiúčtu, typ pohybu se slovem „úrok…") → interest, daň z úroku → fee; jinak vklad / výběr.
     * Jen typ pohybu a jen celé slovo — „Eurokonto" v předmětu ani text od protistrany úrok nedělá.
     */
    private function type(ParsedNotice $notice): string
    {
        $movement = mb_strtolower(strtr((string) $notice->movementType, ['ú' => 'u', 'Ú' => 'u', 'ů' => 'u']), 'UTF-8');
        if ($notice->counterpartyAccount === null && preg_match('/\burok/u', $movement) === 1) {
            return $notice->isIncoming() ? 'interest' : 'fee';
        }
        return $notice->isIncoming() ? 'deposit' : 'withdrawal';
    }

    private function note(ParsedNotice $notice): string
    {
        $parts = array_filter([$notice->movementType, $notice->counterpartyName ?? $notice->counterpartyAccount, $notice->message]);
        return mb_substr('Avízo' . ($parts !== [] ? ': ' . implode(' · ', $parts) : ''), 0, 500);
    }

    /** @param array<string,mixed> $base */
    private function fail(int $userId, array $base, string $status, string $error): string
    {
        $this->notices->record($userId, $base + ['status' => $status, 'error' => $error]);
        return $status;
    }
}
