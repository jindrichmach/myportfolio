<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import;

use Brick\Math\BigDecimal;

/**
 * Sdílená klasifikace pohybů pro parsery CSV i klienty API burz: fiat vs. krypto, stablecoiny,
 * obchod ze změn zůstatků.
 */
trait DraftHelpers
{
    /** Fiat měny — pohyby v nich (vklady, výběry) se neimportují, u obchodů jsou měnou ceny. */
    public const FIAT = ['CZK', 'EUR', 'USD', 'GBP', 'CHF', 'PLN', 'HUF', 'CAD', 'AUD', 'JPY', 'SEK', 'NOK', 'DKK', 'NZD', 'SGD', 'HKD', 'TRY', 'BRL', 'RON'];

    protected function isFiat(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::FIAT, true);
    }

    /** Stablecoiny navázané na USD — obchod proti nim se ocení v USD (ČNB stablecoiny nezná). */
    public const USD_STABLECOINS = ['USDT', 'USDC', 'BUSD', 'FDUSD', 'TUSD', 'DAI', 'USDP', 'PYUSD'];

    /**
     * Obchod ze součtů změn zůstatků (Binance skupina řádků, Kraken refid, …): právě jedno aktivum přibylo
     * a jedno ubylo. Fiat strana = měna ceny; stablecoin strana = cena v USD + zapíše se i pohyb stablecoinu;
     * krypto za krypto bez stablecoinu → přeskočit (cenu nejde převést kurzem ČNB).
     *
     * @param array<string,BigDecimal> $net aktivum → čistá změna (bez poplatků)
     * @param array<string,BigDecimal> $fees aktivum → zaplacený poplatek (kladný)
     * @return list<Draft>
     */
    protected function tradeFromNet(array $net, array $fees, string $at, string $id, int $line, ?string $note = null): array
    {
        $net = array_filter($net, static fn (BigDecimal $v) => !$v->isZero());
        $in = array_keys(array_filter($net, static fn (BigDecimal $v) => $v->isPositive()));
        $out = array_keys(array_filter($net, static fn (BigDecimal $v) => $v->isNegative()));
        if (count($in) !== 1 || count($out) !== 1) {
            return [Draft::skip($at, 'Nejednoznačný obchod (' . implode(', ', array_keys($net)) . ') — zadej ručně', $line, $id)];
        }
        [$got, $paid] = [$in[0], $out[0]];
        $gotQty = (string) $net[$got]->abs();
        $paidQty = (string) $net[$paid]->abs();
        $feeCoin = array_key_first($fees);
        $fee = $feeCoin !== null ? (string) $fees[$feeCoin] : null;

        if ($this->isFiat($paid) || $this->isFiat($got)) {
            $buy = $this->isFiat($paid);
            [$crypto, $qty, $fiat, $amount] = $buy ? [$got, $gotQty, $paid, $paidQty] : [$paid, $paidQty, $got, $gotQty];
            return [new Draft($buy ? 'buy' : 'sell', $at, $crypto, quantity: $qty, amount: $amount, currency: $fiat,
                fee: $fee, feeCurrency: $feeCoin, externalId: $id, note: $note, line: $line)];
        }
        $paidStable = in_array($paid, self::USD_STABLECOINS, true);
        $gotStable = in_array($got, self::USD_STABLECOINS, true);
        if (!$paidStable && !$gotStable) {
            return [Draft::skip($at, "Obchod krypto za krypto ({$paid} → {$got}) — zadej ručně", $line, $id, $got)];
        }
        // Hodnota v USD = množství stablecoinu (1:1)
        $usd = $paidStable ? $paidQty : $gotQty;
        $stableNote = trim(($note ?? '') . ' (cena v ' . ($paidStable ? $paid : $got) . ')');
        return [
            new Draft('buy', $at, $got, quantity: $gotQty, amount: $usd, currency: 'USD',
                fee: $fee, feeCurrency: $feeCoin, externalId: $id, note: $stableNote, line: $line),
            new Draft('sell', $at, $paid, quantity: $paidQty, amount: $usd, currency: 'USD', externalId: $id . ':paid', note: $stableNote, line: $line),
        ];
    }
}
