<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

/**
 * Porovnání čísla účtu z avíza s číslem uloženým u pozice. Ignoruje pomlčku předčíslí
 * a úvodní nuly; Moneta posílá maskované číslo (`238***891`) — `*` zastupuje jednu číslici
 * a délka musí sedět. Kód banky se porovná, když ho mají obě strany.
 */
final class AccountNumber
{
    public static function matches(string $notice, string $position): bool
    {
        [$noticeNumber, $noticeBank] = self::split($notice);
        [$positionNumber, $positionBank] = self::split($position);
        if ($noticeNumber === '' || $positionNumber === '' || str_contains($positionNumber, '*')) {
            return false;
        }
        if ($noticeBank !== null && $positionBank !== null && $noticeBank !== $positionBank) {
            return false;
        }
        if (!str_contains($noticeNumber, '*')) {
            return ltrim($noticeNumber, '0') === ltrim($positionNumber, '0');
        }
        if (strlen($noticeNumber) !== strlen($positionNumber)) {
            return false;
        }
        for ($i = 0, $len = strlen($noticeNumber); $i < $len; $i++) {
            if ($noticeNumber[$i] !== '*' && $noticeNumber[$i] !== $positionNumber[$i]) {
                return false;
            }
        }
        return true;
    }

    /** Kanonický zápis pro uložení: `[předčíslí-]číslo/kód` bez mezer, nebo null. */
    public static function normalize(?string $value): ?string
    {
        $value = preg_replace('/\s+/', '', (string) $value) ?? '';
        if ($value === '') {
            return null;
        }
        return preg_match('/^(?:\d{1,6}-)?\d{2,10}\/\d{4}$/', $value) === 1 ? $value : throw new \InvalidArgumentException('Číslo účtu zadej ve tvaru 123456789/0800.');
    }

    /** @return array{0:string,1:?string} číslice (a `*`) bez předčíslí-pomlčky, kód banky */
    private static function split(string $value): array
    {
        $parts = explode('/', trim($value), 2);
        $number = preg_replace('/[^0-9*]/', '', $parts[0]) ?? '';
        $bank = isset($parts[1]) && preg_match('/^\d{4}$/', trim($parts[1])) === 1 ? trim($parts[1]) : null;
        return [$number, $bank];
    }
}
