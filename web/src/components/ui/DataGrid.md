# DataGrid — návod a rozhodnutí

Znovupoužitelný seznam: lišta filtrů nad tabulkou, tabulka (na mobilu karty), číslované stránkování
a volba počtu záznamů na stránku. Převzatý z MyBusinessCard. V MyPortfolio ho používají:
serverový režim `pages/admin/ActivityLog.vue` a transakce (`components/portfolio/TransactionTable.vue`
na stránce Transakce a na detailu pozice); klientský režim `pages/admin/Users.vue`, `pages/admin/Market.vue`,
`pages/catalog/Instruments.vue`, `pages/catalog/Institutions.vue` a `pages/portfolio/Accounts.vue`. Čistá logika je v `grid.ts`, ovládací prvek filtru
v `GridFilterField.vue`, tabulka v `DataTable.vue`, prázdný stav v `EmptyState.vue`.

## Soubory

| Soubor | Co dělá |
|---|---|
| `DataGrid.vue` | Skládá lištu filtrů, tabulku, patičku se stránkováním a prázdný stav. |
| `grid.ts` | Typy filtrů, výchozí hodnoty, predikáty, `pageCount`, `clampPage`, `pageItems`. Bez Vue. |
| `GridFilterField.vue` | Jeden ovládací prvek filtru (text, select, multiselect, daterange). |
| `DataTable.vue` | Tabulka + karty na mobilu. Používá ji i stránka API tokenů. |
| `FilterBar.vue` | Kontejner lišty: slot `primary`, výchozí slot pro ostatní filtry, slot `actions` vpravo. |

## Použití (základ)

```vue
<DataGrid :columns="columns" :rows="rows" :row-key="(r: Row) => r.id" :filters="filters" :filter-fn="matches">
  <template #cell-role="{ row }"><RoleBadge :role="row.role" /></template>
  <template #actions="{ row }"><IconButton icon="edit" :label="t('common.edit')" @click="edit(row)" /></template>
</DataGrid>
```

## API

**Props**
- `columns: DataColumn[]` — sloupce (viz `DataTable.vue`). `primary: true` určí titulek karty na mobilu. `align` je `left`, `center` nebo `right`.
- `rows: T[]` — klientský režim: všechna data. Serverový režim: aktuální stránka.
- `rowKey: (row) => string | number`
- `filters?: GridFilter[]` — definice filtrů (viz níže). Výchozí hodnoty se nastaví při prvním vykreslení.
- `filterFn?: (row, values) => boolean` — složený predikát přes více polí. Používá se jen v klientském režimu.
- `total?: number` — zadání zapne serverový režim.
- `loading?: boolean`
- `pageSizes?: number[]` (výchozí `[10, 25, 50, 100]`), `initialPageSize?: number` (výchozí 25)
- `emptyText?: string` — nadpis prázdného stavu (výchozí `grid.empty`). Při aktivním filtru bez výsledku se vždy ukáže `grid.no_match`.
- `emptyHint?: string` — podnadpis prázdného stavu, pokyn co udělat dál.
- `emptyIcon?: IconName` — ikona prázdného stavu (výchozí `empty`, pro uživatele `users`).
- `rowClass?`, `expandable?`, `fixed?`, `stickyFirst?`, `cardsBelow?` — předávají se do `DataTable`.

**Emits**
- `change(query: { page, pageSize, filterValues })` — při připojení a při každé změně stránky, velikosti nebo filtru. Serverový rodič na něj načítá data.

**Sloty**
- `cell-<klíč>`, `actions`, `expanded`, `foot` — předávají se do `DataTable`.
- `filter-<klíč>` — vlastní filtr typu `custom`. Props: `{ value, set, label }`.
- Slot `toolbar` už grid nevykresluje. Tlačítko Obnovit bylo odstraněno, přidávat nová tlačítka do lišty je zatím nutné přes rozšíření gridu.

## Filtry

| `type` | Hodnota | Ovládací prvek | Poznámka |
|---|---|---|---|
| `text` | `string` | Vyhledávací pole | První `text` filtr jde do `primary` slotu, tedy vždy viditelný. Vyžaduje `placeholder`. |
| `select` | `string` (`''` = vše) | Výběr jedné hodnoty | `allLabel` je **povinný**, např. „Všechny role“. |
| `multiselect` | `string[]` | Rozbalovací panel se zaškrtávacími políčky | `allLabel` je povinný (text při prázdném výběru). |
| `daterange` | `{ from, to }` (YYYY-MM-DD) | Dvě pole typu date | Obě hranice jsou volitelné. |
| `custom` | cokoli | Vlastní slot `filter-<klíč>` | Pro složené vstupy, které nepatří k jednomu sloupci. |

Společné: `key` (klíč ve `values`), `label` (**vždy viditelný** nad prvkem), volitelně `column`.

**Pravidla pro názvy a texty**
- Popisek je vždy viditelný nad prvkem. Placeholder pole ukazuje, co se hledá: „Hledat e-mail nebo jméno“.
- Možnost „vše“ musí být konkrétní: „Všechny role“, „Všechny stavy“, ne obecné „Vše“.
- Název filtru je podstatné jméno (Hledat, Role, Stav, Akce, Entita, Období).

**Kdy filtr působí**
- Filtr s `column` filtruje sám v klientském režimu (`matchesFilter`). Textový filtr hledá podstring bez ohledu na velikost písmen, select a multiselect porovnávají hodnotu, daterange porovnává první 10 znaků (datum).
- Filtr **bez** `column` nic nedělá sám. Jeho výsledek musí zohlednit `filterFn`. Typicky: hledání přes více polí (e-mail nebo jméno), složené podmínky.
- V serverovém režimu grid filtry neaplikuje. Všechno jde do `change` a backend.

**Aktivní filtr** je ten, který má hodnotu (text bez mezer, vybraná položka, alespoň jedna hodnota daterange, vlastní hodnota není prázdná).

## Chování lišty

- Lišta se zobrazuje vždy, pokud grid má data nebo aktivní filtr (viz prázdný stav).
- Tlačítko **„<ikona x> Zrušit filtr“** se objeví jen když je aktivní alespoň jeden filtr. Vrátí všechny filtry do výchozího stavu. Žádné jiné tlačítko (např. Obnovit) v liště není.
- Na mobilu (pod `md`) se ostatní filtry schovají za tlačítko **Filtry** s odznakem počtu aktivních.
- Zarovnání: `FilterBar` používá `items-end`, aby popisky a ovládací prvky byly v jedné linii.

## Režimy

**Klientský** (bez `total`): grid sám filtruje (`filterFn` + sloupcové filtry) a stránkuje `rows`.
Data se načtou jednou. Vhodné pro malé seznamy (uživatelé, pozvánky).

**Serverový** (`total` zadán): `rows` je jen aktuální stránka a `total` celkový počet.
Grid nefiltruje ani nestránkuje data. Při připojení i při každé změně emituje `change`.
Rodič posílá do API:
- `limit = pageSize`, `offset = (page - 1) * pageSize`
- `q` (text), `action`, `entity_type` (select), `from`, `to` (daterange, YYYY-MM-DD)
- prázdné hodnoty se neposílají

Backend (`GET /api/admin/activity-log`) vrací `{ data, total, actions }`. Parametry `from` a `to` jsou validované regulárním výrazem, `to` je včetně celého dne. `q` hledá v názvu akce, e-mailu a jménu, znaky `%` a `_` se escapují.

## Stránkování

- Patička: vlevo „1–25 z 137“, uprostřed číslované stránky, vpravo „Na stránku“ (select).
- Číslované odkazy řeší `pageItems(current, count, siblings = 2)`. Vždy se ukáže první a poslední stránka a dvě kolem aktuální. Mezera přes jednu stránku se nahradí touto stránkou. Mezera se zobrazí jako „…“.
- Změna velikosti stránky nebo filtru vrací na stránku 1.
- Aktuální stránka je označená `aria-current="page"`, každé číslo má popisek „Strana N“.
- Patička se nevykreslí, když nejsou žádné záznamy.

## Prázdné stavy

Tři případy:
1. **Žádné záznamy a žádný aktivní filtr.** Grid vykreslí jen `EmptyState` v bílém rámečku (`bg-surface border shadow-sm`). Bez lišty a bez patičky. Nadpis = `emptyText`, podnadpis = `emptyHint`, ikona = `emptyIcon`. Text ve stylu „Žádné čekající pozvánky.“ a pod ním pokyn „Pozvi uživatele tlačítkem…“.
2. **Záznamy existují, ale filtr nic nevrátil.** Lišta zůstává (aby šel filtr zrušit), v kartě se ukáže text `grid.no_match`.
3. **Načítání.** Při `loading` bez záznamů se místo „Žádné záznamy“ ukáže `common.loading`.

Prázdný stav bez karty („visí na pozadí“) je chyba. Vždy obalit do rámečku.

## Řádky

- Řádek se při najetí myší vždy zvýrazní (`hover:bg-primary-50`), na desktopu i v mobilních kartách. Uživatel musí vidět, na který řádek klikne.
- Neaktivní položky se ztlumí přes `rowClass` (např. `text-neutral-400`).
- Akce řádku v slotu `actions` (ikonová tlačítka `IconButton` s `label`).

## Checklist pro nový grid

1. Definuj sloupce (`DataColumn[]`), `primary` pro mobilní titulek, `mono` pro kódy a e-maily, `align` pro čísla a stavy.
2. Vyber režim: klientský pro malé seznamy, serverový pro logy a velké tabulky (potřebuje backend s `limit`, `offset` a filtry).
3. Filtry: text jako první (primary), u každého `label`. Všechny `select` a `multiselect` mají konkrétní `allLabel`. Text má `placeholder` s tím, co se hledá.
4. Složené podmínky (více polí) dej do `filterFn` (klient) nebo do backendu (server). Nezapomeň, že filtr bez `column` sám nic nedělá.
5. Prázdné stavy: nastav `emptyText`, `emptyHint` a `emptyIcon`, aby pokyn dával smysl pro tenhle seznam.
6. Překlady přidej do cs i en (paritu hlídá PWA test). Žádný text natvrdo v šabloně.
7. Ověř: build, type-check, PWA testy. Pokud přidáváš backend parametry, přidej integrační test (vzor: `ActivityLogFilterTest`).
8. Manuál: stránka s gridem dostane popis filtrů a stránkování (kapitola Administrace).

## Známá omezení

- `filters` se čtou při vytvoření komponenty. Dynamická změna seznamu filtrů za běhu není podporovaná. Změna `options` (např. `actionOptions` v activity logu) je v pořádku, protože se čte z prop při každém renderu.
- Filtry nejsou v URL, po obnovení stránky se resetují.
- Řazení sloupců není implementované.
- Hromadné akce nejsou implementované.
- Export (CSV) není implementovaný.
- Vlastní filtr (`custom`) nemá výchozí hodnotu, rodič ji musí nastavit sám přes `set`.
- Ruční ověření v prohlížeči na desktopu musí proběhnout s admin účtem (seznamy jsou za admin rolí).

## Rozhodnutí a proč

- **Grid místo tabulky u každé stránky.** Stránky jsou stejné: filtr, tabulka, stránkování. Duplikace vedla k rozdílným vzhledům (pozvánky měly vlastní tabulku).
- **Popisek vždy viditelný.** Placeholder mizí po napsání a výběr „Vše“ nic neříká. Popisek nad prvkem je čitelný vždy.
- **Konkrétní „všechny …“.** Obecné „Vše“ u selectu nepoznáš, co filtruje.
- **Jediné tlačítko „Zrušit filtr“.** Obnovit se nepoužíval a dvě tlačítka matla. Zrušit filtr se ukáže jen když má smysl.
- **Prázdný stav bez lišty.** Když nejsou data a nic se nefiltruje, lišta nemá co dělat.
- **Serverový režim přes `total`.** Logy mají tisíce záznamů, klientské stránkování by stahovalo všechno.
- **Číslované stránky s elipsou.** Při stovkách stránek nestačí Předchozí a Další.
