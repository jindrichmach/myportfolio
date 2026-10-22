<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Portfolio;

use MyPortfolio\Service\Portfolio\TransactionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class TransactionValidatorTest extends TestCase
{
    private const MARKET = ['valuation_mode' => 'market', 'currency' => 'EUR', 'quote_currency' => 'EUR'];
    private const MANUAL = ['valuation_mode' => 'manual', 'currency' => 'CZK', 'quote_currency' => null];

    private function validator(): TransactionValidator
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-28 12:00:00');
            }
        };
        return new TransactionValidator($clock);
    }

    public function testBuyComputesUnitPriceFromAmount(): void
    {
        $r = $this->validator()->normalize(
            ['type' => 'buy', 'executed_at' => '2026-01-02T10:30', 'quantity' => '3', 'amount' => '100'],
            self::MARKET,
        );
        self::assertSame([], $r['errors']);
        self::assertSame('33.3333333333', $r['data']['unit_price']);
        self::assertSame('100.0000', $r['data']['amount']);
        self::assertSame('EUR', $r['data']['price_currency']);
        self::assertSame('2026-01-02 10:30:00', $r['data']['executed_at']);
    }

    public function testSellComputesAmountFromUnitPrice(): void
    {
        $r = $this->validator()->normalize(
            ['type' => 'sell', 'executed_at' => '2026-01-02', 'quantity' => '0.125', 'unit_price' => '40000.5', 'price_currency' => 'usd'],
            self::MARKET,
        );
        self::assertSame([], $r['errors']);
        self::assertSame('5000.0625', $r['data']['amount']);
        self::assertSame('USD', $r['data']['price_currency']);
    }

    public function testCryptoFeeKeepsFullPrecision(): void
    {
        $r = $this->validator()->normalize(
            ['type' => 'transfer_out', 'executed_at' => '2026-01-02', 'quantity' => '0.1', 'fee' => '0.00005', 'fee_currency' => 'BTC'],
            self::MARKET,
            true,
        );
        self::assertSame([], $r['errors']);
        self::assertSame('0.000050000000000000', $r['data']['fee']);
        self::assertSame('BTC', $r['data']['fee_currency']);

        $usdt = $this->validator()->normalize(
            ['type' => 'buy', 'executed_at' => '2026-01-02', 'quantity' => '1', 'amount' => '1', 'fee' => '0.1', 'fee_currency' => 'usdt'],
            self::MARKET,
        );
        self::assertSame('USDT', $usdt['data']['fee_currency']);
        $bad = $this->validator()->normalize(
            ['type' => 'buy', 'executed_at' => '2026-01-02', 'quantity' => '1', 'amount' => '1', 'fee' => '0.1', 'fee_currency' => 'B-T'],
            self::MARKET,
        );
        self::assertArrayHasKey('fee_currency', $bad['errors']);
        $price = $this->validator()->normalize(
            ['type' => 'buy', 'executed_at' => '2026-01-02', 'quantity' => '1', 'amount' => '1', 'price_currency' => 'USDT'],
            self::MARKET,
        );
        self::assertArrayHasKey('price_currency', $price['errors']);
    }

    public function testRequiredFieldsPerType(): void
    {
        $v = $this->validator();
        self::assertArrayHasKey('amount', $v->normalize(['type' => 'buy', 'executed_at' => '2026-01-02', 'quantity' => '1'], self::MARKET)['errors']);
        self::assertArrayHasKey('quantity', $v->normalize(['type' => 'reward', 'executed_at' => '2026-01-02'], self::MARKET)['errors']);
        self::assertArrayHasKey('split_ratio', $v->normalize(['type' => 'split', 'executed_at' => '2026-01-02'], self::MARKET)['errors']);
        self::assertArrayHasKey('amount', $v->normalize(['type' => 'deposit', 'executed_at' => '2026-01-02', 'amount' => '0'], self::MANUAL)['errors']);
        // Reward bez ceny je v pořádku — hodnotu doplní ceny (M2).
        self::assertSame([], $v->normalize(['type' => 'reward', 'executed_at' => '2026-01-02', 'quantity' => '0.01'], self::MARKET)['errors']);
    }

    public function testTypeMustMatchValuationMode(): void
    {
        $v = $this->validator();
        self::assertArrayHasKey('type', $v->normalize(['type' => 'deposit', 'executed_at' => '2026-01-02', 'amount' => '1'], self::MARKET)['errors']);
        self::assertArrayHasKey('type', $v->normalize(['type' => 'buy', 'executed_at' => '2026-01-02', 'quantity' => '1', 'amount' => '1'], self::MANUAL)['errors']);
        self::assertArrayHasKey('type', $v->normalize(['type' => 'transfer_in', 'executed_at' => '2026-01-02', 'quantity' => '1'], self::MARKET)['errors']);
        self::assertSame([], $v->normalize(['type' => 'transfer_in', 'executed_at' => '2026-01-02', 'quantity' => '1'], self::MARKET, true)['errors']);
    }

    public function testRejectsFutureAndInvalidDatesAndNegativeValues(): void
    {
        $v = $this->validator();
        self::assertSame([], $v->normalize(['type' => 'interest', 'executed_at' => '2026-09-28 23:00', 'amount' => '1'], self::MANUAL)['errors']);
        self::assertArrayHasKey('executed_at', $v->normalize(['type' => 'interest', 'executed_at' => '2026-09-29', 'amount' => '1'], self::MANUAL)['errors']);
        self::assertArrayHasKey('executed_at', $v->normalize(['type' => 'interest', 'executed_at' => '2026-02-30', 'amount' => '1'], self::MANUAL)['errors']);
        self::assertArrayHasKey('fee', $v->normalize(['type' => 'buy', 'executed_at' => '2026-01-02', 'quantity' => '1', 'amount' => '1', 'fee' => '-1'], self::MARKET)['errors']);
        self::assertArrayHasKey('price_currency', $v->normalize(['type' => 'buy', 'executed_at' => '2026-01-02', 'quantity' => '1', 'amount' => '1', 'price_currency' => 'EURO'], self::MARKET)['errors']);
    }

    public function testAmountTypesDropQuantityAndSplitDropsMoney(): void
    {
        $v = $this->validator();
        $dividend = $v->normalize(['type' => 'dividend', 'executed_at' => '2026-01-02', 'amount' => '12', 'quantity' => '5'], self::MARKET);
        self::assertNull($dividend['data']['quantity']);
        $split = $v->normalize(['type' => 'split', 'executed_at' => '2026-01-02', 'split_ratio' => '10', 'amount' => '5'], self::MARKET);
        self::assertSame([], $split['errors']);
        self::assertNull($split['data']['amount']);
        self::assertNull($split['data']['price_currency']);
        self::assertSame('10.0000000000', $split['data']['split_ratio']);
    }
}
