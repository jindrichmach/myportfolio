<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Portfolio;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use MyPortfolio\Support\Decimal;
use Psr\Clock\ClockInterface;

/**
 * Validace a normalizace vstupu transakce podle typu a režimu pozice.
 *
 * Výstup `normalize()` jsou hodnoty připravené pro DB (DECIMAL jako string),
 * nebo pole chyb per pole. Převody (transfer_in/out) se zakládají jen přes
 * TransactionService::transfer() — obecné create je odmítne.
 */
final class TransactionValidator
{
    /** @var array<string,list<string>> povolené typy per valuation_mode */
    public const TYPES = [
        'market' => ['buy', 'sell', 'reward', 'split', 'dividend', 'interest', 'fee'],
        'manual' => ['deposit', 'withdrawal', 'dividend', 'interest', 'fee'],
    ];

    private const AMOUNT_TYPES = ['deposit', 'withdrawal', 'dividend', 'interest', 'fee'];

    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @param array<string,mixed> $input
     * @param array{valuation_mode:string,currency:string,quote_currency?:?string} $position
     * @return array{data:array<string,?string>,errors:array<string,list<string>>}
     */
    public function normalize(array $input, array $position, bool $allowTransfer = false): array
    {
        $errors = [];
        $mode = $position['valuation_mode'];
        $type = (string) ($input['type'] ?? '');

        $allowed = self::TYPES[$mode] ?? [];
        if ($allowTransfer && $mode === 'market') {
            $allowed = [...$allowed, 'transfer_in', 'transfer_out'];
        }
        if (!in_array($type, $allowed, true)) {
            $errors['type'][] = in_array($type, ['transfer_in', 'transfer_out'], true)
                ? 'Převod zakládej přes /api/transfers.'
                : 'Typ transakce není pro tuto pozici povolený.';
        }

        $executedAt = $this->parseDateTime($input['executed_at'] ?? null);
        if ($executedAt === null) {
            $errors['executed_at'][] = 'Neplatné datum.';
        } elseif ($executedAt > $this->endOfToday()) {
            $errors['executed_at'][] = 'Datum nesmí být v budoucnosti.';
        }

        $quantity  = $this->number($input, 'quantity', Decimal::SCALE_QUANTITY, $errors);
        $unitPrice = $this->number($input, 'unit_price', Decimal::SCALE_PRICE, $errors);
        $amount    = $this->number($input, 'amount', Decimal::SCALE_AMOUNT, $errors);
        $fee       = $this->number($input, 'fee', Decimal::SCALE_FEE, $errors);
        $ratio     = $this->number($input, 'split_ratio', Decimal::SCALE_PRICE, $errors);

        $defaultCurrency = $mode === 'market'
            ? (string) ($position['quote_currency'] ?? $position['currency'])
            : $position['currency'];
        $priceCurrency = $this->currency($input['price_currency'] ?? null, $defaultCurrency, 'price_currency', $errors);
        $feeCurrency   = $this->currency($input['fee_currency'] ?? null, $priceCurrency, 'fee_currency', $errors, true);

        foreach (['quantity' => $quantity, 'unit_price' => $unitPrice, 'amount' => $amount, 'fee' => $fee] as $field => $value) {
            if ($value !== null && $value->isNegative()) {
                $errors[$field][] = 'Hodnota nesmí být záporná.';
            }
        }

        if (in_array($type, ['buy', 'sell', 'reward', 'transfer_in', 'transfer_out'], true)) {
            if ($quantity === null || !$quantity->isPositive()) {
                $errors['quantity'][] = 'Množství musí být větší než 0.';
            }
            if (in_array($type, ['buy', 'sell'], true) && $amount === null && $unitPrice === null) {
                $errors['amount'][] = 'Zadej celkovou částku nebo cenu za kus.';
            }
            if ($quantity !== null && $quantity->isPositive()) {
                if ($amount === null && $unitPrice !== null) {
                    $amount = $unitPrice->multipliedBy($quantity)->toScale(Decimal::SCALE_AMOUNT, RoundingMode::HalfUp);
                } elseif ($unitPrice === null && $amount !== null) {
                    $unitPrice = $amount->dividedBy($quantity, Decimal::SCALE_PRICE, RoundingMode::HalfUp);
                }
            }
        } elseif (in_array($type, self::AMOUNT_TYPES, true)) {
            if ($amount === null || !$amount->isPositive()) {
                $errors['amount'][] = 'Částka musí být větší než 0.';
            }
            $quantity = null;
            $unitPrice = null;
        } elseif ($type === 'split') {
            if ($ratio === null || !$ratio->isPositive()) {
                $errors['split_ratio'][] = 'Poměr splitu musí být větší než 0.';
            }
            $quantity = $unitPrice = $amount = $fee = null;
        }
        if ($type !== 'split') {
            $ratio = null;
        }

        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 500) {
            $errors['note'][] = 'Poznámka může mít nejvýše 500 znaků.';
        }

        return [
            'data' => [
                'type'           => $type,
                'executed_at'    => $executedAt?->format('Y-m-d H:i:s'),
                'quantity'       => Decimal::toDb($quantity, Decimal::SCALE_QUANTITY),
                'unit_price'     => Decimal::toDb($unitPrice, Decimal::SCALE_PRICE),
                'price_currency' => $type === 'split' ? null : $priceCurrency,
                'amount'         => Decimal::toDb($amount, Decimal::SCALE_AMOUNT),
                'fee'            => Decimal::toDb($fee, Decimal::SCALE_FEE),
                'fee_currency'   => $fee !== null ? $feeCurrency : null,
                'split_ratio'    => Decimal::toDb($ratio, Decimal::SCALE_PRICE),
                'note'           => $note !== '' ? $note : null,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,list<string>> $errors
     */
    private function number(array $input, string $field, int $scale, array &$errors): ?BigDecimal
    {
        try {
            return Decimal::parse($input[$field] ?? null, $scale);
        } catch (\InvalidArgumentException $e) {
            $errors[$field][] = $e->getMessage();
            return null;
        }
    }

    /**
     * Měna poplatku smí být i symbol kryptoměny (BTC, USDT, …) — `$allowSymbol`.
     *
     * @param array<string,list<string>> $errors
     */
    private function currency(mixed $value, string $default, string $field, array &$errors, bool $allowSymbol = false): string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $code = strtoupper(trim((string) $value));
        if ($allowSymbol && preg_match('/^[A-Z0-9]{2,10}$/', $code) === 1) {
            return $code;
        }
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            $errors[$field][] = $allowSymbol
                ? 'Měna poplatku musí být kód měny nebo symbol (např. CZK, BTC).'
                : 'Měna musí být třípísmenný kód (např. CZK).';
            return $default;
        }
        return $code;
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $tz = new \DateTimeZone(date_default_timezone_get());
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d\TH:i', '!Y-m-d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, trim($value), $tz);
            $warnings = \DateTimeImmutable::getLastErrors();
            if ($parsed !== false && ($warnings === false || $warnings['warning_count'] === 0)) {
                return $parsed;
            }
        }
        return null;
    }

    private function endOfToday(): \DateTimeImmutable
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime(23, 59, 59);
    }
}
