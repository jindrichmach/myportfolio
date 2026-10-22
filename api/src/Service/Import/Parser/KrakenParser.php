<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Import\Parser;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\Import\Draft;

/**
 * Kraken — `ledgers.csv` (Documents → Exports → Ledgers, CSV v ZIPu):
 *   "txid","refid","time","type","subtype","aclass"[,"subclass"],"asset"[,"wallet"],"amount","fee","balance"
 * Časy v UTC. `amount` je bez poplatku (poplatek zvlášť, kladný). Obchod = řádky se stejným refid (trade,
 * spend/receive). Aktiva: staré názvy XXBT / ZEUR / XXDG, přípony stakingu .S .M .F .B .P (DOT28.S).
 */
final class KrakenParser extends AbstractParser
{
    private const LEGACY = ['XXBT' => 'BTC', 'XBT' => 'BTC', 'XXDG' => 'DOGE', 'XDG' => 'DOGE', 'XETH' => 'ETH', 'XLTC' => 'LTC',
        'XXRP' => 'XRP', 'XXLM' => 'XLM', 'XXMR' => 'XMR', 'XZEC' => 'ZEC', 'XETC' => 'ETC', 'XMLN' => 'MLN', 'XREP' => 'REP',
        'ZEUR' => 'EUR', 'ZUSD' => 'USD', 'ZGBP' => 'GBP', 'ZCAD' => 'CAD', 'ZJPY' => 'JPY', 'ZAUD' => 'AUD', 'ETH2' => 'ETH'];

    public function key(): string
    {
        return 'kraken';
    }

    public function label(): string
    {
        return 'Kraken';
    }

    public function detect(array $tables): bool
    {
        return $this->hasHeader($tables, ['txid', 'refid', 'time', 'type', 'asset', 'amount', 'fee']);
    }

    public function parse(array $tables): array
    {
        $rows = $this->rows($tables, ['txid', 'refid', 'time', 'type', 'asset', 'amount', 'fee'])
            ?? throw new \InvalidArgumentException('Nenalezena hlavička Kraken ledgers.csv.');
        $out = [];
        $groups = [];
        foreach ($rows as $row) {
            $type = strtolower($this->col($row, 'type'));
            if (in_array($type, ['trade', 'spend', 'receive'], true)) {
                $groups[$this->col($row, 'refid')][] = $row;
                continue;
            }
            $out[] = $this->single($row, $type);
        }
        foreach ($groups as $refid => $legs) {
            $net = [];
            $fees = [];
            $at = '';
            foreach ($legs as $leg) {
                $asset = $this->asset($this->col($leg, 'asset'));
                if ($asset === 'KFEE') {
                    continue;
                }
                $net[$asset] = ($net[$asset] ?? BigDecimal::zero())->plus(BigDecimal::of($this->num($this->col($leg, 'amount'), false) ?? '0'));
                $fee = BigDecimal::of($this->num($this->col($leg, 'fee')) ?? '0');
                if ($fee->isPositive()) {
                    $fees[$asset] = ($fees[$asset] ?? BigDecimal::zero())->plus($fee);
                }
                $at = max($at, $this->dateTime($this->col($leg, 'time')));
            }
            $out = [...$out, ...$this->tradeFromNet($net, $fees, $at, (string) $refid, min(array_map([$this, 'line'], $legs)))];
        }
        return $out;
    }

    private function single(array $row, string $type): Draft
    {
        $line = $this->line($row);
        $at = $this->dateTime($this->col($row, 'time'));
        $id = $this->col($row, 'txid') ?: $this->rowHash($row);
        $asset = $this->asset($this->col($row, 'asset'));
        $subtype = strtolower($this->col($row, 'subtype'));
        $amount = BigDecimal::of($this->num($this->col($row, 'amount'), false) ?? '0');
        $fee = BigDecimal::of($this->num($this->col($row, 'fee')) ?? '0');
        if ($asset === 'KFEE') {
            return Draft::skip($at, 'Kraken fee credits', $line, $id, $asset);
        }
        if ($this->isFiat($asset) && $type !== 'staking' && $type !== 'earn') {
            return Draft::skip($at, 'Pohyb fiat měny (vklad / výběr)', $line, $id, $asset);
        }
        if (($type === 'staking' && $amount->isPositive()) || ($type === 'earn' && $subtype === 'reward')) {
            return new Draft('reward', $at, $asset, quantity: (string) $amount->minus($fee)->abs()->strippedOfTrailingZeros(), externalId: $id, note: 'Staking', line: $line);
        }
        if ($type === 'deposit') {
            return new Draft('receive', $at, $asset, quantity: (string) $amount->abs(), externalId: $id, line: $line);
        }
        if ($type === 'withdrawal') {
            return new Draft('send', $at, $asset, quantity: (string) $amount->abs(), fee: $fee->isPositive() ? (string) $fee : null,
                feeCurrency: $fee->isPositive() ? $asset : null, externalId: $id, line: $line);
        }
        if (in_array($type, ['earn', 'transfer', 'staking'], true)) {
            return Draft::skip($at, 'Interní přesun (staking, earn, peněženky)', $line, $id, $asset);
        }
        return Draft::skip($at, "Nepodporovaný typ „{$type}\" — zadej ručně", $line, $id, $asset);
    }

    /** XXBT → BTC, ZEUR → EUR, DOT.S / DOT28.S → DOT, ETH2.S → ETH. */
    private function asset(string $raw): string
    {
        $a = strtoupper(trim($raw));
        $a = preg_replace('/(\d{2})?\.(S|M|F|B|P|HOLD)$/', '', $a) ?? $a;
        return self::LEGACY[$a] ?? $a;
    }
}
