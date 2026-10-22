<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Exchange\Client;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Service\Exchange\ExchangeException;
use MyPortfolio\Service\Exchange\ExchangeJson;
use MyPortfolio\Service\Import\Parser\CoinbaseParser;

/**
 * Coinbase — CDP API klíč (doporučený typ ECDSA, oprávnění jen View). Zdrojem je Coinbase App API v2:
 * /v2/accounts → /v2/accounts/{id}/transactions (obsahuje i Advanced Trade filly, proto ne v3 /fills — duplicity).
 * Transakce se převedou na řádky CSV exportu „Transaction history" v4 a zpracuje je CoinbaseParser.
 *
 * Hodnoty se berou z `native_amount` (měna účtu Coinbase, např. EUR). API v2 u jednoduchých nákupů / prodejů
 * neuvádí poplatek → částka je včetně poplatku (Total). U Advanced Trade fillu je poplatek (commission).
 * Ověření JWT (ES256 nebo EdDSA): hlavička kid = název klíče, nonce; claims iss=cdp, sub, nbf, exp (+120 s),
 * uri „METHOD host/cesta" bez query.
 */
final class CoinbaseClient extends AbstractExchangeClient
{
    private const HOST = 'api.coinbase.com';
    private const VERSION = '2024-10-01';
    private const REWARDS = ['earn_payout', 'incentives_rewards_payout', 'staking_reward', 'inflation_reward', 'interest', 'subscription_rebate'];
    private const INTERNAL = ['transfer', 'staking_transfer', 'unstaking_transfer', 'wrap_asset', 'unwrap_asset', 'pro_deposit', 'pro_withdrawal',
        'vault_withdrawal', 'intx_deposit', 'intx_withdrawal', 'exchange_deposit', 'exchange_withdrawal'];

    public function provider(): string
    {
        return 'coinbase';
    }

    public function label(): string
    {
        return 'Coinbase';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'key_name', 'label' => ['Název klíče (organizations/…/apiKeys/…)', 'Key name (organizations/…/apiKeys/…)'], 'secret' => false],
            ['key' => 'private_key', 'label' => ['Privátní klíč (ECDSA PEM nebo Ed25519)', 'Private key (ECDSA PEM or Ed25519)'], 'secret' => true, 'multiline' => true],
        ];
    }

    public function test(array $credentials): array
    {
        $p = (array) $this->get($credentials, '/api/v3/brokerage/key_permissions');
        if (($p['can_view'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'Klíč nemá oprávnění View.', 'warnings' => []];
        }
        $extra = array_keys(array_filter(['Trade' => $p['can_trade'] ?? false, 'Transfer' => $p['can_transfer'] ?? false], static fn ($v) => $v === true));
        return ['ok' => true, 'message' => 'Klíč funguje — historie jde číst.',
            'warnings' => $extra !== [] ? ['Klíč má i oprávnění ' . implode(', ', $extra) . ' — pro synchronizaci stačí jen View, vytvoř raději klíč jen pro čtení.'] : []];
    }

    public function fetch(array $credentials, ?\DateTimeImmutable $from, \DateTimeImmutable $to, array $knownAssets = []): array
    {
        $transactions = [];
        foreach ($this->pages($credentials, '/v2/accounts?limit=100') as $account) {
            if (($account['type'] ?? '') === 'fiat') {
                continue;  // fiat pohyby (vklady, výběry) se neimportují
            }
            foreach ($this->pages($credentials, '/v2/accounts/' . rawurlencode((string) $account['id']) . '/transactions?limit=100&order=desc', $from) as $t) {
                $at = new \DateTimeImmutable((string) $t['created_at']);
                if (($from !== null && $at < $from) || $at > $to || ($t['status'] ?? '') !== 'completed') {
                    continue;
                }
                $transactions[] = $t;
            }
        }
        $rows = $this->rows($transactions);
        if ($rows === []) {
            return [];
        }
        $table = self::table(['ID', 'Timestamp', 'Transaction Type', 'Asset', 'Quantity Transacted', 'Price Currency', 'Price at Transaction',
            'Subtotal', 'Total (inclusive of fees and/or spread)', 'Fees and/or Spread', 'Notes'], $rows);
        return (new CoinbaseParser())->parse([$table]);
    }

    /**
     * Transakce v2 → řádky CSV v4. Convert (`trade`) má dvě nohy v různých účtech — spojí se do jednoho řádku.
     * Fill Advanced Trade je na účtu základu i kotace (USDC…) s různým id → jeden řádek za fill (order_id + čas)
     * s množstvím základu; poplatek v nefiat kotaci se přepočte na nativní měnu poměrem native_amount.
     *
     * @param list<array<string,mixed>> $transactions
     * @return list<list<string>>
     */
    private function rows(array $transactions): array
    {
        $rows = [];
        $trades = [];
        $fills = [];
        foreach ($transactions as $t) {
            $type = (string) $t['type'];
            $qty = BigDecimal::of((string) $t['amount']['amount']);
            $asset = strtoupper((string) $t['amount']['currency']);
            $native = BigDecimal::of((string) ($t['native_amount']['amount'] ?? '0'))->abs();
            $fiat = strtoupper((string) ($t['native_amount']['currency'] ?? 'USD'));
            $time = (new \DateTimeImmutable((string) $t['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC';
            $price = $qty->isZero() ? '' : (string) $native->dividedBy($qty->abs(), 10, RoundingMode::HalfUp)->strippedOfTrailingZeros();
            $row = static fn (string $csvType, string $subtotal, string $total, string $fee, string $notes) => [
                (string) $t['id'], $time, $csvType, $asset, (string) $qty->abs(), $fiat, $price, $subtotal, $total, $fee, $notes];

            if ($type === 'trade') {
                $key = (string) ($t['trade']['id'] ?? substr((string) $t['created_at'], 0, 19));
                $trades[$key][] = ['t' => $t, 'qty' => $qty, 'asset' => $asset, 'native' => $native, 'row' => $row];
                continue;
            }
            if ($type === 'advanced_trade_fill') {
                $fill = (array) ($t['advanced_trade_fill'] ?? []);
                $key = (string) ($fill['trade_id'] ?? (($fill['order_id'] ?? '') . '@' . $t['created_at']));
                $fills[$key][] = ['t' => $t, 'fill' => $fill, 'qty' => $qty, 'asset' => $asset, 'native' => $native, 'fiat' => $fiat, 'time' => $time];
                continue;
            }
            $rows[] = match (true) {
                $type === 'buy'                        => $row('Buy', '', (string) $native, '', 'Cena včetně poplatku (API ho neuvádí)'),
                $type === 'sell'                       => $row('Sell', '', (string) $native, '', 'Výnos po poplatku (API ho neuvádí)'),
                $type === 'send'                       => $row('Send', '', '', '', (string) ($t['description'] ?? '')),
                $type === 'receive'                    => $row('Receive', '', '', '', (string) ($t['description'] ?? '')),
                in_array($type, self::REWARDS, true)   => $row('Reward Income', (string) $native, (string) $native, '', $type),
                in_array($type, self::INTERNAL, true)  => $row('Transfer', '', '', '', $type),
                default                                => $row($type, '', '', '', ''),
            };
        }
        foreach ($fills as $key => $legs) {
            $rows[] = self::fillRow((string) $key, $legs);
        }
        foreach ($trades as $legs) {
            $out = array_values(array_filter($legs, static fn (array $l) => $l['qty']->isNegative()));
            $in = array_values(array_filter($legs, static fn (array $l) => $l['qty']->isPositive()));
            if (count($out) !== 1 || count($in) !== 1) {
                foreach ($legs as $l) {
                    $rows[] = ($l['row'])('Convert', '', '', '', '');  // bez poznámky → parser řádek přeskočí k ručnímu zadání
                }
                continue;
            }
            $notes = sprintf('Converted %s %s to %s %s', $out[0]['qty']->abs(), $out[0]['asset'], $in[0]['qty']->abs(), $in[0]['asset']);
            $rows[] = ($out[0]['row'])('Convert', (string) $out[0]['native'], (string) $out[0]['native'], '', $notes);
        }
        usort($rows, static fn (array $a, array $b) => $b[1] <=> $a[1]);  // jako API: od nejnovější
        return $rows;
    }

    /**
     * Jeden řádek CSV za fill: noha na účtu základu (jinak dopočet z nohy kotace přes fill_price).
     *
     * @param list<array<string,mixed>> $legs
     * @return list<string>
     */
    private static function fillRow(string $key, array $legs): array
    {
        $fill = $legs[0]['fill'];
        [$base, $quote] = array_pad(explode('-', (string) ($fill['product_id'] ?? '')), 2, '');
        $price = BigDecimal::of((string) ($fill['fill_price'] ?? '0'));
        $leg = array_values(array_filter($legs, static fn (array $l) => $l['asset'] === $base))[0] ?? $legs[0];
        $qty = $leg['asset'] === $base || !$price->isPositive()
            ? $leg['qty']->abs()
            : $leg['qty']->abs()->dividedBy($price, 18, RoundingMode::HalfUp)->strippedOfTrailingZeros();
        $buy = strtoupper((string) ($fill['order_side'] ?? ($leg['qty']->isPositive() ? 'BUY' : 'SELL'))) === 'BUY';
        $native = $leg['native'];
        $fiat = $leg['fiat'];
        $quoteAmount = $price->multipliedBy($qty);
        $commission = BigDecimal::of((string) ($fill['commission'] ?? '0'));
        $fee = match (true) {
            $commission->isZero()       => '',
            $quote === $fiat            => (string) $commission,
            $quoteAmount->isPositive()  => (string) $commission->multipliedBy($native)->dividedBy($quoteAmount, 2, RoundingMode::HalfUp),
            default                     => '',
        };
        $unit = $qty->isZero() ? '' : (string) $native->dividedBy($qty, 10, RoundingMode::HalfUp)->strippedOfTrailingZeros();
        $notes = sprintf('%s %s %s for %s %s on %s', $buy ? 'Bought' : 'Sold', $qty, $base, $quoteAmount->strippedOfTrailingZeros(), $quote, $fill['product_id'] ?? '');
        return ['fill:' . $key, $leg['time'], $buy ? 'Advanced Trade Buy' : 'Advanced Trade Sell', $base, (string) $qty, $fiat, $unit,
            (string) $native, (string) $native, $fee, $notes];
    }

    /**
     * Stránkování v2 přes `pagination.next_uri`; u transakcí (řazeno od nejnovější) končí po prvním starším než $from.
     *
     * @param array<string,string> $credentials
     * @return \Generator<array<string,mixed>>
     */
    private function pages(array $credentials, string $path, ?\DateTimeImmutable $from = null): \Generator
    {
        for ($page = 0; $path !== '' && $page < self::MAX_PAGES; $page++) {
            $json = (array) $this->get($credentials, $path);
            foreach ((array) ($json['data'] ?? []) as $item) {
                yield $item;
                if ($from !== null && isset($item['created_at']) && new \DateTimeImmutable((string) $item['created_at']) < $from) {
                    return;
                }
            }
            $path = (string) ($json['pagination']['next_uri'] ?? '');
        }
    }

    /** @param array<string,string> $credentials */
    private function get(array $credentials, string $pathWithQuery): mixed
    {
        $path = (string) parse_url($pathWithQuery, PHP_URL_PATH);
        $jwt = $this->jwt($credentials, 'GET ' . self::HOST . $path);
        $r = $this->http->request('GET', 'https://' . self::HOST . $pathWithQuery, ['Authorization' => 'Bearer ' . $jwt, 'CB-VERSION' => self::VERSION]);
        $json = ExchangeJson::decode($r['body'] !== '' ? $r['body'] : '{}');
        if ($r['status'] >= 200 && $r['status'] < 300) {
            return $json;
        }
        $message = (string) ($json['errors'][0]['message'] ?? $json['message'] ?? $json['error'] ?? ('HTTP ' . $r['status']));
        throw new ExchangeException('Coinbase: ' . match (true) {
            $r['status'] === 401 => 'neplatný klíč nebo podpis (' . $message . ').',
            $r['status'] === 403 => 'klíči chybí oprávnění View.',
            $r['status'] === 429 => 'příliš mnoho požadavků — zkus to později.',
            default              => $message,
        });
    }

    /** JWT pro jeden požadavek (ES256 pro ECDSA PEM, EdDSA pro Ed25519). @param array<string,string> $credentials */
    private function jwt(array $credentials, string $uri): string
    {
        $keyName = self::credential($credentials, 'key_name');
        $secret = str_replace('\n', "\n", self::credential($credentials, 'private_key'));
        $ecdsa = str_contains($secret, 'BEGIN');
        $now = time();
        $header = ['alg' => $ecdsa ? 'ES256' : 'EdDSA', 'kid' => $keyName, 'nonce' => bin2hex(random_bytes(16)), 'typ' => 'JWT'];
        $claims = ['iss' => 'cdp', 'sub' => $keyName, 'nbf' => $now, 'exp' => $now + 120, 'uri' => $uri];
        $input = self::b64(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $input . '.' . self::b64($ecdsa ? self::signEs256($input, $secret) : self::signEd25519($input, $secret));
    }

    private static function signEs256(string $input, string $pem): string
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new ExchangeException('Coinbase: privátní klíč ECDSA nejde načíst — vlož celý blok včetně BEGIN/END.');
        }
        // DER (SEQUENCE{INTEGER r, INTEGER s}) → JWS r‖s po 32 bajtech
        $offset = 3 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 1, $len), "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }
        return $parts[0] . $parts[1];
    }

    private static function signEd25519(string $input, string $secret): string
    {
        $raw = base64_decode($secret, true);
        if ($raw === false || !in_array(strlen($raw), [32, 64], true)) {
            throw new ExchangeException('Coinbase: privátní klíč Ed25519 není platný (base64, 32 nebo 64 bajtů).');
        }
        $secretKey = strlen($raw) === 64 ? $raw : sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw));
        return sodium_crypto_sign_detached($input, $secretKey);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
