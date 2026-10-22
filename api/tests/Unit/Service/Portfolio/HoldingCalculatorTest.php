<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Portfolio;

use MyPortfolio\Service\Portfolio\HoldingCalculator;
use PHPUnit\Framework\TestCase;

final class HoldingCalculatorTest extends TestCase
{
    private function tx(int $id, string $type, string $at, ?string $quantity = null, ?string $amount = null, ?string $ratio = null): array
    {
        return ['id' => $id, 'type' => $type, 'executed_at' => $at, 'quantity' => $quantity, 'amount' => $amount, 'split_ratio' => $ratio];
    }

    public function testMarketHoldingWithRewardTransferAndSplit(): void
    {
        $result = (new HoldingCalculator())->evaluate([
            $this->tx(4, 'split', '2024-04-01 00:00:00', ratio: '2'),
            $this->tx(1, 'buy', '2024-01-01 00:00:00', '1.5'),
            $this->tx(2, 'reward', '2024-02-01 00:00:00', '0.000123'),
            $this->tx(3, 'transfer_out', '2024-03-01 00:00:00', '0.5'),
            $this->tx(5, 'sell', '2024-05-01 00:00:00', '1'),
            $this->tx(6, 'dividend', '2024-06-01 00:00:00', amount: '10'),
        ], 'market');

        // (1.5 + 0.000123 − 0.5) × 2 − 1
        self::assertSame('1.000246', (string) $result->holding->strippedOfTrailingZeros());
        self::assertTrue($result->isValid());
    }

    public function testDetectsFirstNegativePointInTime(): void
    {
        $result = (new HoldingCalculator())->evaluate([
            $this->tx(1, 'buy', '2024-03-01 00:00:00', '1'),
            $this->tx(2, 'sell', '2024-02-01 00:00:00', '1'),
        ], 'market');

        self::assertFalse($result->isValid());
        self::assertSame(2, $result->firstNegative['id']);
        self::assertTrue($result->holding->isZero());
    }

    public function testInflowBeforeOutflowAtSameTimestamp(): void
    {
        $result = (new HoldingCalculator())->evaluate([
            $this->tx(10, 'sell', '2024-01-01 10:00:00', '1'),
            $this->tx(11, 'buy', '2024-01-01 10:00:00', '1'),
        ], 'market');

        self::assertTrue($result->isValid());
    }

    public function testFeeInInstrumentUnitsReducesHolding(): void
    {
        $txs = [
            $this->tx(1, 'buy', '2024-01-01 00:00:00', '1') + ['fee' => '0.01', 'fee_currency' => 'BTC'],
            $this->tx(2, 'transfer_out', '2024-02-01 00:00:00', '0.5') + ['fee' => '0.0001', 'fee_currency' => 'btc'],
            $this->tx(3, 'sell', '2024-03-01 00:00:00', '0.1') + ['fee' => '2', 'fee_currency' => 'EUR'],
        ];

        $result = (new HoldingCalculator())->evaluate($txs, 'market', 'BTC');
        self::assertSame('0.3899', (string) $result->holding->strippedOfTrailingZeros());

        // Bez symbolu (starší volání) se poplatky neodečítají.
        self::assertSame('0.4', (string) (new HoldingCalculator())->evaluate($txs, 'market')->holding->strippedOfTrailingZeros());
    }

    public function testManualHoldingIsNetDeposits(): void
    {
        $result = (new HoldingCalculator())->evaluate([
            $this->tx(1, 'deposit', '2024-01-01 00:00:00', amount: '1000'),
            $this->tx(2, 'interest', '2024-02-01 00:00:00', amount: '5'),
            $this->tx(3, 'withdrawal', '2024-03-01 00:00:00', amount: '250.5'),
        ], 'manual');

        self::assertSame('749.5', (string) $result->holding->strippedOfTrailingZeros());
        self::assertTrue($result->isValid());
    }
}
