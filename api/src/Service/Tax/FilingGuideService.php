<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Podklad k přepisu do přiznání DPFO: hodnoty z daňového reportu
 * přiřazené k řádkům tiskopisu. Čísla řádků ověřena na tiskopisech Finanční správy pro roky {@see self::VERIFIED_YEARS}
 * (DAP 25 5405 vzor 28–30, přílohy 2–4); pro jiný rok se vrátí s upozorněním.
 *
 *   Příloha 2 (§ 10): druh D — prodej cenných papírů, druh C — kryptoaktiva (pokyny kód neuvádějí, běžná praxe
 *                     „prodej movitých věcí"); ř. 207 příjmy, ř. 208 výdaje (nejvýš do výše příjmů druhu), ř. 209 → ř. 40
 *   Dividendy ze zahraničí, volba poplatníka:
 *     16a — příloha 4: ř. 401a, 406, 409 (dolů na sta), 410 (15 %), 411–413 zápočet, 414 → ř. 74a
 *     38  — základní část ř. 38 + příloha 3 (metoda zápočtu) za každý stát: ř. 321 příjmy, ř. 323 daň zaplacená v zahraničí
 *           (ř. 322 výdaje k dividendě nejsou — neuvádí se);
 *           ř. 324–326 závisí na celém přiznání (ř. 42, 57), proto je podklad neuvádí
 *
 * Částky v celých Kč: příjmy a výdaje zaokrouhlené matematicky, daň nahoru (§ 146 daňového řádu),
 * daň uznaná k zápočtu dolů. Orientační podklad — ne daňové poradenství.
 */
final class FilingGuideService
{
    public const DIVIDENDS_SEPARATE = '16a';
    public const DIVIDENDS_GENERAL = '38';
    public const VERIFIED_YEARS = [2023, 2024, 2025];

    public function __construct(private readonly TaxReportService $reports) {}

    /** @return array<string,mixed> */
    public function build(int $userId, int $year, string $rate, string $dividendMethod): array
    {
        $report = $this->reports->build($userId, $year, $rate);
        $warnings = [];
        if (!in_array($year, self::VERIFIED_YEARS, true)) {
            $warnings[] = 'unverified_year';
        }

        // Příloha 2 — ostatní příjmy podle § 10
        $types = [];
        foreach ([CzTaxRules::SECURITIES => 'D', CzTaxRules::CRYPTO => 'C'] as $class => $code) {
            $income = self::whole($report[$class]['taxable_income']);
            if ($income->isZero()) {
                continue;
            }
            $expense = self::whole($report[$class]['taxable_expense']);
            $types[] = ['code' => $code, 'class' => $class, 'income' => $income, 'expense' => $expense,
                'expense_capped' => $expense->isGreaterThan($income) ? $income : $expense];
        }
        $r207 = self::sum(array_column($types, 'income'));
        $r208 = self::sum(array_column($types, 'expense_capped'));
        $attachment2 = [
            'rows' => array_map(static fn (array $t) => ['code' => $t['code'], 'class' => $t['class'], 'income' => (string) $t['income'],
                'expense' => (string) $t['expense'], 'difference' => (string) $t['income']->minus($t['expense'])], $types),
            'r207' => (string) $r207,
            'r208' => (string) $r208,
            'r209' => (string) $r207->minus($r208),
            'main_r40' => (string) $r207->minus($r208),
        ];
        if ($report['crypto']['taxable_income'] !== '0.00') {
            $warnings[] = 'crypto_code';
        }

        // Dividendy ze zahraničí
        $countries = $report['dividends']['countries'];
        $gross = self::whole($report['dividends']['total']['gross']);
        if ($dividendMethod === self::DIVIDENDS_SEPARATE) {
            $r409 = $gross->dividedBy(100, 0, RoundingMode::Down)->multipliedBy(100);
            $r410 = $r409->multipliedBy(CzTaxRules::DIVIDEND_RATE)->toScale(0, RoundingMode::Up);
            $withTax = array_filter($countries, static fn (array $c) => BigDecimal::of($c['withheld'])->isPositive());
            $r411 = self::whole(self::sum(array_map(static fn (array $c) => BigDecimal::of($c['gross']), $withTax)));
            $r412 = self::whole(self::sum(array_map(static fn (array $c) => BigDecimal::of($c['withheld']), $withTax)));
            $r413 = self::sum(array_map(static fn (array $c) => BigDecimal::of($c['creditable']), $withTax))->toScale(0, RoundingMode::Down);
            $cap = $r411->multipliedBy(CzTaxRules::DIVIDEND_RATE)->toScale(0, RoundingMode::Down);
            // zápočet nejvýš 15 % z ř. 411 a nikdy víc než daň na ř. 410 (ta je z ř. 409 zaokrouhleného na sta dolů)
            foreach ([$cap, $r410] as $limit) {
                $r413 = $r413->isGreaterThan($limit) ? $limit : $r413;
            }
            $r414 = $r410->minus($r413);
            $dividends = ['method' => self::DIVIDENDS_SEPARATE, 'r401a' => (string) $gross, 'r406' => (string) $gross, 'r409' => (string) $r409,
                'r410' => (string) $r410, 'r411' => (string) $r411, 'r412' => (string) $r412, 'r413' => (string) $r413,
                'r414' => (string) ($r414->isNegative() ? BigDecimal::zero() : $r414)];
            $dividends['main_r74a'] = $dividends['r414'];
        } else {
            $dividends = ['method' => self::DIVIDENDS_GENERAL, 'main_r38' => (string) $gross,
                'countries' => array_map(static fn (array $c) => ['country' => $c['country'], 'r321' => (string) self::whole($c['gross']),
                    'r323' => (string) self::whole($c['withheld'])], $countries)];
        }
        if (array_filter($countries, static fn (array $c) => $c['country'] === null) !== []) {
            $warnings[] = 'dividend_country_unknown';
        }
        if ($report['interest']['total'] !== '0.00') {
            $warnings[] = 'interest';
        }
        if ($report['cap_exceeded']) {
            $warnings[] = 'cap_exceeded';
        }

        return [
            'year'        => $year,
            'rate'        => $rate,
            'verified'    => in_array($year, self::VERIFIED_YEARS, true),
            'forms'       => 'DAP 25 5405, přílohy 25 5405/P2, /P3, /P4',
            'attachment2' => $attachment2,
            'dividends'   => $dividends,
            'interest_total'     => (string) self::whole($report['interest']['total']),
            'domestic_dividends' => (string) self::whole($report['dividends']['domestic']['gross']),
            'exempt'      => [
                'securities' => (string) self::whole($report[CzTaxRules::SECURITIES]['exempt_income']),
                'crypto'     => (string) self::whole($report[CzTaxRules::CRYPTO]['exempt_income']),
            ],
            'warnings'    => $warnings,
        ];
    }

    /**
     * Řádky podkladu pro CSV (oddíl; řádek; popis; hodnota v Kč).
     *
     * @param array<string,mixed> $g výstup build()
     * @return list<array{0:string,1:string,2:string,3:string}>
     */
    public static function csvRows(array $g): array
    {
        $out = [];
        foreach ($g['attachment2']['rows'] as $r) {
            $label = $r['code'] === 'D' ? 'D – prodej cenných papírů' : 'C – úplatný převod kryptoaktiv (prodej movitých věcí)';
            $out[] = ['Příloha 2', 'tabulka', "$label — příjmy", $r['income']];
            $out[] = ['Příloha 2', 'tabulka', "$label — výdaje", $r['expense']];
            $out[] = ['Příloha 2', 'tabulka', "$label — rozdíl", $r['difference']];
        }
        $out[] = ['Příloha 2', '207', 'Příjmy podle § 10', $g['attachment2']['r207']];
        $out[] = ['Příloha 2', '208', 'Výdaje podle § 10 (nejvýš do výše příjmů)', $g['attachment2']['r208']];
        $out[] = ['Příloha 2', '209', 'Dílčí základ daně z ostatních příjmů (→ ř. 40)', $g['attachment2']['r209']];
        $out[] = ['Základní část', '40', 'Dílčí základ daně z ostatních příjmů podle § 10', $g['attachment2']['main_r40']];
        $d = $g['dividends'];
        if ($d['method'] === self::DIVIDENDS_SEPARATE) {
            foreach (['401a' => 'Příjmy podle § 8 ze zdrojů v zahraničí (dividendy)', '406' => 'Úhrn dílčího samostatného základu daně',
                '409' => 'Součet dílčích základů zaokrouhlený na sta Kč dolů', '410' => 'Daň 15 %', '411' => 'Úhrn příjmů se zápočtem',
                '412' => 'Daň zaplacená v zahraničí', '413' => 'Daň uznaná k zápočtu', '414' => 'Daň ze samostatného základu (→ ř. 74a)'] as $row => $label) {
                $out[] = ['Příloha 4', $row, $label, $d['r' . $row]];
            }
            $out[] = ['Základní část', '74a', 'Daň ze samostatného základu daně podle § 16a', $d['main_r74a']];
        } else {
            $out[] = ['Základní část', '38', 'Dílčí základ daně z kapitálového majetku podle § 8 (zahraniční dividendy)', $d['main_r38']];
            foreach ($d['countries'] as $c) {
                $state = $c['country'] ?? '??';
                $out[] = ["Příloha 3 ($state)", '321', 'Příjmy ze zdrojů v zahraničí — metoda zápočtu', $c['r321']];
                $out[] = ["Příloha 3 ($state)", '323', 'Daň zaplacená v zahraničí', $c['r323']];
            }
        }
        return $out;
    }

    private static function whole(string|BigDecimal $v): BigDecimal
    {
        return BigDecimal::of($v)->toScale(0, RoundingMode::HalfUp);
    }

    /** @param list<BigDecimal> $values */
    private static function sum(array $values): BigDecimal
    {
        return array_reduce($values, static fn (BigDecimal $c, BigDecimal $v) => $c->plus($v), BigDecimal::zero());
    }
}
