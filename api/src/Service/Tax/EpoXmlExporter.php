<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Tax;

use MyPortfolio\Service\Portfolio\DomainError;

/**
 * Podklad pro přiznání (FilingGuideService) jako XML pro EPO / Moje daně — struktura DPFDP7
 * („Daň z příjmů fyzických osob — od roku 2024 včetně“, popis struktury verze 01.01.02 z 23. 9. 2025).
 * Soubor je rozpracované přiznání: obsahuje jen řádky, které aplikace spočítá (příloha č. 2, dividendy
 * v příloze č. 3 nebo č. 4 a řádky 38 / 40 / 74a); údaje o poplatníkovi a zbytek přiznání se doplní v EPO.
 */
final class EpoXmlExporter
{
    /** Roky, které struktura DPFDP7 přijímá (kontrola věty D: „rok DAP může být pouze 2024 nebo 2025“). */
    public const YEARS = [2024, 2025];

    /** Druh příjmu v příloze č. 2 (kod_dr_prij10): D = prodej cenných papírů, C = prodej movitých věcí */
    private const P2_LABELS = [CzTaxRules::SECURITIES => 'Prodej cenných papírů', CzTaxRules::CRYPTO => 'Prodej kryptoaktiv'];

    /** @param array<string,mixed> $guide výstup FilingGuideService::build() */
    public function export(array $guide, int $financialOffice, string $appVersion): string
    {
        $year = (int) $guide['year'];
        if (!in_array($year, self::YEARS, true)) {
            throw DomainError::validation(['year' => ['XML pro EPO (struktura DPFDP7) jde jen pro roky ' . implode(' a ', self::YEARS) . '.']]);
        }
        if ($financialOffice < 1 || $financialOffice > 9999) {
            throw DomainError::validation(['ufo' => ['Zadej číslo finančního úřadu (1–4 číslice) podle číselníku FÚ.']]);
        }

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->appendChild($xml->createElement('Pisemnost'));
        $root->setAttribute('nazevSW', 'MyPortfolio');
        $root->setAttribute('verzeSW', $appVersion);
        $form = $root->appendChild($xml->createElement('DPFDP7'));

        $a2 = $guide['attachment2'];
        $d = $guide['dividends'];
        $separate = $d['method'] === FilingGuideService::DIVIDENDS_SEPARATE;

        // Věta D — povinné položky; řádné přiznání, bez daňového poradce a auditu
        self::veta($xml, $form, 'VetaD', [
            'k_uladis' => 'DPF', 'dokument' => 'DP7', 'rok' => (string) $year, 'dap_typ' => 'B',
            'c_ufo_cil' => (string) $financialOffice, 'pln_moc' => 'N', 'audit' => 'N',
            'da_samzakl' => $separate ? self::positive($d['main_r74a']) : null,
        ]);
        // II. oddíl: ř. 38 (dividendy obecně) a ř. 40 (ř. 209 přílohy č. 2)
        self::veta($xml, $form, 'VetaO', [
            'kc_zakldan8' => $separate ? null : self::positive($d['main_r38']),
            'kc_zd10'     => $a2['rows'] !== [] ? $a2['main_r40'] : null,
        ]);
        // Přiložené přílohy — příloha č. 2 musí být „1“, když je vyplněný ř. 40
        self::veta($xml, $form, 'VetaB', ['priloha2' => $a2['rows'] !== [] ? '1' : null]);

        if ($a2['rows'] !== []) {
            self::veta($xml, $form, 'VetaV', ['kc_prij10' => $a2['r207'], 'kc_vyd10' => $a2['r208'], 'kc_zd10p' => $a2['r209']]);
            foreach ($a2['rows'] as $r) {
                self::veta($xml, $form, 'VetaJ', [
                    'kod_dr_prij10' => $r['code'], 'druh_prij10' => self::P2_LABELS[$r['class']] ?? null,
                    'prijmy10' => $r['income'], 'vydaje10' => $r['expense'], 'rozdil10' => $r['difference'],
                ]);
            }
        }
        if (!$separate) {
            // Příloha č. 3 — metoda prostého zápočtu, jedna věta L za stát (ř. 321 příjmy, ř. 323 daň v zahraničí)
            foreach ($d['countries'] as $c) {
                self::veta($xml, $form, 'VetaL', [
                    'kod_statu' => $c['country'] !== null && $c['country'] !== '??' ? $c['country'] : null,
                    'kc_prijzap' => $c['r321'], 'da_zahr' => $c['r323'],
                ]);
            }
        } elseif (self::positive($d['r401a']) !== null) {
            // Příloha č. 4 — samostatný základ daně (§ 16a), dividendy ze zahraničí na ř. 401a
            self::veta($xml, $form, 'VetaZ', [
                'kc_prij48' => $d['r401a'], 'kc_zd48' => $d['r406'], 'kc_uhrndzd' => $d['r409'], 'kc_dan415' => $d['r410'],
                'kc_uh415' => $d['r411'], 'kc_zahr415' => $d['r412'], 'kc_uznzap415' => $d['r413'], 'da_samzakl4' => $d['r414'],
            ]);
        }

        return (string) $xml->saveXML();
    }

    /** Věta = element s položkami jako atributy; prázdné položky se vynechají, prázdná věta také. */
    private static function veta(\DOMDocument $xml, \DOMNode $form, string $name, array $items): void
    {
        $items = array_filter($items, static fn (?string $v) => $v !== null && $v !== '');
        if ($items === []) {
            return;
        }
        $el = $xml->createElement($name);
        foreach ($items as $k => $v) {
            $el->setAttribute($k, $v);
        }
        $form->appendChild($el);
    }

    private static function positive(?string $v): ?string
    {
        return $v !== null && $v !== '' && $v !== '0' ? $v : null;
    }
}
