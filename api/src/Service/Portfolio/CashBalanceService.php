<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Portfolio;

use Brick\Math\BigDecimal;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Support\Decimal;

/**
 * Hotovost na účtu: uživatel zadává zůstatek, rozdíl proti dosavadnímu
 * zůstatku se zapíše jako vklad / výběr. Hodnota i investováno pak odpovídají zůstatku a hotovost nemá výnos —
 * změna zůstatku je tok peněz, ne zisk (historie, souhrny, TWR).
 */
final class CashBalanceService
{
    private const SIGNS = ['deposit' => 1, 'interest' => 1, 'dividend' => 1, 'withdrawal' => -1, 'fee' => -1];

    public function __construct(
        private readonly PositionRepository $positions,
        private readonly TransactionRepository $transactions,
        private readonly TransactionService $transactionService,
    ) {}

    /**
     * @return array{balance:string,previous:string,delta:string,transaction_id:?int}
     */
    public function set(int $userId, int $positionId, string $date, mixed $balanceInput, ?string $note): array
    {
        $position = $this->positions->find($userId, $positionId) ?? throw DomainError::notFound('Pozice nenalezena.');
        if (!$position['is_cash']) {
            throw new DomainError('not_cash', 'Zůstatek jde nastavit jen u hotovosti.', 422);
        }
        $errors = [];
        try {
            $balance = Decimal::parse($balanceInput, Decimal::SCALE_AMOUNT);
            if ($balance === null || $balance->isNegative()) {
                $errors['balance'][] = 'Zadej zůstatek (0 nebo víc).';
            }
        } catch (\InvalidArgumentException $e) {
            $balance = null;
            $errors['balance'][] = $e->getMessage();
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            $errors['date'][] = 'Datum ve tvaru RRRR-MM-DD.';
        }

        [$previous, $lastDate] = $this->current($userId, $position);
        if ($day !== false && $lastDate !== null && $date < $lastDate) {
            $errors['date'][] = "Zůstatek jde nastavit nejdřív k poslednímu pohybu ($lastDate).";
        }
        if ($errors !== []) {
            throw DomainError::validation($errors);
        }

        $delta = $balance->minus($previous);
        $id = null;
        if (!$delta->isZero()) {
            $text = 'Zůstatek ' . $balance->strippedOfTrailingZeros() . ' ' . $position['currency'];
            $id = $this->transactionService->create($userId, $positionId, [
                'type'           => $delta->isPositive() ? 'deposit' : 'withdrawal',
                'executed_at'    => $date . ' 23:59:59',
                'amount'         => (string) $delta->abs()->strippedOfTrailingZeros(),
                'price_currency' => $position['currency'],
                'note'           => $note !== null && $note !== '' ? mb_substr($text . ' — ' . $note, 0, 500) : $text,
            ]);
        }
        return ['balance' => (string) $balance->strippedOfTrailingZeros(), 'previous' => (string) $previous->strippedOfTrailingZeros(),
            'delta' => (string) $delta->strippedOfTrailingZeros(), 'transaction_id' => $id];
    }

    /**
     * Dosavadní zůstatek (součet toků v měně pozice) a den posledního pohybu.
     *
     * @param array<string,mixed> $position
     * @return array{0:BigDecimal,1:?string}
     */
    private function current(int $userId, array $position): array
    {
        $balance = BigDecimal::zero();
        $last = null;
        foreach ($this->transactions->cashFlows($userId, (int) $position['id']) as $t) {
            $sign = self::SIGNS[$t['type']] ?? 0;
            if ($sign === 0 || $t['amount'] === null) {
                continue;
            }
            if (($t['price_currency'] ?? $position['currency']) !== $position['currency']) {
                throw new DomainError('currency_mismatch', 'Hotovost obsahuje pohyby v jiné měně — zůstatek uprav transakcemi.', 422);
            }
            $amount = BigDecimal::of((string) $t['amount']);
            $balance = $sign === 1 ? $balance->plus($amount) : $balance->minus($amount);
            $last = substr((string) $t['executed_at'], 0, 10);
        }
        return [$balance, $last];
    }
}
