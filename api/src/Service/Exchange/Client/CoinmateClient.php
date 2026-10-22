<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange\Client;

use MyPortfolio\Service\Exchange\ExchangeException;
use MyPortfolio\Service\Exchange\ExchangeJson;
use MyPortfolio\Service\Import\Parser\CoinmateParser;

/**
 * Coinmate — POST /api/transactionHistory (obchody, vklady, výběry, odměny; offset / limit 1000, ASC).
 * Řádky se převedou na tabulku ve formátu CSV exportu (ID;Date;Type;Amount;…, čas Praha) a zpracuje je
 * CoinmateParser — stejné ID `transactionId:TYP` jako u CSV. Podpis: HMAC-SHA256(nonce + clientId + publicKey,
 * privateKey), velká hex. Chyby chodí s HTTP 200 a `error: true`. Limit 100 požadavků / min na IP.
 * Oprávnění klíče API neprozradí — klíč má mít jen „Account Info".
 */
final class CoinmateClient extends AbstractExchangeClient
{
    private const BASE = 'https://coinmate.io/api/';
    private const PAGE = 1000;

    public function provider(): string
    {
        return 'coinmate';
    }

    public function label(): string
    {
        return 'Coinmate';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'client_id', 'label' => ['Client ID', 'Client ID'], 'secret' => false],
            ['key' => 'public_key', 'label' => ['Veřejný klíč (Public key)', 'Public key'], 'secret' => false],
            ['key' => 'private_key', 'label' => ['Privátní klíč (Private key)', 'Private key'], 'secret' => true],
        ];
    }

    public function test(array $credentials): array
    {
        $this->call($credentials, 'balances', []);
        return ['ok' => true, 'message' => 'Klíč funguje — zůstatky jdou číst.',
            'warnings' => ['Coinmate neumí ověřit oprávnění klíče — použij klíč jen s oprávněním „Account Info" (bez obchodování a výběrů).']];
    }

    public function fetch(array $credentials, ?\DateTimeImmutable $from, \DateTimeImmutable $to, array $knownAssets = []): array
    {
        $prague = new \DateTimeZone('Europe/Prague');
        $rows = [];
        for ($page = 0, $offset = 0; $page < self::MAX_PAGES; $page++) {
            $params = ['sort' => 'ASC', 'limit' => (string) self::PAGE, 'offset' => (string) $offset,
                'timestampTo' => (string) ($to->getTimestamp() * 1000)];
            if ($from !== null) {
                $params['timestampFrom'] = (string) ($from->getTimestamp() * 1000);
            }
            $data = (array) $this->call($credentials, 'transactionHistory', $params);
            foreach ($data as $t) {
                $at = (new \DateTimeImmutable('@' . intdiv((int) $t['timestamp'], 1000)))->setTimezone($prague)->format('Y-m-d H:i:s');
                $rows[] = [(string) $t['transactionId'], $at, (string) $t['transactionType'], (string) ($t['amount'] ?? ''),
                    (string) ($t['amountCurrency'] ?? ''), (string) ($t['price'] ?? ''), (string) ($t['priceCurrency'] ?? ''),
                    (string) ($t['fee'] ?? ''), (string) ($t['feeCurrency'] ?? ''), (string) ($t['description'] ?? ''), (string) ($t['status'] ?? '')];
            }
            if (count($data) < self::PAGE) {
                break;
            }
            $offset += count($data);
            $this->sleep(1);
        }
        if ($rows === []) {
            return [];
        }
        $table = self::table(['ID', 'Date', 'Type', 'Amount', 'Amount Currency', 'Price', 'Price Currency', 'Fee', 'Fee Currency', 'Description', 'Status'], $rows);
        return (new CoinmateParser())->parse([$table]);
    }

    /**
     * @param array<string,string> $credentials
     * @param array<string,string> $params
     */
    private function call(array $credentials, string $method, array $params): mixed
    {
        $clientId = self::credential($credentials, 'client_id');
        $publicKey = self::credential($credentials, 'public_key');
        $nonce = self::nonce();
        $signature = strtoupper(hash_hmac('sha256', $nonce . $clientId . $publicKey, self::credential($credentials, 'private_key')));
        $r = $this->http->request('POST', self::BASE . $method, ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['clientId' => $clientId, 'publicKey' => $publicKey, 'nonce' => $nonce, 'signature' => $signature] + $params));
        if ($r['status'] === 429) {
            throw new ExchangeException('Coinmate: příliš mnoho požadavků — zkus to za minutu.');
        }
        $json = ExchangeJson::decode($r['body'] !== '' ? $r['body'] : '{}');
        if (!is_array($json) || ($json['error'] ?? true) !== false) {
            $message = (string) ($json['errorMessage'] ?? ('HTTP ' . $r['status']));
            throw new ExchangeException('Coinmate: ' . match (true) {
                str_contains($message, 'Access denied') => 'přístup odepřen — zkontroluj Client ID a klíče.',
                str_contains($message, 'Insufficient key privileges') => 'klíči chybí oprávnění (Account Info).',
                default => $message,
            });
        }
        return $json['data'] ?? [];
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
