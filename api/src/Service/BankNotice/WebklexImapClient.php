<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;

/**
 * IMAP přes webklex/php-imap (převzato z MyInvoice, jen čtení): zprávy se stahují s FT_PEEK,
 * takže zůstanou nepřečtené; příznaky ani přesuny se nenastavují.
 */
final class WebklexImapClient implements ImapClientInterface
{
    public function __construct(
        private readonly EmailNoticeTextNormalizer $normalizer,
        private readonly ImapHostGuard $guard,
    ) {}

    public function latest(array $settings, int $limit): array
    {
        $client = $this->client($settings);
        $client->connect();
        try {
            $messages = $this->folder($client, $settings)->query()
                ->all()
                ->leaveUnread()
                ->fetchOrderDesc()
                ->limit(max(1, $limit))
                ->get();
            $out = [];
            foreach ($messages as $message) {
                $out[] = $this->toNotice($message);
            }
            return $out;
        } finally {
            $client->disconnect();
        }
    }

    public function test(array $settings): array
    {
        try {
            $client = $this->client($settings);
            $client->connect();
            $folders = [];
            foreach ($client->getFolders(false) as $folder) {
                $folders[] = (string) $folder->path;
            }
            $this->folder($client, $settings);
            $client->disconnect();
            return ['ok' => true, 'message' => 'IMAP připojení je funkční.', 'folders' => $folders];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function toNotice(object $message): NoticeMessage
    {
        $html = (string) $message->getHTMLBody();
        $text = (string) $message->getTextBody();
        $body = $text !== '' ? $text : ($html !== '' ? $html : (string) $message->getRawBody());
        return new NoticeMessage(
            uid: (int) $message->getUid() ?: null,
            messageId: trim((string) $message->getMessageId()) ?: null,
            date: $this->messageDate($message),
            sender: MimeHeaderDecoder::decode((string) $message->getFrom()),
            subject: MimeHeaderDecoder::decode((string) $message->getSubject()),
            text: $this->normalizer->normalize($body),
            authResults: $this->authenticationResults($message),
        );
    }

    /** @param array<string,mixed> $settings */
    private function client(array $settings): Client
    {
        // Těsně před připojením znovu (DNS se mohl od uložení změnit)
        $denied = $this->guard->check((string) ($settings['host'] ?? ''));
        if ($denied !== null) {
            throw new \RuntimeException($denied);
        }
        $manager = new ClientManager([
            'default'  => 'default',
            'accounts' => [],
            'options'  => [
                'fetch'       => IMAP::FT_PEEK,
                'sequence'    => IMAP::ST_UID,
                'fetch_body'  => true,
                'fetch_flags' => false,
                'message_key' => 'uid',
                'fetch_order' => 'desc',
                'rfc822'      => true,
                'soft_fail'   => true,
            ],
        ]);
        return $manager->make([
            'host'           => (string) ($settings['host'] ?? ''),
            'port'           => (int) ($settings['port'] ?? 993),
            'protocol'       => 'imap',
            'encryption'     => ($settings['encryption'] ?? 'ssl') === 'none' ? false : (string) ($settings['encryption'] ?? 'ssl'),
            'validate_cert'  => (bool) ($settings['validate_cert'] ?? true),
            'username'       => (string) ($settings['username'] ?? ''),
            'password'       => (string) ($settings['password'] ?? ''),
            'authentication' => null,
            'timeout'        => 30,
            'extensions'     => [],
        ]);
    }

    /** @param array<string,mixed> $settings */
    private function folder(Client $client, array $settings): Folder
    {
        $path = trim((string) ($settings['folder'] ?? 'INBOX')) ?: 'INBOX';
        $folder = $client->getFolderByPath($path, false, true);
        if ($folder !== null) {
            return $folder;
        }
        $available = [];
        foreach ($client->getFolders(false, null, true) as $candidate) {
            $available[] = (string) $candidate->path;
            if ((string) $candidate->full_name === $path || (string) $candidate->name === $path) {
                return $candidate;
            }
        }
        throw new \RuntimeException('IMAP složka nebyla nalezena: ' . $path
            . ($available !== [] ? '. Dostupné složky: ' . implode(', ', $available) : '.'));
    }

    /**
     * Hodnoty hlaviček Authentication-Results v pořadí shora dolů (index 0 = přijímací server).
     *
     * @return list<string>
     */
    private function authenticationResults(object $message): array
    {
        try {
            $header = $message->getHeader();
            $raw = is_object($header) && isset($header->raw) ? (string) $header->raw : '';
        } catch (\Throwable) {
            return [];
        }
        if (preg_match_all('/^Authentication-Results:[ \t]*(.*(?:\r?\n[ \t]+.*)*)/mi', $raw, $m) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $value) {
            $value = trim((string) preg_replace('/\s+/', ' ', $value));
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    private function messageDate(object $message): ?\DateTimeImmutable
    {
        try {
            $date = $message->getDate();
            if (is_object($date) && method_exists($date, 'toDate')) {
                return \DateTimeImmutable::createFromInterface($date->toDate());
            }
            $text = trim((string) $date);
            return $text !== '' ? new \DateTimeImmutable($text) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
