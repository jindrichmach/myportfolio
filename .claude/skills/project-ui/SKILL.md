---
name: project-ui
description: Projektová pravidla pro UI MyPortfolio – sdílené komponenty ve web/src/components/ui, design tokeny z main.css, dark mode, i18n, mobile cards a přístupnost. Použij při tvorbě nebo úpravě jakékoli Vue stránky nebo komponenty.
---

# UI pravidla projektu

Než vymyslíš nový vzor, podívej se na sousední stránky a použij, co už existuje.

## Komponenty

1. Nejdřív sdílená komponenta z `web/src/components/ui/` – např. `SectionCard`, `KpiTile`, `DataTable` / `DataGrid` (na mobilu karty), `Badge`, `SegmentControl`, `Tabs`, `Modal`, `FilterBar`, `ActionBar`, `RowActionsMenu`, `EmptyState`, `TableSkeleton`, `InfoTip`, `ManualLink`, `Icon`, `IconButton`, `DatePicker`, `SearchableSelect`, `FieldError`.
2. Doménové komponenty patří do příslušné složky (`components/portfolio`, `components/tax` …).
3. Opakuje-li se stejná skupina utilit 3× a víc, udělej komponentu, ne CSS třídu.

## Barvy a typografie

- Jen tokeny z `@theme` ve `web/src/styles/main.css`: `primary-*`, `neutral-*`, `surface`, `accent-*`, `success-*` (zisk), `warning-*`, `danger-*` (ztráta/chyba), `chart-*`, `risk-*`.
- Box: `bg-surface border rounded-lg shadow-sm`. Primární tlačítko: `bg-action hover:bg-action-hover text-on-action`. Text značky: `text-primary-700`.
- Zakázáno: libovolné hex (`bg-[#…]`), inline `style="color: …"`, `text-white` na plochách značky, `bg-primary-600` tlačítka, nové CSS soubory.
- Grafy berou barvy přes `useChartColors` (`composables/useTheme.ts`, `--color-chart-*`), nastavení v `utils/chartSetup.ts`.
- Čísla a částky tabulárními číslicemi (font „MyPortfolio Digits“ řeší `main.css`); formátování přes `composables/useFormat.ts`, ne ručně.
- Chybí-li barva, přidej token do `main.css` (light i `.dark`) ve stejném tasku a uveď to v reportu.

## Dark mode a layout

- Dark mode přepisuje **hodnoty tokenů** v `.dark` – komponenty se nepřebarvují přes `dark:` varianty, pokud to token řeší sám.
- Mobile-first: tabulky na mobilu jako karty (`DataTable`), `sm:`/`md:`/`lg:` přidávají širší layout.

## Texty

- Všechny texty přes `t()` / `tm()` + `rt()`, vždy obě locale (`web/src/i18n/cs.json` i `en.json`); literální `{ }` escapovat `{'{token}'}`.
- Hodnoty z API jsou stringy; na `number` jen pro zobrazení a grafy.

## Přístupnost (kontroluje review)

- Obrázky s `alt` (dekorativní `alt=""`), ikonová tlačítka s `aria-label`.
- Každé pole formuláře má label; chyby přes `FieldError`.
- Klikatelné prvky jsou `<a>`/`<RouterLink>` nebo `<button>`, ne `<div @click>`; viditelný focus; modály zavíratelné Esc.
- Jeden `<h1>` na stránku, nadpisy bez přeskakování úrovní.
