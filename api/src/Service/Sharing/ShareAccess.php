<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Sharing;

/**
 * Allowlist cest dostupných v kontextu vlastníka (`X-Owner`): metoda + cesta → sekce a zda jde o zápis.
 * Co tu není, v cizím kontextu nejde (403). Zápis = transakce, ruční ocenění / zůstatky, pozice, importy.
 */
final class ShareAccess
{
    public const SECTIONS = ['net_worth', 'tax', 'income'];

    /**
     * Cesty, které hlavičku ignorují: vlastní účet přihlášeného (nastavení, přihlášení, sdílení, cenové hranice,
     * bankovní avíza) a globální číselníky (instituce, instrumenty, ceny, kurzy, ikony) — ty nepatří žádnému uživateli.
     */
    private const PERSONAL_PREFIXES = ['/api/auth/', '/api/settings/', '/api/shares', '/api/household', '/api/admin/', '/api/public/',
        '/api/price-alerts', '/api/bank-notices', '/api/icons/',
        '/api/institutions', '/api/instruments', '/api/prices', '/api/fx-rates', '/api/catalog', '/api/health', '/api/version',
        '/api/api-catalog', '/api/openapi.json'];

    /** @var list<array{0:string,1:string,2:?string,3:bool}> metoda, regex cesty, sekce (null = základ), zápis */
    private const RULES = [
        ['GET',    '#^/api/dashboard$#', null, false],
        ['GET',    '#^/api/history$#', null, false],
        ['GET',    '#^/api/summary$#', null, false],
        ['GET',    '#^/api/allocation$#', null, false],
        ['GET',    '#^/api/(accounts|portfolios|positions|transactions)(/[0-9]+)?$#', null, false],
        ['GET',    '#^/api/positions/[0-9]+/(valuation|valuations)$#', null, false],
        ['GET',    '#^/api/imports(/sources)?$#', null, false],
        ['GET',    '#^/api/imports/preview/[0-9a-f]{32}$#', null, true],
        ['POST',   '#^/api/positions$#', null, true],
        ['PUT',    '#^/api/positions/[0-9]+$#', null, true],
        ['POST',   '#^/api/transactions$#', null, true],
        ['PUT',    '#^/api/transactions/[0-9]+$#', null, true],
        ['DELETE', '#^/api/transactions/[0-9]+$#', null, true],
        ['POST',   '#^/api/transfers$#', null, true],
        ['POST',   '#^/api/positions/[0-9]+/(valuations|cash-balance)$#', null, true],
        ['DELETE', '#^/api/valuations/[0-9]+$#', null, true],
        ['POST',   '#^/api/imports/preview$#', null, true],
        ['POST',   '#^/api/imports/preview/[0-9a-f]{32}/confirm$#', null, true],
        ['POST',   '#^/api/imports/[0-9]+/revert$#', null, true],
        ['GET',    '#^/api/net-worth(/history|/items/[0-9]+/entries)?$#', 'net_worth', false],
        ['GET',    '#^/api/tax-report(/years|/export\.csv|/filing(\.csv)?)?$#', 'tax', false],
        ['GET',    '#^/api/income$#', 'income', false],
    ];

    public static function isPersonal(string $path): bool
    {
        foreach (self::PERSONAL_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{section:?string,write:bool}|null null = v cizím kontextu nedostupné */
    public static function rule(string $method, string $path): ?array
    {
        $method = in_array(strtoupper($method), ['HEAD', 'OPTIONS'], true) ? 'GET' : strtoupper($method);
        foreach (self::RULES as [$m, $regex, $section, $write]) {
            if ($m === $method && preg_match($regex, $path) === 1) {
                return ['section' => $section, 'write' => $write];
            }
        }
        return null;
    }
}
