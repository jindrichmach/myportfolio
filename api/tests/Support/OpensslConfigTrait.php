<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Support;

/**
 * Pomoc pro testy, které generují RSA klíč / certifikát (DKIM, S/MIME, PDF podpis).
 *
 * openssl extension potřebuje pro `openssl_pkey_new()` / `openssl_csr_*` platný
 * `openssl.cnf`. Na holém Windows PHP bez `OPENSSL_CONF` (a samotné nastavení té
 * env proměnné nestačí — PHP build ji ignoruje) generování vrací `false`. Config
 * si proto dohledáme sami a předáme ho openssl voláním přes `['config' => …]`, ať
 * testy běží lokálně (Windows) i v CI/Dockeru (Linux, kde je config v defaultu a
 * `opensslConfigArgs()` vrátí prázdné pole).
 */
trait OpensslConfigTrait
{
    /**
     * Argumenty pro openssl_* volání: `['config' => cesta]` když najdeme openssl.cnf,
     * jinak prázdné pole (prostředí má config v defaultu, např. Linux/CI).
     *
     * Použití: `openssl_pkey_new([...] + $this->opensslConfigArgs())`.
     *
     * @return array{config?:string}
     */
    protected static function opensslConfigArgs(): array
    {
        foreach (self::opensslConfigCandidates() as $cnf) {
            if ($cnf !== '' && is_file($cnf)) {
                return ['config' => $cnf];
            }
        }
        return [];
    }

    /**
     * @return list<string>
     */
    private static function opensslConfigCandidates(): array
    {
        $candidates = [];
        $env = getenv('OPENSSL_CONF');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            // PHP Windows build: <php>/extras/ssl/openssl.cnf
            $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras'
                . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        }
        return $candidates;
    }

    /**
     * Nashromážděné chyby z openssl fronty (pro srozumitelné assert hlášky).
     */
    protected static function opensslErrors(): string
    {
        $errors = [];
        while (($e = openssl_error_string()) !== false) {
            $errors[] = $e;
        }
        return implode('; ', $errors);
    }
}
