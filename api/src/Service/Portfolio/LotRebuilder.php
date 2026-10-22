<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Portfolio;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Repository\LotRepository;
use MyPortfolio\Repository\PriceRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Service\Fx\FxRateMissing;
use MyPortfolio\Service\Fx\FxService;
use MyPortfolio\Support\Decimal;

/**
 * Přepočítá FIFO loty jednoho instrumentu uživatele z transakcí (všech jeho pozic)
 * a přepíše `tax_lots` / `lot_disposals`. Volá se v DB transakci TransactionService.
 */
final class LotRebuilder
{
    private const REWARD_PRICE_MAX_AGE_DAYS = 7;

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly LotRepository $lots,
        private readonly InstrumentRepository $instruments,
        private readonly PriceRepository $prices,
        private readonly FxService $fx,
        private readonly LotEngine $engine,
    ) {}

    /** @return list<string> upozornění enginu */
    public function rebuild(int $userId, int $instrumentId): array
    {
        $instrument = $this->instruments->find($instrumentId);
        if ($instrument === null) {
            return [];
        }
        $result = $this->compute($this->transactions->forInstrument($userId, $instrumentId), $instrument);

        $this->lots->deleteForInstrument($userId, $instrumentId);
        $ids = [];
        foreach ($result['lots'] as $lot) {
            $ids[$lot['key']] = $this->lots->insertLot($userId, $instrumentId, [
                'position_id'           => $lot['position_id'],
                'source_transaction_id' => $lot['source_transaction_id'],
                'origin_lot_id'         => $lot['origin_key'] !== null ? $ids[$lot['origin_key']] : null,
                'acquired_at'           => $lot['acquired_at'],
                'quantity'              => Decimal::toDb($lot['quantity'], Decimal::SCALE_QUANTITY),
                'remaining_quantity'    => Decimal::toDb($lot['remaining'], Decimal::SCALE_QUANTITY),
                'cost_amount'           => Decimal::toDb($lot['cost'], Decimal::SCALE_PRICE),
                'cost_currency'         => $lot['cost_currency'],
            ]);
        }
        foreach ($result['disposals'] as $d) {
            $this->lots->insertDisposal($userId, [
                'lot_id'            => $ids[$d['lot_key']],
                'transaction_id'    => $d['transaction_id'],
                'kind'              => $d['kind'],
                'disposed_at'       => $d['disposed_at'],
                'quantity'          => Decimal::toDb($d['quantity'], Decimal::SCALE_QUANTITY),
                'cost_amount'       => Decimal::toDb($d['cost'], Decimal::SCALE_PRICE),
                'cost_currency'     => $d['cost_currency'],
                'proceeds_amount'   => Decimal::toDb($d['proceeds'], Decimal::SCALE_PRICE),
                'proceeds_currency' => $d['proceeds_currency'],
            ]);
        }
        return $result['warnings'];
    }

    /**
     * Spustí FIFO engine nad transakcemi instrumentu (i nad prefixem — historie v M4).
     *
     * @param list<array<string,mixed>> $transactions
     * @param array<string,mixed> $instrument
     * @return array{lots:list<array<string,mixed>>,disposals:list<array<string,mixed>>,warnings:list<string>}
     */
    public function compute(array $transactions, array $instrument): array
    {
        return $this->engine->run(
            $transactions,
            (string) $instrument['symbol'],
            $this->convert(...),
            fn (array $tx) => $this->rewardValue((int) $instrument['id'], $tx),
        );
    }

    private function convert(BigDecimal $amount, string $from, string $to, string $date): ?BigDecimal
    {
        if (preg_match('/^[A-Z]{3}$/', $from) !== 1) {
            return null; // poplatek v jiném kryptu
        }
        try {
            return $this->fx->convert($amount, $from, $to, $date);
        } catch (FxRateMissing) {
            return null;
        }
    }

    /**
     * Hodnota odměny bez `amount`: cena ke dni připsání × množství.
     *
     * @param array<string,mixed> $tx
     * @return array{0:BigDecimal,1:string}|null
     */
    private function rewardValue(int $instrumentId, array $tx): ?array
    {
        $date = substr((string) $tx['executed_at'], 0, 10);
        $notBefore = (new \DateTimeImmutable($date))->modify('-' . self::REWARD_PRICE_MAX_AGE_DAYS . ' days')->format('Y-m-d');
        $price = $this->prices->onOrBefore($instrumentId, $date, $notBefore);
        if ($price === null) {
            return null;
        }
        $value = BigDecimal::of($price['close'])->multipliedBy((string) $tx['quantity'])
            ->toScale(Decimal::SCALE_PRICE, RoundingMode::HalfUp);
        return [$value, $price['currency']];
    }
}
