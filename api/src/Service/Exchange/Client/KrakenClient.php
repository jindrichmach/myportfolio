<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange\Client;

use MyPortfolio\Service\Exchange\ExchangeException;
use MyPortfolio\Service\Import\Parser\KrakenParser;

/**
 * Kraken — POST /0/private/Ledgers (stránkování kurzorem po 50, nejnovější první). Ledger má stejné sloupce
 * jako `ledgers.csv`, takže řádky zpracuje KrakenParser (obchody podle refid, staking, odeslání / příjmy).
 * Podpis: API-Sign = base64(HMAC-SHA512(cesta + SHA256(nonce + tělo), base64_decode(secret))).
 * Klíč stačí s oprávněním „Query Ledger Entries"; GetApiKeyInfo ukáže, jestli nemá víc.
 */
final class KrakenClient extends AbstractExchangeClient
{
    private const BASE = 'https://api.kraken.com';
    private const DANGEROUS = ['add-funds', 'withdraw-funds', 'earn-funds', 'modify-trades', 'close-trades',
        'add-withdraw-address', 'update-withdraw-address'];

    public function provider(): string
    {
        return 'kraken';
    }

    public function label(): string
    {
        return 'Kraken';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'api_key', 'label' => ['API klíč', 'API key'], 'secret' => false],
            ['key' => 'api_secret', 'label' => ['Privátní klíč (Private key)', 'Private key'], 'secret' => true],
        ];
    }

    public function test(array $credentials): array
    {
        $info = $this->call($credentials, 'GetApiKeyInfo', [], true);
        if ($info === null) {
            $this->call($credentials, 'Ledgers', ['without_count' => 'true']);
            return ['ok' => true, 'message' => 'Klíč funguje — ledger jde číst.', 'warnings' => []];
        }
        $permissions = (array) ($info['permissions'] ?? []);
        if (!in_array('query-ledger', $permissions, true)) {
            return ['ok' => false, 'message' => 'Klíči chybí oprávnění „Query Ledger Entries".', 'warnings' => []];
        }
        $extra = array_values(array_intersect($permissions, self::DANGEROUS));
        return ['ok' => true, 'message' => 'Klíč funguje — ledger jde číst.',
            'warnings' => $extra !== [] ? ['Klíč má i oprávnění ' . implode(', ', $extra) . ' — pro synchronizaci stačí jen čtení, vytvoř raději klíč jen pro čtení.'] : []];
    }

    public function fetch(array $credentials, ?\DateTimeImmutable $from, \DateTimeImmutable $to, array $knownAssets = []): array
    {
        $rows = [];
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $params = ['with_cursor' => 'true', 'without_count' => 'true', 'end' => (string) $to->getTimestamp()];
            if ($from !== null) {
                $params['start'] = (string) ($from->getTimestamp() - 1);  // start je exkluzivní
            }
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }
            $result = $this->call($credentials, 'Ledgers', $params);
            foreach ((array) ($result['ledger'] ?? []) as $id => $e) {
                $rows[] = [(string) $id, (string) $e['refid'], self::utc((string) $e['time']), (string) $e['type'], (string) ($e['subtype'] ?? ''),
                    (string) ($e['aclass'] ?? 'currency'), (string) $e['asset'], (string) $e['amount'], (string) $e['fee'], (string) ($e['balance'] ?? '')];
            }
            $cursor = $result['cursor']['next'] ?? null;
            if ($cursor === null || $cursor === '') {
                break;
            }
            $this->pace($page);
        }
        if ($rows === []) {
            return [];
        }
        $table = self::table(['txid', 'refid', 'time', 'type', 'subtype', 'aclass', 'asset', 'amount', 'fee', 'balance'], array_reverse($rows));
        return (new KrakenParser())->parse([$table]);
    }

    /**
     * @param array<string,string> $credentials
     * @param array<string,string> $params
     * @return array<string,mixed>|null null = metoda neexistuje (jen u $optional)
     */
    private function call(array $credentials, string $method, array $params, bool $optional = false): ?array
    {
        $secret = base64_decode(self::credential($credentials, 'api_secret'), true);
        if ($secret === false) {
            throw new ExchangeException('Privátní klíč Krakenu není platný base64.');
        }
        $path = '/0/private/' . $method;
        for ($attempt = 0; ; $attempt++) {
            $nonce = self::nonce();
            $body = http_build_query(['nonce' => $nonce] + $params);
            $sign = self::sign($path, $nonce, $body, $secret);
            $r = $this->http->request('POST', self::BASE . $path, [
                'API-Key' => self::credential($credentials, 'api_key'), 'API-Sign' => $sign,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], $body);
            $json = \MyPortfolio\Service\Exchange\ExchangeJson::decode($r['body'] !== '' ? $r['body'] : '{}');
            $errors = (array) ($json['error'] ?? []);
            if ($errors === []) {
                return (array) ($json['result'] ?? []);
            }
            $error = (string) $errors[0];
            if ($optional && (str_contains($error, 'Unknown method') || str_contains($error, 'Permission denied'))) {
                return null;
            }
            if ((str_contains($error, 'Rate limit') || str_contains($error, 'Throttled')) && $attempt < 3) {
                $this->sleep(10 * ($attempt + 1));
                continue;
            }
            throw new ExchangeException('Kraken: ' . match (true) {
                str_contains($error, 'Invalid key') => 'neplatný API klíč.',
                str_contains($error, 'Invalid signature') => 'neplatný podpis — zkontroluj privátní klíč.',
                str_contains($error, 'Permission denied') => 'klíči chybí oprávnění (Query Ledger Entries).',
                default => $error,
            });
        }
    }

    /** API-Sign = base64(HMAC-SHA512(cesta + SHA256(nonce + tělo), dekódovaný secret)). */
    public static function sign(string $path, string $nonce, string $body, string $secret): string
    {
        return base64_encode(hash_hmac('sha512', $path . hash('sha256', $nonce . $body, true), $secret, true));
    }

    /** Čítač API Krakenu: ledger stojí 2 body, Starter má 15 a ubývá 0,33/s → po prvních stránkách zpomalit. */
    private function pace(int $page): void
    {
        if ($page >= 5) {
            $this->sleep(6);
        }
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
