<?php

declare(strict_types=1);

namespace MyPortfolio\Support;

/**
 * Validace polí požadavku — plní pole chyb ve tvaru `fields` pro Json::error().
 */
final class Input
{
    private function __construct() {}

    /**
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     */
    public static function string(array $body, string $key, int $max, bool $required, array &$errors): ?string
    {
        $value = trim((string) ($body[$key] ?? ''));
        if ($value === '') {
            if ($required) {
                $errors[$key][] = 'Povinné pole.';
            }
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors[$key][] = sprintf('Nejvýše %d znaků.', $max);
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     */
    public static function currency(array $body, string $key, bool $required, array &$errors, ?string $default = null): ?string
    {
        $value = strtoupper(trim((string) ($body[$key] ?? '')));
        if ($value === '') {
            if ($required && $default === null) {
                $errors[$key][] = 'Povinné pole.';
            }
            return $default;
        }
        if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            $errors[$key][] = 'Měna musí být třípísmenný kód (např. CZK).';
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,list<string>> $errors
     */
    public static function id(array $body, string $key, bool $required, array &$errors): ?int
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$key][] = 'Povinné pole.';
            }
            return null;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            $errors[$key][] = 'Neplatné ID.';
            return null;
        }
        return (int) $value;
    }

    /**
     * @param array<string,mixed> $body
     * @param list<string> $allowed
     * @param array<string,list<string>> $errors
     */
    public static function enum(array $body, string $key, array $allowed, array &$errors, ?string $default = null): ?string
    {
        $value = (string) ($body[$key] ?? $default ?? '');
        if (!in_array($value, $allowed, true)) {
            $errors[$key][] = 'Neplatná hodnota.';
            return null;
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    public static function bool(array $body, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $body)) {
            return $default;
        }
        return filter_var($body[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string,list<string>> $errors
     * @throws \MyPortfolio\Service\Portfolio\DomainError
     */
    public static function assertValid(array $errors): void
    {
        if ($errors !== []) {
            throw \MyPortfolio\Service\Portfolio\DomainError::validation($errors);
        }
    }
}
