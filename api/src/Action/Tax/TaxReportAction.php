<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Tax;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Service\Portfolio\DomainError;
use MyPortfolio\Service\Tax\FilingGuideService;
use MyPortfolio\Service\Tax\TaxReportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Daňový report:
 *   GET /api/tax-report/years                         — roky s prodejem / dividendou / úrokem
 *   GET /api/tax-report?year=&rate=daily|unified      — report
 *   GET /api/tax-report/export.csv?year=&rate=        — detail prodejů po lotech a dividend (CSV pro Excel)
 *   GET /api/tax-report/filing?year=&rate=&dividends=16a|38      — podklad k přepisu do přiznání (řádky tiskopisu)
 *   GET /api/tax-report/filing.csv?year=&rate=&dividends=16a|38  — totéž jako CSV
 */
final class TaxReportAction
{
    private const EXEMPTIONS = [
        'limit' => 'osvobozeno — limit 100 000 Kč', 'time_test' => 'osvobozeno — časový test 3 roky',
        'time_test_capped' => 'časový test — kráceno stropem 40 mil. Kč', 'before_crypto_exemption' => 'zdanitelné — prodej před 15. 2. 2025',
        'none' => 'zdanitelné',
    ];

    public function __construct(
        private readonly TaxReportService $reports,
        private readonly FilingGuideService $filing,
    ) {}

    public function years(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->reports->years(CurrentUser::owner($request)));
    }

    public function report(Request $request, Response $response): Response
    {
        try {
            [$year, $rate] = self::params($request);
            return Json::ok($response, $this->reports->build(CurrentUser::owner($request), $year, $rate));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function csv(Request $request, Response $response): Response
    {
        try {
            [$year, $rate] = self::params($request);
            $report = $this->reports->build(CurrentUser::owner($request), $year, $rate);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
        $out = fopen('php://temp', 'w+b');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Druh', 'Datum prodeje', 'Symbol', 'Název', 'ISIN', 'Účet', 'Množství', 'Datum nabytí',
            'Příjem CZK', 'Výdaj CZK', 'Zisk CZK', 'Osvobození'], ';', '"', '');
        foreach (['securities' => 'Cenné papíry', 'crypto' => 'Kryptoaktiva'] as $class => $label) {
            foreach ($report[$class]['rows'] as $r) {
                fputcsv($out, [$label, substr($r['disposed_at'], 0, 10), self::text($r['symbol']), self::text($r['name']), self::text($r['isin']), self::text($r['account']), self::dec($r['quantity']),
                    $r['acquired_at'] !== null ? substr($r['acquired_at'], 0, 10) : '', self::dec($r['income']), self::dec($r['expense']),
                    self::dec($r['gain']), trim((self::EXEMPTIONS[$r['exemption']] ?? $r['exemption'])
                        . ($r['fee_lot'] ? ' · kusy na poplatek prodeje' : '')
                        . ($r['fee_skipped'] !== null ? " · poplatek v {$r['fee_skipped']} není ve výdajích" : ''))], ';', '"', '');
            }
        }
        fputcsv($out, [], ';', '"', '');
        fputcsv($out, ['Dividendy', 'Datum', 'Symbol', 'Název', 'ISIN', 'Účet', 'Částka', 'Měna', 'Hrubě CZK', 'Sražená daň CZK', 'Země'], ';', '"', '');
        foreach ($report['dividends']['countries'] as $c) {
            foreach ($c['rows'] as $r) {
                fputcsv($out, ['Dividenda', $r['date'], self::text($r['symbol']), self::text($r['name']), self::text($r['isin']), self::text($r['account']), self::dec($r['amount']), $r['currency'],
                    self::dec($r['gross_czk']), self::dec($r['withheld_czk']), $c['country'] ?? ''], ';', '"', '');
            }
        }
        rewind($out);
        $response->getBody()->write((string) stream_get_contents($out));
        fclose($out);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="danovy-report-%d.csv"', $year));
    }

    public function filing(Request $request, Response $response): Response
    {
        try {
            return Json::ok($response, $this->guide($request));
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function filingCsv(Request $request, Response $response): Response
    {
        try {
            $guide = $this->guide($request);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
        $out = fopen('php://temp', 'w+b');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Oddíl', 'Řádek', 'Popis', 'Kč'], ';', '"', '');
        foreach (FilingGuideService::csvRows($guide) as $row) {
            fputcsv($out, $row, ';', '"', '');
        }
        rewind($out);
        $response->getBody()->write((string) stream_get_contents($out));
        fclose($out);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="podklad-priznani-%d.csv"', $guide['year']));
    }

    /** @return array<string,mixed> */
    private function guide(Request $request): array
    {
        [$year, $rate] = self::params($request);
        $method = (string) ($request->getQueryParams()['dividends'] ?? FilingGuideService::DIVIDENDS_SEPARATE);
        if (!in_array($method, [FilingGuideService::DIVIDENDS_SEPARATE, FilingGuideService::DIVIDENDS_GENERAL], true)) {
            throw DomainError::validation(['dividends' => ['Zvol 16a nebo 38.']]);
        }
        return $this->filing->build(CurrentUser::owner($request), $year, $rate, $method);
    }

    /** @return array{0:int,1:string} */
    private static function params(Request $request): array
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? 0);
        if ($year < 2000 || $year > (int) date('Y')) {
            throw DomainError::validation(['year' => ['Neplatný rok.']]);
        }
        return [$year, (string) ($q['rate'] ?? TaxReportService::RATE_DAILY)];
    }

    /** Text z dat uživatele / katalogu: začátek =, +, -, @ by Excel spustil jako vzorec → prefix apostrofem. */
    private static function text(?string $v): string
    {
        $v = (string) $v;
        return preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v;
    }

    /** Desetinná čárka pro český Excel. */
    private static function dec(string $v): string
    {
        return str_replace('.', ',', $v);
    }
}
