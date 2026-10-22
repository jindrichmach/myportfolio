<?php

declare(strict_types=1);

namespace MyPortfolio\Api;

/**
 * Katalog endpointů dostupných přes API token — jediný zdroj pravdy pro:
 *   - oprávnění tokenu (ApiScopeMiddleware: metoda + cesta, potřebný rozsah),
 *   - stránku Profil → API (GET /api/api-catalog),
 *   - OpenAPI 3.1 (GET /api/openapi.json).
 * Nový endpoint pro token se přidává vědomě sem; /api/admin/*, správa tokenů a nastavení účtu sem nepatří
 * (hlídá ApiCatalogTest). Texty cs + en.
 */
final class ApiCatalog
{
    public const SCOPE_READ = 'read';
    public const SCOPE_WRITE = 'read_write';

    /** Typy cestových parametrů → regex (shodně s Routes.php). */
    private const PATH_PARAMS = ['id' => '[0-9]+', 'token' => '[0-9a-f]{32}'];

    private const GROUPS = [
        'system'       => ['Systém', 'System'],
        'catalog'      => ['Katalog', 'Catalog'],
        'market'       => ['Ceny a kurzy', 'Prices and rates'],
        'accounts'     => ['Účty', 'Accounts'],
        'portfolios'   => ['Portfolia', 'Portfolios'],
        'positions'    => ['Pozice', 'Positions'],
        'transactions' => ['Transakce', 'Transactions'],
        'valuations'   => ['Ruční ocenění a hotovost', 'Manual valuations and cash'],
        'overview'     => ['Přehledy', 'Overviews'],
        'tax'          => ['Daňový report', 'Tax report'],
        'imports'      => ['Importy', 'Imports'],
        'alerts'       => ['Cenové hranice', 'Price alerts'],
    ];

    /** @var list<array<string,mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string,array{0:string,1:string}> skupina → [cs, en] */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    /**
     * Endpoint pro metodu a skutečnou cestu požadavku (HEAD / OPTIONS jako GET), jinak null.
     *
     * @return array<string,mixed>|null
     */
    public static function match(string $method, string $path): ?array
    {
        $method = in_array(strtoupper($method), ['HEAD', 'OPTIONS'], true) ? 'GET' : strtoupper($method);
        foreach (self::endpoints() as $e) {
            if ($e['method'] === $method && preg_match(self::regex($e['path']), $path) === 1) {
                return $e;
            }
        }
        return null;
    }

    /** `/api/positions/{id}` → `#^/api/positions/[0-9]+$#` */
    public static function regex(string $path): string
    {
        $quoted = preg_quote($path, '#');
        $quoted = (string) preg_replace_callback('/\\\\\{(\w+)\\\\\}/', static fn (array $m) => self::PATH_PARAMS[$m[1]] ?? '[^/]+', $quoted);
        return '#^' . $quoted . '$#';
    }

    /**
     * Endpointy s texty v jednom jazyce (pro stránku).
     *
     * @return list<array<string,mixed>>
     */
    public static function localized(string $locale): array
    {
        $i = $locale === 'en' ? 1 : 0;
        return array_map(static function (array $e) use ($i): array {
            $e['group_label'] = self::GROUPS[$e['group']][$i];
            $e['summary'] = $e['summary'][$i];
            $e['description'] = $e['description'] !== null ? $e['description'][$i] : null;
            $e['response']['description'] = $e['response']['description'][$i];
            $e['params'] = array_map(static fn (array $p) => ['description' => $p['description'][$i]] + $p, $e['params']);
            return $e;
        }, self::endpoints());
    }

    /**
     * Všechny endpointy dostupné tokenem.
     *
     * @return list<array{id:string,group:string,method:string,path:string,scope:string,summary:array{0:string,1:string},
     *   description:array{0:string,1:string}|null,params:list<array<string,mixed>>,response:array<string,mixed>,content_type:string}>
     */
    public static function endpoints(): array
    {
        return self::$cache ??= self::build();
    }

    /** @return list<array<string,mixed>> */
    private static function build(): array
    {
        $R = self::SCOPE_READ;
        $W = self::SCOPE_WRITE;
        $id = self::p('id', 'path', 'integer', true, 'ID záznamu', 'Record ID');
        $date = static fn (string $name, bool $req, string $cs, string $en) => self::p($name, 'query', 'date', $req, $cs, $en, ['example' => '2026-01-31']);
        $money = static fn (string $name, bool $req, string $cs, string $en, array $x = []) => self::p($name, 'body', 'decimal', $req, $cs, $en, $x);
        $note = self::p('note', 'body', 'string', false, 'Poznámka (max. 500 znaků)', 'Note (max 500 characters)');
        $ok = static fn (string $cs, string $en, mixed $example = null) => ['status' => 200, 'description' => [$cs, $en], 'example' => $example];
        $created = static fn (string $cs, string $en, mixed $example = null) => ['status' => 201, 'description' => [$cs, $en], 'example' => $example];
        $deleted = ['status' => 204, 'description' => ['Smazáno, prázdná odpověď.', 'Deleted, empty response.'], 'example' => null];

        $accountBody = [
            self::p('institution_id', 'body', 'integer', true, 'Instituce (aktivní)', 'Institution (active)'),
            self::p('name', 'body', 'string', true, 'Název účtu, max. 120 znaků; unikátní u instituce', 'Account name, max 120; unique within institution', ['example' => 'Hlavní']),
            self::p('currency', 'body', 'currency', false, 'Měna účtu', 'Account currency', ['default' => 'CZK']),
            self::p('note', 'body', 'string', false, 'Poznámka, max. 500 znaků', 'Note, max 500 characters'),
        ];
        $portfolioBody = [
            self::p('name', 'body', 'string', true, 'Název, max. 120 znaků, unikátní', 'Name, max 120, unique', ['example' => 'Dlouhodobé']),
            self::p('sort', 'body', 'integer', false, 'Pořadí v seznamech', 'Sort order', ['default' => 0]),
        ];
        $archived = self::p('archived', 'body', 'boolean', false, 'Archivovat (PUT nahrazuje celý záznam — vynechání = false)', 'Archive (PUT replaces the record — omitted = false)', ['default' => false]);
        $txBody = [
            self::p('type', 'body', 'string', true,
                'Typ. Tržní pozice: buy, sell, reward, split, dividend, interest, fee; ruční: deposit, withdrawal, dividend, interest, fee. Převod přes POST /api/transfers.',
                'Type. Market position: buy, sell, reward, split, dividend, interest, fee; manual: deposit, withdrawal, dividend, interest, fee. Transfers via POST /api/transfers.',
                ['enum' => ['buy', 'sell', 'reward', 'split', 'deposit', 'withdrawal', 'dividend', 'interest', 'fee']]),
            self::p('executed_at', 'body', 'datetime', true, 'Datum a čas (Y-m-d H:i:s, Y-m-d H:i nebo Y-m-d), ne v budoucnosti', 'Date and time (Y-m-d H:i:s, Y-m-d H:i or Y-m-d), not in the future', ['example' => '2026-01-15 10:30']),
            $money('quantity', false, 'Množství (buy, sell, reward — povinné, > 0), max. 18 des. míst', 'Quantity (buy, sell, reward — required, > 0), max 18 decimals', ['example' => '0.015']),
            $money('unit_price', false, 'Cena za kus; u buy/sell zadej unit_price nebo amount, druhé se dopočítá', 'Unit price; for buy/sell give unit_price or amount, the other is derived'),
            $money('amount', false, 'Částka bez poplatku (deposit, withdrawal, dividend, interest, fee — povinná, > 0); u dividendy hrubá', 'Amount without fee (deposit, withdrawal, dividend, interest, fee — required, > 0); gross for dividends', ['example' => '1250.50']),
            self::p('price_currency', 'body', 'currency', false, 'Měna ceny / částky; výchozí měna instrumentu nebo pozice', 'Price / amount currency; defaults to instrument or position currency'),
            $money('fee', false, 'Poplatek (u dividendy sražená daň)', 'Fee (withholding tax for dividends)'),
            self::p('fee_currency', 'body', 'string', false, 'Měna poplatku — 3 písmena nebo symbol kryptoměny (BTC); výchozí price_currency', 'Fee currency — 3 letters or a crypto symbol (BTC); defaults to price_currency'),
            $money('split_ratio', false, 'Poměr splitu (split — povinný, > 0), např. 10 = 1 kus → 10', 'Split ratio (split — required, > 0), e.g. 10 = 1 share → 10'),
            $note,
        ];
        $alertBody = [
            self::p('instrument_id', 'body', 'integer', true, 'Instrument', 'Instrument'),
            self::p('direction', 'body', 'string', true, 'Ohlásit, když cena vystoupí nad / klesne pod hranici', 'Report when the price rises above / falls below', ['enum' => ['above', 'below']]),
            $money('price', true, 'Hranice (> 0), max. 10 des. míst', 'Threshold (> 0), max 10 decimals', ['example' => '2000000']),
            self::p('currency', 'body', 'currency', false, 'Měna hranice; výchozí měna kotace instrumentu', 'Threshold currency; defaults to the instrument quote currency'),
            self::p('note', 'body', 'string', false, 'Poznámka, max. 255 znaků', 'Note, max 255 characters'),
        ];
        $taxQuery = [
            self::p('year', 'query', 'integer', true, 'Zdaňovací rok (2000 – letošek)', 'Tax year (2000 – current)', ['example' => 2025]),
            self::p('rate', 'query', 'string', false, 'Přepočet: denní kurz ČNB, nebo jednotný kurz', 'Conversion: daily CNB rate or unified annual rate', ['enum' => ['daily', 'unified'], 'default' => 'daily']),
        ];

        $e = static fn (...$a) => self::e(...$a);
        return [
            // ── Systém ─────────────────────────────────────────────────────
            $e('health', 'system', 'GET', '/api/health', $R, 'Stav aplikace', 'Application health', [], $ok('Stav, verze, dostupnost DB a Redisu.', 'Status, version, DB and Redis availability.', ['status' => 'ok', 'version' => '0.3.0-dev', 'db' => true, 'redis' => false])),
            $e('version', 'system', 'GET', '/api/version', $R, 'Verze aplikace', 'Application version', [], $ok('Aktuální verze.', 'Current version.', ['current' => '0.3.0-dev'])),
            $e('api_me', 'system', 'GET', '/api/auth/api-me', $R, 'Ověření tokenu', 'Token check', [], $ok('Uživatel a token, kterým je požadavek ověřený.', 'User and token that authenticated the request.',
                ['user' => ['id' => 1, 'email' => 'user@example.invalid', 'name' => 'Jan', 'role' => 'user'], 'auth_method' => 'bearer', 'token' => ['id' => 3, 'name' => 'Home Assistant', 'prefix' => 'mp_ab12', 'scope' => 'read', 'expires_at' => null]])),
            $e('openapi', 'system', 'GET', '/api/openapi.json', $R, 'Specifikace OpenAPI', 'OpenAPI specification', [], $ok('OpenAPI 3.1 všech endpointů z tohoto seznamu (Swagger, Postman).', 'OpenAPI 3.1 of all endpoints in this list (Swagger, Postman).')),

            // ── Katalog ────────────────────────────────────────────────────
            $e('institutions_list', 'catalog', 'GET', '/api/institutions', $R, 'Seznam institucí', 'List institutions', [
                self::p('all', 'query', 'string', false, '„1" = i neaktivní', '"1" = include inactive', ['enum' => ['1']]),
            ], $ok('Pole institucí seřazené podle názvu.', 'Array of institutions ordered by name.', [['id' => 1, 'name' => 'XTB', 'type' => 'broker', 'website' => 'https://www.xtb.com', 'is_active' => true]])),
            $e('institutions_create', 'catalog', 'POST', '/api/institutions', $W, 'Založit instituci', 'Create institution', [
                self::p('name', 'body', 'string', true, 'Název, max. 120 znaků, unikátní', 'Name, max 120, unique'),
                self::p('type', 'body', 'string', true, 'Typ instituce', 'Institution type', ['enum' => ['exchange', 'broker', 'wallet', 'robo', 'bank', 'pension']]),
                self::p('website', 'body', 'string', false, 'Web (URL)', 'Website (URL)'),
            ], $created('Založená instituce; 409 duplicate při shodném názvu.', 'Created institution; 409 duplicate for an existing name.')),
            $e('instruments_list', 'catalog', 'GET', '/api/instruments', $R, 'Seznam instrumentů', 'List instruments', [
                self::p('q', 'query', 'string', false, 'Hledání v symbolu, názvu a ISIN', 'Search in symbol, name and ISIN', ['example' => 'BTC']),
                self::p('asset_class', 'query', 'string', false, 'Třída aktiv', 'Asset class', ['enum' => ['crypto', 'etf', 'stock', 'fund', 'cash']]),
                self::p('all', 'query', 'string', false, '„1" = i neaktivní', '"1" = include inactive', ['enum' => ['1']]),
            ], $ok('Pole instrumentů (max. 500) s poslední cenou.', 'Array of instruments (max 500) with last price.', [['id' => 5, 'asset_class' => 'crypto', 'symbol' => 'BTC', 'name' => 'Bitcoin', 'isin' => null, 'quote_currency' => 'EUR', 'price_source' => 'coingecko', 'last_price' => ['date' => '2026-10-11', 'close' => '104250.12', 'currency' => 'EUR', 'source' => 'coingecko', 'stale' => false]]])),
            $e('instruments_create', 'catalog', 'POST', '/api/instruments', $W, 'Založit instrument', 'Create instrument', [
                self::p('asset_class', 'body', 'string', true, 'Třída aktiv', 'Asset class', ['enum' => ['crypto', 'etf', 'stock', 'fund', 'cash']]),
                self::p('symbol', 'body', 'string', true, 'Symbol, max. 40 znaků', 'Symbol, max 40', ['example' => 'VWCE']),
                self::p('name', 'body', 'string', true, 'Název, max. 190 znaků', 'Name, max 190'),
                self::p('isin', 'body', 'string', false, 'ISIN (12 znaků)', 'ISIN (12 characters)', ['example' => 'IE00BK5BQT80']),
                self::p('quote_currency', 'body', 'currency', true, 'Měna kotace', 'Quote currency', ['example' => 'EUR']),
                self::p('decimals', 'body', 'integer', false, 'Desetinná místa množství (0–18)', 'Quantity decimals (0–18)', ['default' => 8]),
                self::p('price_source', 'body', 'string', false, 'Zdroj ceny', 'Price source', ['enum' => ['coingecko', 'xtb', 'yahoo', 'manual'], 'default' => 'manual']),
                self::p('source_refs', 'body', 'object', false, 'Identifikátory u zdrojů, např. {"yahoo": "VWCE.DE"}', 'Source identifiers, e.g. {"yahoo": "VWCE.DE"}'),
            ], $created('Založený instrument; 409 duplicate při shodné třídě, symbolu a měně.', 'Created instrument; 409 duplicate for the same class, symbol and currency.')),

            // ── Ceny a kurzy ───────────────────────────────────────────────
            $e('prices_list', 'market', 'GET', '/api/prices', $R, 'Historie ceny instrumentu', 'Instrument price history', [
                self::p('instrument_id', 'query', 'integer', true, 'Instrument', 'Instrument'),
                $date('from', false, 'Od (výchozí rok zpět)', 'From (default one year back)'),
                $date('to', false, 'Do (výchozí dnes)', 'To (default today)'),
            ], $ok('Poslední cena a denní historie.', 'Last price and daily history.', ['instrument_id' => 5, 'last_price' => null, 'data' => [['date' => '2026-10-10', 'close' => '103900.00', 'currency' => 'EUR', 'source' => 'coingecko']]])),
            $e('prices_create', 'market', 'POST', '/api/prices', $W, 'Ruční cena', 'Manual price', [
                self::p('instrument_id', 'body', 'integer', true, 'Instrument (se zdrojem manual, jinak jen admin)', 'Instrument (manual source; otherwise admin only)'),
                self::p('date', 'body', 'date', true, 'Den, ne v budoucnosti', 'Day, not in the future'),
                $money('close', true, 'Cena (> 0)', 'Price (> 0)'),
                self::p('currency', 'body', 'currency', false, 'Měna; výchozí měna kotace', 'Currency; defaults to quote currency'),
            ], $created('Instrument a jeho poslední cena.', 'Instrument and its last price.')),
            $e('fx_rates', 'market', 'GET', '/api/fx-rates', $R, 'Kurzy ČNB', 'CNB exchange rates', [
                $date('date', false, 'Den (výchozí poslední dostupný)', 'Day (default latest available)'),
            ], $ok('Kurzovní lístek: rate = Kč za amount jednotek.', 'Rate sheet: rate = CZK per amount units.', ['requested_date' => '2026-10-11', 'date' => '2026-10-09', 'rates' => [['currency' => 'EUR', 'amount' => 1, 'rate' => '24.3150']]])),

            // ── Účty ───────────────────────────────────────────────────────
            $e('accounts_list', 'accounts', 'GET', '/api/accounts', $R, 'Seznam účtů', 'List accounts', [
                self::p('archived', 'query', 'string', false, '„1" = i archivované', '"1" = include archived', ['enum' => ['1']]),
            ], $ok('Pole účtů s institucí.', 'Array of accounts with institution.', [['id' => 2, 'institution_id' => 1, 'institution_name' => 'XTB', 'name' => 'Investiční účet', 'currency' => 'CZK', 'note' => null, 'archived_at' => null]])),
            $e('accounts_create', 'accounts', 'POST', '/api/accounts', $W, 'Založit účet', 'Create account', $accountBody, $created('Založený účet; 409 duplicate.', 'Created account; 409 duplicate.')),
            $e('accounts_get', 'accounts', 'GET', '/api/accounts/{id}', $R, 'Detail účtu', 'Account detail', [$id], $ok('Účet.', 'Account.')),
            $e('accounts_update', 'accounts', 'PUT', '/api/accounts/{id}', $W, 'Upravit účet', 'Update account', [$id, ...$accountBody, $archived], $ok('Upravený účet (PUT nahrazuje celý záznam).', 'Updated account (PUT replaces the record).')),
            $e('accounts_delete', 'accounts', 'DELETE', '/api/accounts/{id}', $W, 'Smazat účet', 'Delete account', [$id], ['description' => ['Smazáno; 409 in_use, pokud má pozice.', 'Deleted; 409 in_use when it has positions.']] + $deleted),

            // ── Portfolia ──────────────────────────────────────────────────
            $e('portfolios_list', 'portfolios', 'GET', '/api/portfolios', $R, 'Seznam portfolií', 'List portfolios', [
                self::p('archived', 'query', 'string', false, '„1" = i archivovaná', '"1" = include archived', ['enum' => ['1']]),
            ], $ok('Pole portfolií s počtem pozic.', 'Array of portfolios with position count.', [['id' => 1, 'name' => 'Dlouhodobé', 'sort' => 0, 'archived_at' => null, 'position_count' => 4]])),
            $e('portfolios_create', 'portfolios', 'POST', '/api/portfolios', $W, 'Založit portfolio', 'Create portfolio', $portfolioBody, $created('Založené portfolio; 409 duplicate.', 'Created portfolio; 409 duplicate.')),
            $e('portfolios_get', 'portfolios', 'GET', '/api/portfolios/{id}', $R, 'Detail portfolia', 'Portfolio detail', [$id], $ok('Portfolio.', 'Portfolio.')),
            $e('portfolios_update', 'portfolios', 'PUT', '/api/portfolios/{id}', $W, 'Upravit portfolio', 'Update portfolio', [$id, ...$portfolioBody, $archived], $ok('Upravené portfolio.', 'Updated portfolio.')),
            $e('portfolios_delete', 'portfolios', 'DELETE', '/api/portfolios/{id}', $W, 'Smazat portfolio', 'Delete portfolio', [$id], ['description' => ['Smazáno; 409 in_use, pokud má pozice.', 'Deleted; 409 in_use when it has positions.']] + $deleted),

            // ── Pozice ─────────────────────────────────────────────────────
            $e('positions_list', 'positions', 'GET', '/api/positions', $R, 'Seznam pozic', 'List positions', [
                self::p('portfolio_id', 'query', 'integer', false, 'Jen portfolio', 'Only portfolio'),
                self::p('account_id', 'query', 'integer', false, 'Jen účet', 'Only account'),
                self::p('archived', 'query', 'string', false, '„1" = i archivované', '"1" = include archived', ['enum' => ['1']]),
            ], $ok('Pole pozic s drženým množstvím a poslední cenou.', 'Array of positions with holding and last price.', [['id' => 7, 'portfolio_id' => 1, 'account_id' => 2, 'instrument_id' => 5, 'instrument_symbol' => 'BTC', 'valuation_mode' => 'market', 'currency' => 'EUR', 'holding' => '0.12', 'last_price' => null]])),
            $e('positions_create', 'positions', 'POST', '/api/positions', $W, 'Založit pozici', 'Create position', [
                self::p('valuation_mode', 'body', 'string', true, 'market = instrument s cenou; manual = ruční ocenění (Portu, spořicí účet)', 'market = instrument with price; manual = manual valuation (robo-advisor, savings)', ['enum' => ['market', 'manual']]),
                self::p('portfolio_id', 'body', 'integer', true, 'Portfolio', 'Portfolio'),
                self::p('account_id', 'body', 'integer', true, 'Účet', 'Account'),
                self::p('instrument_id', 'body', 'integer', false, 'Instrument (market — povinný)', 'Instrument (market — required)'),
                self::p('name', 'body', 'string', false, 'Název (manual — povinný), max. 120 znaků', 'Name (manual — required), max 120'),
                self::p('currency', 'body', 'currency', false, 'Měna ruční pozice; výchozí měna účtu', 'Manual position currency; defaults to account currency'),
                self::p('is_cash', 'body', 'boolean', false, 'Hotovost na účtu (manual): zůstatek se nastavuje přes /cash-balance, název se doplní „Hotovost <měna>"', 'Cash on the account (manual): balance is set via /cash-balance, name defaults to "Hotovost <currency>"', ['default' => false]),
                $money('interest_rate_pa', false, 'Úrok p.a. v % (0–100)', 'Interest p.a. in % (0–100)'),
            ], $created('Založená pozice; 409 duplicate při stejném instrumentu, účtu a portfoliu.', 'Created position; 409 duplicate for the same instrument, account and portfolio.')),
            $e('positions_get', 'positions', 'GET', '/api/positions/{id}', $R, 'Detail pozice', 'Position detail', [$id], $ok('Pozice s drženým množstvím a poslední cenou.', 'Position with holding and last price.')),
            $e('positions_update', 'positions', 'PUT', '/api/positions/{id}', $W, 'Upravit pozici', 'Update position', [$id,
                self::p('portfolio_id', 'body', 'integer', true, 'Portfolio', 'Portfolio'),
                self::p('account_id', 'body', 'integer', true, 'Účet', 'Account'),
                self::p('name', 'body', 'string', false, 'Název (manual — povinný)', 'Name (manual — required)'),
                $money('interest_rate_pa', false, 'Úrok p.a. v %', 'Interest p.a. in %'),
                $archived,
            ], $ok('Upravená pozice; instrument, režim a měnu nejde změnit.', 'Updated position; instrument, mode and currency cannot change.')),
            $e('positions_delete', 'positions', 'DELETE', '/api/positions/{id}', $W, 'Smazat pozici', 'Delete position', [$id], ['description' => ['Smazáno; 409 in_use, pokud má transakce.', 'Deleted; 409 in_use when it has transactions.']] + $deleted),

            // ── Transakce ──────────────────────────────────────────────────
            $e('transactions_list', 'transactions', 'GET', '/api/transactions', $R, 'Seznam transakcí', 'List transactions', [
                self::p('position_id', 'query', 'integer', false, 'Jen pozice', 'Only position'),
                self::p('portfolio_id', 'query', 'integer', false, 'Jen portfolio', 'Only portfolio'),
                self::p('account_id', 'query', 'integer', false, 'Jen účet', 'Only account'),
                self::p('type', 'query', 'string', false, 'Typ', 'Type', ['enum' => ['buy', 'sell', 'deposit', 'withdrawal', 'transfer_in', 'transfer_out', 'reward', 'dividend', 'interest', 'fee', 'split']]),
                $date('from', false, 'Od (včetně)', 'From (inclusive)'),
                $date('to', false, 'Do (včetně)', 'To (inclusive)'),
                self::p('page', 'query', 'integer', false, 'Stránka', 'Page', ['default' => 1]),
                self::p('per_page', 'query', 'integer', false, 'Počet na stránku (5–200)', 'Per page (5–200)', ['default' => 50]),
                self::p('sort', 'query', 'string', false, 'Řazení: executed_at, type, amount, quantity, id; „-" = sestupně, víc čárkou', 'Sort: executed_at, type, amount, quantity, id; "-" = descending, comma-separated', ['default' => '-executed_at']),
            ], $ok('Stránka transakcí a meta (total, page, per_page, pages).', 'Page of transactions with meta (total, page, per_page, pages).', ['data' => [['id' => 41, 'position_id' => 7, 'type' => 'buy', 'executed_at' => '2026-01-15 10:30:00', 'quantity' => '0.015', 'amount' => '1250.5', 'price_currency' => 'EUR', 'fee' => '2.5', 'fee_currency' => 'EUR']], 'meta' => ['total' => 1, 'page' => 1, 'per_page' => 50, 'pages' => 1]])),
            $e('transactions_create', 'transactions', 'POST', '/api/transactions', $W, 'Založit transakci', 'Create transaction', [
                self::p('position_id', 'body', 'integer', true, 'Pozice', 'Position'), ...$txBody,
            ], $created('Založená transakce; 422 insufficient_quantity, pokud by pozice držela záporné množství.', 'Created transaction; 422 insufficient_quantity when the holding would go negative.')),
            $e('transactions_get', 'transactions', 'GET', '/api/transactions/{id}', $R, 'Detail transakce', 'Transaction detail', [$id], $ok('Transakce.', 'Transaction.')),
            $e('transactions_update', 'transactions', 'PUT', '/api/transactions/{id}', $W, 'Upravit transakci', 'Update transaction', [$id, ...$txBody], $ok('Upravená transakce; převod nejde upravit (409 transfer_readonly).', 'Updated transaction; transfers cannot be edited (409 transfer_readonly).')),
            $e('transactions_delete', 'transactions', 'DELETE', '/api/transactions/{id}', $W, 'Smazat transakci', 'Delete transaction', [$id], ['description' => ['Smazáno; u převodu obě části.', 'Deleted; both legs of a transfer.']] + $deleted),
            $e('transfers_create', 'transactions', 'POST', '/api/transfers', $W, 'Převod mezi účty', 'Transfer between accounts', [
                self::p('from_position_id', 'body', 'integer', true, 'Zdrojová pozice', 'Source position'),
                self::p('to_position_id', 'body', 'integer', true, 'Cílová pozice stejného instrumentu', 'Target position of the same instrument'),
                $money('quantity', true, 'Převáděné množství (> 0)', 'Quantity (> 0)'),
                self::p('executed_at', 'body', 'datetime', true, 'Datum a čas', 'Date and time'),
                $money('fee', false, 'Poplatek (odečte se ze zdroje)', 'Fee (taken from the source)'),
                self::p('fee_currency', 'body', 'string', false, 'Měna poplatku nebo symbol (BTC)', 'Fee currency or symbol (BTC)'),
                $note,
            ], $created('Skupina převodu a obě transakce (odchod, příjem). Datum nabytí se zachová.', 'Transfer group and both transactions (out, in). Acquisition date is kept.')),

            // ── Ruční ocenění ──────────────────────────────────────────────
            $e('position_valuation', 'valuations', 'GET', '/api/positions/{id}/valuation', $R, 'Ocenění pozice', 'Position valuation', [$id], $ok('Hodnota, investováno, zisk, FIFO loty a prodeje v měně zobrazení.', 'Value, invested, gain, FIFO lots and disposals in display currency.')),
            $e('valuations_list', 'valuations', 'GET', '/api/positions/{id}/valuations', $R, 'Ruční ocenění pozice', 'Manual valuations', [$id], $ok('Pole ocenění od nejnovějšího.', 'Array of valuations, newest first.', [['id' => 3, 'position_id' => 9, 'date' => '2026-09-30', 'value' => '125000', 'currency' => 'CZK', 'note' => null]])),
            $e('valuations_create', 'valuations', 'POST', '/api/positions/{id}/valuations', $W, 'Zapsat ruční ocenění', 'Add manual valuation', [$id,
                self::p('date', 'body', 'date', true, 'Den (jedno ocenění na den, přepíše se)', 'Day (one per day, overwritten)'),
                $money('value', true, 'Hodnota (≥ 0)', 'Value (≥ 0)', ['example' => '125000']),
                self::p('currency', 'body', 'currency', false, 'Měna; výchozí měna pozice', 'Currency; defaults to position currency'),
                $note,
            ], $created('Všechna ocenění pozice; 422 not_manual u tržní pozice.', 'All valuations of the position; 422 not_manual for market positions.')),
            $e('valuations_delete', 'valuations', 'DELETE', '/api/valuations/{id}', $W, 'Smazat ocenění', 'Delete valuation', [$id], $deleted),
            $e('cash_balance', 'valuations', 'POST', '/api/positions/{id}/cash-balance', $W, 'Nastavit zůstatek hotovosti', 'Set cash balance', [$id,
                self::p('date', 'body', 'date', true, 'Den zůstatku — ne dřív než poslední pohyb pozice', 'Balance day — not before the last movement of the position', ['example' => '2026-10-11']),
                $money('balance', true, 'Zůstatek (≥ 0) v měně pozice', 'Balance (≥ 0) in position currency', ['example' => '25000']),
                $note,
            ], $ok('Nový a předchozí zůstatek, rozdíl zapsaný jako vklad / výběr (transaction_id; null když se nezměnil) a pozice. 422 not_cash u jiné pozice.',
                'New and previous balance, the difference recorded as deposit / withdrawal (transaction_id; null when unchanged) and the position. 422 not_cash for other positions.',
                ['balance' => '25000', 'previous' => '30000', 'delta' => '-5000', 'transaction_id' => 812])),

            // ── Přehledy ───────────────────────────────────────────────────
            $e('dashboard', 'overview', 'GET', '/api/dashboard', $R, 'Přehled', 'Dashboard', [], $ok('Součty, alokace, pozice s oceněním a upozornění v měně zobrazení.', 'Totals, allocation, valued positions and warnings in display currency.',
                ['currency' => 'CZK', 'totals' => ['value' => '1250000.00', 'invested' => '980000.00', 'unrealized' => '270000.00', 'realized' => '15000.00', 'income' => '4200.00', 'fees' => '1800.00', 'total_return' => '287400.00', 'return_pct' => '29.33']])),
            $e('history', 'overview', 'GET', '/api/history', $R, 'Historie hodnoty', 'Value history', [
                self::p('scope', 'query', 'string', false, 'Rozsah', 'Scope', ['enum' => ['total', 'portfolio', 'position', 'class'], 'default' => 'total']),
                self::p('id', 'query', 'string', false, 'ID portfolia / pozice, u class třída (crypto, etf…); povinné mimo total', 'Portfolio / position ID, class name for class; required except total'),
                self::p('range', 'query', 'string', false, 'Období', 'Range', ['enum' => ['1M', '3M', 'YTD', '1Y', 'ALL'], 'default' => '1Y']),
            ], $ok('Denní body (hodnota, investováno, benchmark) a metriky (zisk, TWR, XIRR, inflace ČR a reálný výnos); benchmark = stejné toky do zvoleného instrumentu.', 'Daily points (value, invested, benchmark) and metrics (gain, TWR, XIRR, Czech inflation and real return); benchmark = same flows into the chosen instrument.')),
            $e('summary', 'overview', 'GET', '/api/summary', $R, 'Měsíční a roční souhrn', 'Monthly and yearly summary', [
                self::p('portfolio_id', 'query', 'integer', false, 'Jen portfolio', 'Only portfolio'),
                self::p('account_id', 'query', 'integer', false, 'Jen účet', 'Only account'),
            ], $ok('Měsíce a roky: hodnota, toky, výnos (Modified Dietz), dividendy, úroky, poplatky.', 'Months and years: value, flows, return (Modified Dietz), dividends, interest, fees.')),
            $e('income', 'overview', 'GET', '/api/income', $R, 'Dividendy a úroky', 'Dividends and interest', [
                self::p('year', 'query', 'integer', false, 'Rok (bez = celá historie)', 'Year (omitted = all history)', ['example' => 2026]),
            ], $ok('Součty po letech, měsících a pozicích a seznam plateb (hrubě, sraženo, čistě).', 'Totals by year, month and position and a list of payments (gross, withheld, net).')),
            $e('allocation', 'overview', 'GET', '/api/allocation', $R, 'Cílová alokace a rebalancing', 'Target allocation and rebalancing', [
                self::p('portfolio_id', 'query', 'integer', false, 'Cíle portfolia (bez = celý majetek)', 'Portfolio targets (omitted = all holdings)'),
                self::p('deposit', 'query', 'string', false, 'Plánovaný vklad v měně zobrazení → návrh nákupů', 'Planned deposit in display currency → purchase plan', ['example' => '10000']),
            ], $ok('Třídy a instrumenty: hodnota, podíl, cíl, odchylka, mimo toleranci; návrh vkladu a plný rebalancing (prodeje se zdanitelnými kusy z lotů < 3 roky).', 'Classes and instruments: value, share, target, deviation, out of band; deposit plan and full rebalancing (sells with taxable quantity from lots < 3 years).')),
            $e('net_worth', 'overview', 'GET', '/api/net-worth', $R, 'Čisté jmění', 'Net worth', [],
                $ok('Investice + majetek mimo investice (nemovitosti, ostatní) − závazky; položky s hodnotou, u úvěru úrok, splátka a odhad doplacení.', 'Investments + other assets (real estate, other) − liabilities; items with value, for loans rate, payment and payoff estimate.')),
            $e('net_worth_history', 'overview', 'GET', '/api/net-worth/history', $R, 'Historie čistého jmění', 'Net worth history', [
                self::p('range', 'query', 'string', false, 'Období', 'Range', ['enum' => ['1M', '3M', 'YTD', '1Y', 'ALL'], 'default' => '1Y']),
            ], $ok('Denní body: investice, majetek, závazky, čisté jmění.', 'Daily points: investments, assets, liabilities, net worth.')),
            $e('net_worth_entry', 'overview', 'POST', '/api/net-worth/items/{id}/entries', $W, 'Zapsat ocenění / zůstatek', 'Record valuation / balance', [
                $id,
                self::p('date', 'body', 'string', true, 'Datum (RRRR-MM-DD); stejný den se přepíše', 'Date (YYYY-MM-DD); the same day is overwritten', ['example' => '2026-10-01']),
                self::p('value', 'body', 'decimal', true, 'Hodnota majetku nebo zůstatek úvěru v měně položky', 'Asset value or loan balance in the item currency', ['example' => '4500000']),
                self::p('rate_pct', 'body', 'decimal', false, 'Závazek: nový úrok p. a. (prázdné = beze změny)', 'Liability: new rate p.a. (empty = unchanged)'),
                self::p('payment', 'body', 'decimal', false, 'Závazek: nová měsíční splátka (prázdné = beze změny)', 'Liability: new monthly payment (empty = unchanged)'),
            ], $created('Seznam záznamů položky.', 'List of item entries.')),
            $e('allocation_targets', 'overview', 'PUT', '/api/allocation/targets', $W, 'Uložit cílovou alokaci', 'Save target allocation', [
                self::p('portfolio_id', 'body', 'integer', false, 'Portfolio (bez = celý majetek)', 'Portfolio (omitted = all holdings)'),
                self::p('classes', 'body', 'array', true, 'Celá sada: [{"category": "etf", "target_pct": "60", "band_pct": "5", "instruments": [{"instrument_id": 5, "target_pct": "100"}]}]; třídy i instrumenty ve třídě v součtu 100 %, prázdné = smazat', 'Whole set: [{"category": "etf", "target_pct": "60", "band_pct": "5", "instruments": [{"instrument_id": 5, "target_pct": "100"}]}]; classes and instruments within a class sum to 100 %, empty = delete'),
            ], $ok('Stejná odpověď jako GET /api/allocation.', 'Same response as GET /api/allocation.')),

            // ── Daňový report ──────────────────────────────────────────────
            $e('tax_years', 'tax', 'GET', '/api/tax-report/years', $R, 'Roky s daňovými podklady', 'Years with tax data', [], $ok('Pole roků s prodejem, dividendou nebo úrokem.', 'Array of years with a sale, dividend or interest.', [2026, 2025])),
            $e('tax_report', 'tax', 'GET', '/api/tax-report', $R, 'Daňový report', 'Tax report', $taxQuery, $ok('Prodeje CP a krypta po lotech s osvobozením, zahraniční dividendy po zemích, úroky; 422 fx_missing.', 'Securities and crypto sales by lot with exemptions, foreign dividends by country, interest; 422 fx_missing.')),
            $e('tax_filing', 'tax', 'GET', '/api/tax-report/filing', $R, 'Podklad k přepisu do přiznání', 'Tax return filing guide', [
                ...$taxQuery,
                self::p('dividends', 'query', 'string', false, 'Zahraniční dividendy: 16a = samostatný základ (příloha 4), 38 = ř. 38 + příloha 3', 'Foreign dividends: 16a = separate base (attachment 4), 38 = row 38 + attachment 3', ['enum' => ['16a', '38'], 'default' => '16a']),
            ], $ok('Hodnoty řádků tiskopisu DPFO v celých Kč: příloha 2 (ř. 207–209 → ř. 40), dividendy (příloha 4 ř. 401a–414 → ř. 74a, nebo ř. 38 a příloha 3 po státech); warnings.', 'DPFO form row values in whole CZK: attachment 2 (rows 207–209 → row 40), dividends (attachment 4 rows 401a–414 → row 74a, or row 38 and attachment 3 per country); warnings.')),
            $e('tax_filing_csv', 'tax', 'GET', '/api/tax-report/filing.csv', $R, 'Podklad k přepisu — CSV', 'Filing guide — CSV', [
                ...$taxQuery,
                self::p('dividends', 'query', 'string', false, 'Způsob zdanění zahraničních dividend', 'Foreign dividend method', ['enum' => ['16a', '38'], 'default' => '16a']),
            ], $ok('CSV: oddíl; řádek; popis; Kč.', 'CSV: section; row; description; CZK.'), 'text/csv'),
            $e('tax_report_csv', 'tax', 'GET', '/api/tax-report/export.csv', $R, 'Daňový report — CSV', 'Tax report — CSV', $taxQuery, $ok('CSV pro Excel (UTF-8 s BOM, středník, desetinná čárka).', 'CSV for Excel (UTF-8 with BOM, semicolon, decimal comma).'), 'text/csv'),

            // ── Importy ────────────────────────────────────────────────────
            $e('import_sources', 'imports', 'GET', '/api/imports/sources', $R, 'Podporované zdroje importu', 'Supported import sources', [], $ok('Pole {key, label}.', 'Array of {key, label}.', [['key' => 'coinbase', 'label' => 'Coinbase'], ['key' => 'xtb', 'label' => 'XTB']])),
            $e('import_preview', 'imports', 'POST', '/api/imports/preview', $W, 'Nahrát export a vytvořit náhled', 'Upload export and create preview', [
                self::p('file', 'form', 'file', true, 'CSV, XLSX nebo ZIP, max. 20 MB', 'CSV, XLSX or ZIP, max 20 MB'),
                self::p('account_id', 'form', 'integer', true, 'Účet, ze kterého export pochází', 'Account the export comes from'),
                self::p('portfolio_id', 'form', 'integer', true, 'Portfolio pro nové pozice', 'Portfolio for new positions'),
                self::p('source', 'form', 'string', false, 'Zdroj (bez = rozpoznat automaticky)', 'Source (omitted = auto-detect)', ['enum' => ['coinbase', 'anycoin', 'coinmate', 'binance', 'kraken', 'xtb', 'trading212', 'revolut', 'degiro', 'ibkr']]),
            ], $ok('Náhled s tokenem (platí 24 h): souhrn, instrumenty k přiřazení, převody, řádky. Nic se nezapíše.', 'Preview with a token (valid 24 h): summary, instruments to map, transfers, rows. Nothing is written.'), 'multipart/form-data'),
            $e('import_preview_get', 'imports', 'GET', '/api/imports/preview/{token}', $R, 'Náhled importu', 'Import preview', [
                self::p('token', 'path', 'string', true, 'Token náhledu (32 hex znaků)', 'Preview token (32 hex characters)'),
            ], $ok('Stejný náhled jako po nahrání; 404 po vypršení.', 'Same preview as after upload; 404 when expired.')),
            $e('import_confirm', 'imports', 'POST', '/api/imports/preview/{token}/confirm', $W, 'Potvrdit import', 'Confirm import', [
                self::p('token', 'path', 'string', true, 'Token náhledu', 'Preview token'),
                self::p('assets', 'body', 'object', false, 'Rozhodnutí k instrumentům podle klíče z náhledu: {"instrument_id": 5} | {"create": {"name", "quote_currency", "asset_class", "symbol"}} | {"skip": true}', 'Instrument decisions by preview key: {"instrument_id": 5} | {"create": {"name", "quote_currency", "asset_class", "symbol"}} | {"skip": true}'),
                self::p('transfers', 'body', 'object', false, 'Cíl odeslání / příjmů podle indexu řádku: {"position_id"} | {"account_id"}; bez = vynechat', 'Target of sends / receives by row index: {"position_id"} | {"account_id"}; omitted = skip'),
            ], $ok('Výsledek dávky: batch_id, imported, transfers, skipped. Opakovaný import nevytvoří duplicity.', 'Batch result: batch_id, imported, transfers, skipped. Re-importing creates no duplicates.')),
            $e('imports_list', 'imports', 'GET', '/api/imports', $R, 'Historie importů', 'Import history', [], $ok('Dávky importu (max. 100, od nejnovější).', 'Import batches (max 100, newest first).')),
            $e('import_revert', 'imports', 'POST', '/api/imports/{id}/revert', $W, 'Vrátit import', 'Revert import', [$id], $ok('Počet smazaných transakcí; 409 already_reverted.', 'Number of deleted transactions; 409 already_reverted.', ['deleted' => 42])),

            // ── Cenové hranice ─────────────────────────────────────────────
            $e('alerts_list', 'alerts', 'GET', '/api/price-alerts', $R, 'Cenové hranice', 'Price alerts', [], $ok('Pole hranic; ohlášené mají triggered_at a triggered_price.', 'Array of alerts; reported ones have triggered_at and triggered_price.', [['id' => 1, 'instrument_id' => 5, 'symbol' => 'BTC', 'direction' => 'above', 'price' => '120000', 'currency' => 'EUR', 'triggered_at' => null]])),
            $e('alerts_create', 'alerts', 'POST', '/api/price-alerts', $W, 'Přidat cenovou hranici', 'Add price alert', $alertBody, $created('Založená hranice (max. 100).', 'Created alert (max 100).')),
            $e('alerts_update', 'alerts', 'PUT', '/api/price-alerts/{id}', $W, 'Upravit cenovou hranici', 'Update price alert', [$id, ...$alertBody], $ok('Upravená a znovu aktivovaná hranice.', 'Updated and re-armed alert.')),
            $e('alerts_delete', 'alerts', 'DELETE', '/api/price-alerts/{id}', $W, 'Smazat cenovou hranici', 'Delete price alert', [$id], $deleted),
        ];
    }

    /**
     * @param list<array<string,mixed>> $params
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function e(string $id, string $group, string $method, string $path, string $scope, string $cs, string $en,
        array $params, array $response, string $contentType = 'application/json'): array
    {
        return ['id' => $id, 'group' => $group, 'method' => $method, 'path' => $path, 'scope' => $scope,
            'summary' => [$cs, $en], 'description' => null, 'params' => $params, 'response' => $response, 'content_type' => $contentType];
    }

    /**
     * @param 'path'|'query'|'body'|'form' $in
     * @param array<string,mixed> $extra enum, default, example
     * @return array<string,mixed>
     */
    private static function p(string $name, string $in, string $type, bool $required, string $cs, string $en, array $extra = []): array
    {
        return ['name' => $name, 'in' => $in, 'type' => $type, 'required' => $required, 'description' => [$cs, $en],
            'enum' => $extra['enum'] ?? null, 'default' => $extra['default'] ?? null, 'example' => $extra['example'] ?? null];
    }
}
