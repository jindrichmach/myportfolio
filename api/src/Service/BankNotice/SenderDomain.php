<?php

declare(strict_types=1);

namespace MyPortfolio\Service\BankNotice;

/**
 * End-anchored match domény odesílatele pro systémové parsery.
 *
 * `str_contains($sender, '@csob.cz')` pustí i `attacker@csob.cz.evil.com` —
 * tady se doména kontroluje na konci adresy (vč. subdomén, např. noreply@mail.csob.cz).
 * Sender je samozřejmě spoofnutelný (žádná SPF/DKIM validace na této vrstvě);
 * jde jen o routing na správný parser; autenticitu ověřuje EmailAuthenticationVerifier.
 */
final class SenderDomain
{
    public static function matches(string $sender, string ...$domains): bool
    {
        $address = strtolower(trim($sender));
        // "Display Name <addr@domain>" → vezmi adresu z posledních <>
        if (preg_match('/<([^<>]+)>\s*$/', $address, $m) === 1) {
            $address = trim($m[1]);
        }
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }
        $host = rtrim(substr($address, $at + 1), '.');
        if ($host === '') {
            return false;
        }
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }
        return false;
    }
}
