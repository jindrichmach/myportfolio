<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Portfolio;

use Brick\Math\BigDecimal;
use MyPortfolio\Service\Portfolio\LotEngine;
use PHPUnit\Framework\TestCase;

final class LotEngineTest extends TestCase
{
    private int $id = 0;

    /** @param array<string,mixed> $data */
    private function tx(int $position, string $type, string $at, array $data = []): array
    {
        return $data + [
            'id' => ++$this->id, 'position_id' => $position, 'type' => $type, 'executed_at' => $at,
            'quantity' => null, 'unit_price' => null, 'price_currency' => 'EUR', 'amount' => null,
            'fee' => null, 'fee_currency' => null, 'split_ratio' => null, 'transfer_group_id' => null,
        ];
    }

    /** @param list<array<string,mixed>> $txs */
    private function engine(array $txs, ?callable $convert = null, ?callable $reward = null): array
    {
        return (new LotEngine())->run(
            $txs,
            'BTC',
            $convert ?? static fn (BigDecimal $a, string $from, string $to) => $from === 'CZK' && $to === 'EUR' ? $a->dividedBy(25, 10) : null,
            $reward ?? static fn () => null,
        );
    }

    private static function eq(string $expected, BigDecimal|string $actual): void
    {
        self::assertTrue(BigDecimal::of($expected)->isEqualTo($actual), "expected $expected, got $actual");
    }

    public function testPartialSellAcrossLotsUsesFifo(): void
    {
        $r = $this->engine([
            $this->tx(1, 'buy', '2021-01-01 10:00', ['quantity' => '1', 'amount' => '100']),
            $this->tx(1, 'buy', '2022-01-01 10:00', ['quantity' => '1', 'amount' => '300']),
            $this->tx(1, 'sell', '2023-01-01 10:00', ['quantity' => '1.5', 'amount' => '600']),
        ]);

        self::assertCount(2, $r['lots']);
        self::eq('0', $r['lots'][0]['remaining']);
        self::eq('0.5', $r['lots'][1]['remaining']);
        self::assertCount(2, $r['disposals']);
        self::eq('1', $r['disposals'][0]['quantity']);
        self::eq('100', $r['disposals'][0]['cost']);
        self::eq('400', $r['disposals'][0]['proceeds']);
        self::eq('150', $r['disposals'][1]['cost']);
        self::eq('200', $r['disposals'][1]['proceeds']);
        self::assertSame(0, $r['disposals'][0]['lot_key']);
        self::assertSame('2023-01-01 10:00', $r['disposals'][0]['disposed_at']);
        self::assertSame([], $r['warnings']);
    }

    public function testFiatFeesAddToCostAndReduceProceeds(): void
    {
        $r = $this->engine([
            $this->tx(1, 'buy', '2021-01-01', ['quantity' => '1', 'amount' => '100', 'fee' => '2']),
            $this->tx(1, 'buy', '2021-02-01', ['quantity' => '1', 'amount' => '100', 'fee' => '50', 'fee_currency' => 'CZK']),
            $this->tx(1, 'sell', '2023-01-01', ['quantity' => '2', 'amount' => '500', 'fee' => '5']),
        ]);

        self::eq('102', $r['lots'][0]['cost']);
        self::eq('102', $r['lots'][1]['cost']); // 50 CZK / 25 = 2 EUR
        self::eq('247.5', $r['disposals'][0]['proceeds']);
        self::eq('247.5', $r['disposals'][1]['proceeds']);
    }

    public function testRewardCreatesLotAtValueOrWarns(): void
    {
        $r = $this->engine([
            $this->tx(1, 'reward', '2023-01-01', ['quantity' => '0.1', 'amount' => '10']),
            $this->tx(1, 'reward', '2023-02-01', ['quantity' => '0.1']),
            $this->tx(1, 'reward', '2023-03-01', ['quantity' => '0.1']),
        ], reward: static fn (array $tx) => $tx['executed_at'] === '2023-02-01' ? [BigDecimal::of('12'), 'EUR'] : null);

        self::eq('10', $r['lots'][0]['cost']);
        self::eq('12', $r['lots'][1]['cost']);
        self::eq('0', $r['lots'][2]['cost']);
        self::assertSame(['reward_without_value:3'], $r['warnings']);
    }

    public function testTransferKeepsAcquisitionDateAndCarriesFeeCost(): void
    {
        $r = $this->engine([
            $this->tx(1, 'buy', '2021-01-01', ['quantity' => '0.1', 'amount' => '1000']),
            $this->tx(1, 'transfer_out', '2023-01-01', ['quantity' => '0.09', 'fee' => '0.01', 'fee_currency' => 'BTC', 'transfer_group_id' => 'g']),
            $this->tx(2, 'transfer_in', '2023-01-01', ['quantity' => '0.09', 'transfer_group_id' => 'g']),
        ]);

        self::assertCount(2, $r['lots']);
        self::eq('0', $r['lots'][0]['remaining']);
        $moved = $r['lots'][1];
        self::assertSame(2, $moved['position_id']);
        self::assertSame(0, $moved['origin_key']);
        self::assertSame('2021-01-01', $moved['acquired_at']);
        self::eq('0.09', $moved['remaining']);
        self::eq('1000', $moved['cost']); // celá nákupní cena včetně spotřebovaného poplatku
        self::assertSame([], $r['disposals']);
        self::assertSame([], $r['warnings']);
    }

    public function testTransferredOlderLotIsSoldBeforeNewerPurchase(): void
    {
        $r = $this->engine([
            $this->tx(2, 'buy', '2021-01-01', ['quantity' => '1', 'amount' => '100']),
            $this->tx(1, 'buy', '2024-01-01', ['quantity' => '1', 'amount' => '500']),
            $this->tx(2, 'transfer_out', '2025-01-01', ['quantity' => '1', 'transfer_group_id' => 'g']),
            $this->tx(1, 'transfer_in', '2025-01-01', ['quantity' => '1', 'transfer_group_id' => 'g']),
            $this->tx(1, 'sell', '2025-06-01', ['quantity' => '1', 'amount' => '800']),
        ]);

        self::assertCount(1, $r['disposals']);
        self::eq('100', $r['disposals'][0]['cost']); // lot z roku 2021, ne novější nákup
        self::assertSame('2021-01-01', $r['lots'][$r['disposals'][0]['lot_key']]['acquired_at']);
        self::eq('1', $r['lots'][1]['remaining']);
    }

    public function testTransferAcrossLotsMovesExactQuantity(): void
    {
        $r = $this->engine([
            $this->tx(1, 'buy', '2021-01-01', ['quantity' => '0.05', 'amount' => '100']),
            $this->tx(1, 'buy', '2021-02-01', ['quantity' => '0.08', 'amount' => '100']),
            $this->tx(1, 'transfer_out', '2023-01-01', ['quantity' => '0.1', 'fee' => '0.00005', 'fee_currency' => 'BTC', 'transfer_group_id' => 'g']),
            $this->tx(2, 'transfer_in', '2023-01-01', ['quantity' => '0.1', 'transfer_group_id' => 'g']),
        ]);

        $moved = array_values(array_filter($r['lots'], static fn (array $l) => $l['position_id'] === 2));
        self::assertCount(2, $moved);
        self::eq('0.1', $moved[0]['remaining']->plus($moved[1]['remaining']));
        self::eq('0.02995', $r['lots'][1]['remaining']);
    }

    public function testInstrumentFeeOnSellAndBuy(): void
    {
        $r = $this->engine([
            $this->tx(1, 'buy', '2021-01-01', ['quantity' => '1', 'amount' => '100', 'fee' => '0.1', 'fee_currency' => 'BTC']),
            $this->tx(1, 'sell', '2022-01-01', ['quantity' => '0.4', 'amount' => '80', 'fee' => '0.05', 'fee_currency' => 'BTC']),
        ]);

        self::eq('0.9', $r['lots'][0]['quantity']);
        self::eq('0.45', $r['lots'][0]['remaining']);
        self::assertSame(['sell', 'fee'], array_column($r['disposals'], 'kind'));
        self::eq('80', $r['disposals'][0]['proceeds']);
        self::eq('0', $r['disposals'][1]['proceeds']);
        self::eq('0.05', $r['disposals'][1]['quantity']);
        self::assertSame([], $r['warnings']);
    }

    public function testFeeInOtherCryptoIsOnlyRecordedWithWarning(): void
    {
        $r = $this->engine([
            $this->tx(1, 'buy', '2021-01-01', ['quantity' => '1', 'amount' => '100', 'fee' => '0.01', 'fee_currency' => 'BNB']),
        ]);

        self::eq('1', $r['lots'][0]['quantity']);
        self::eq('100', $r['lots'][0]['cost']);
        self::assertSame(['fee_not_converted:1'], $r['warnings']);
    }

    public function testSplitMultipliesOpenLotsOfPosition(): void
    {
        $r = $this->engine([
            $this->tx(1, 'buy', '2021-01-01', ['quantity' => '2', 'amount' => '100']),
            $this->tx(2, 'buy', '2021-01-01', ['quantity' => '1', 'amount' => '50']),
            $this->tx(1, 'split', '2022-01-01', ['split_ratio' => '10']),
            $this->tx(1, 'sell', '2022-06-01', ['quantity' => '5', 'amount' => '40']),
        ]);

        self::eq('15', $r['lots'][0]['remaining']);
        self::eq('100', $r['lots'][0]['cost']);
        self::eq('1', $r['lots'][1]['remaining']);
        self::eq('25', $r['disposals'][0]['cost']);
    }

    public function testSellFromOtherPositionDoesNotTouchLots(): void
    {
        $r = $this->engine([
            $this->tx(1, 'buy', '2021-01-01', ['quantity' => '1', 'amount' => '100']),
            $this->tx(2, 'sell', '2021-02-01', ['quantity' => '1', 'amount' => '100']),
        ]);

        self::eq('1', $r['lots'][0]['remaining']);
        self::assertSame(['insufficient_quantity:2'], $r['warnings']);
    }
}
