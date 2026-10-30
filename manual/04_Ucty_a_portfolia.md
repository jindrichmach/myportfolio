# 4. Účty, portfolia a pozice

Než zapíšeš první transakci, potřebuješ mít: **instituci** a **instrument**
v katalogu, svůj **účet** u instituce, **portfolio** a v něm **pozici**.

## 4.1 Katalog — instituce

**Účty a katalog → Instituce.** Burzy, brokeři, peněženky, robo-advisoři, banky a penzijní společnosti.
Katalog je **sdílený** všemi uživateli: založit instituci může každý, upravit
nebo smazat jen admin.

| Pole | Význam |
|---|---|
| Název | Unikátní v celé instalaci |
| Typ | Burza / Broker / Peněženka / Robo-advisor / Banka / Penzijní společnost |
| Web | Volitelný odkaz |

Instituci, na kterou už někdo navázal účet, nelze smazat — admin ji může
deaktivovat. Seznam jde prohledat podle názvu a filtrovat podle typu (viz [1.4 Seznamy s filtry](01_Uvod.md#seznamy-s-filtry)).

## 4.2 Katalog — instrumenty

**Účty a katalog → Instrumenty.** Co se dá držet: krypto, ETF, akcie, fondy.
Vyhledávat lze podle symbolu, názvu nebo ISIN a filtrovat podle třídy aktiv (viz [1.4 Seznamy s filtry](01_Uvod.md#seznamy-s-filtry)).

| Pole | Význam |
|---|---|
| Třída aktiv | Krypto / ETF / Akcie / Fond / Hotovost |
| Symbol | Např. `BTC`, `CSPX` (převede se na velká písmena) |
| Název | Plný název |
| Kotace | Měna, ve které se instrument obchoduje (`EUR`, `USD`) |
| ISIN | Volitelně; kontroluje se formát |
| Zdroj ceny | CoinGecko / XTB / Yahoo Finance / Ručně — viz [6. Ceny a kurzy](06_Ceny_a_kurzy.md) |
| Desetinná místa | Kolik míst množství zobrazovat |
| Identifikátory u zdrojů | Jak instrument znají jednotlivé zdroje cen |

Stejný symbol ve stejné měně může v katalogu být jen jednou. Tentýž ETF na
různých burzách v různých měnách jsou dva instrumenty (např. `CSPX` v USD).

**Identifikátory u zdrojů** — příklady:

| Zdroj | BTC | CSPX | VWCE |
|---|---|---|---|
| CoinGecko | `bitcoin` | — | — |
| XTB | — | `CSPX.UK` | — |
| Yahoo | (výchozí `BTC-EUR`) | `CSPX.L` | `VWCE.DE` |

> 💡 U krypta Yahoo ticker vyplňovat nemusíš — použije se `SYMBOL-KOTACE`.

### 4.2.1 Ikony

Instituce i instrumenty mohou mít ikonu — zobrazí se v katalogu, u účtů, v seznamu
pozic, v detailu pozice, v Přehledu a u transakcí. Ruční pozice (spořicí účet, Portu)
ukazují ikonu instituce. Bez ikony se zobrazí kolečko s počátečními písmeny.

Ikonu nastavíš v úpravě položky (tužka v řádku seznamu **Účty a katalog → Instituce** nebo
**Účty a katalog → Instrumenty**), v poli **Ikona** nahoře ve formuláři — ukládá se hned, bez tlačítka Uložit.
U nové položky ji nastavíš až po uložení. Měnit ji může admin a uživatel, který položku založil (ten ve formuláři
mění jen ikonu, ostatní pole upravuje admin); ostatním se ikona jen zobrazí.

| Volba | Co udělá |
|---|---|
| Nahrát obrázek | Vlastní soubor z počítače (požadavky v tabulce níž) |
| Vybrat z knihovny — instrument | Loga správců ETF (iShares, Vanguard, L&G, Amundi, Xtrackers, SPDR, Invesco, WisdomTree, VanEck, HSBC, UBS, Franklin Templeton, ARK, Lyxor) a asi 100 nejběžnějších kryptoměn (sada cryptocurrency-icons, licence CC0); hledání podle názvu nebo symbolu, u ETF je předvyplněné první slovo názvu |
| Vybrat z knihovny — instituce | **Stáhnout logo** z webu instituce, nebo vybrat ze seznamu českých bank, brokerů, burz, peněženek a penzijních společností — u části z nich je logo přibalené v aplikaci (náhled v seznamu), u ostatních se stáhne z jejich webu |
| Odebrat | Zpět na kolečko s počátečními písmeny |

Většinu log institucí aplikace stáhne na tvůj pokyn z webu instituce (`apple-touch-icon` nebo ikona z úvodní
stránky, jen přes https a jen z veřejných adres) a uloží si je jako běžný nahraný obrázek; přibalená jsou jen loga
institucí, jejichž web stažení neumožňuje. Loga jsou ochranné známky svých vlastníků a slouží jen k označení. Když web logo v podporovaném formátu nemá, nahraj obrázek ručně.

| Požadavek | Hodnota |
|---|---|
| Formát | PNG, JPEG, WebP nebo GIF (SVG ne) |
| Velikost souboru | nejvýše 1 MB |
| Rozměr | nejvýše 4096 × 4096 px; uloží se zmenšená na 128 × 128 px |

> 💡 Nejlépe vypadá čtvercový obrázek s průhledným pozadím — průhlednost zůstane zachovaná.

## 4.3 Účty

**Účty a katalog → Účty.** Tvoje účty u institucí.

| Pole | Význam |
|---|---|
| Instituce | Z katalogu |
| Název | Např. „Hlavní", „Model T" |
| Měna | Základní měna účtu |
| Poznámka | Volitelně |

Účet s pozicemi nelze smazat — místo toho ho v úpravě **archivuj**. Archivované
účty zobrazíš přepínačem **Zobrazit archivované**. Seznam jde prohledat (název, instituce, poznámka)
a filtrovat podle instituce (viz [1.4 Seznamy s filtry](01_Uvod.md#seznamy-s-filtry)).

## 4.4 Portfolia

**Účty a katalog → Portfolia.** Tvoje vlastní seskupení pozic napříč účty, např.
„Dlouhodobé", „Spekulace", „Hotovost". Pořadí (v seznamech i na Přehledu) změníš přetažením řádku myší za úchyt ⠿ vlevo, nebo šipkami
**Posunout výš / níž** u portfolia (klávesnice, dotyk). Sloupec **Obsah** ukazuje, kolik pozic portfolio má („3 pozice“, „Zatím prázdné“); kliknutím otevřeš jeho pozice.
Kliknutím na název portfolia se otevřou jeho pozice.

## 4.5 Pozice

**Portfolio → Pozice** — přehled seskupený po portfoliích s drženým množstvím
a poslední cenou. U ruční pozice (Portu, spořicí účet) je místo ceny dnešní
hodnota a datum posledního ručního ocenění; držené množství jsou čisté vklady.
Novou pozici založíš tlačítkem **Nová pozice**:

| Pole | Tržní pozice | Ruční ocenění | Hotovost |
|---|---|---|---|
| Portfolio, účet | povinné | povinné | povinné |
| Instrument | vyhledání v katalogu | — | — |
| Název | — | např. „Portu — Vyvážená" | volitelně, jinak „Hotovost CZK" |
| Měna | podle kotace instrumentu | výchozí měna účtu | výchozí měna účtu |
| Úrok % p.a. | — | volitelně (spořicí účet) | — |
| Číslo účtu pro avíza | — | volitelně v úpravě pozice — viz [10. Bankovní avíza](10_Bankovni_aviza.md) | — |

Stejný instrument na stejném účtu může být ve dvou portfoliích (dvě pozice).
Instrument, režim ocenění a měnu pozice po založení změnit nejde — navazují na
ně transakce. Smazat jde jen pozici bez transakcí — pozici s transakcemi lze jen archivovat (v úpravě pozice).

**Detail pozice** otevřeš kliknutím kamkoli na řádek pozice (v seznamu pozic i v Přehledu; Ctrl/⌘ + klik
v novém panelu) nebo na její název. Tlačítko **⋯** na konci řádku nabídne další akce: **Detail**,
**Nová transakce**, **Převést** (tržní pozice) a **Nastavit zůstatek** (hotovost). Ukazuje držené množství (u ručního ocenění čisté vklady),
poslední cenu (v měně zobrazení stejně jako průměrná nákupní cena; cena z burzy v původní měně je menším
písmem pod ní), ocenění (hodnota, nákupní cena, výnos), u tržní pozice FIFO loty
a prodeje, u ruční pozice ruční ocenění — viz [7.3 FIFO loty](07_Prehled.md#73-fifo-loty) a [7.4 Ruční ocenění](07_Prehled.md#74-ruční-ocenění-portu-spořicí-účty).
V záhlaví jsou tlačítka **Upravit**, **Převést** (jen tržní pozice), **Smazat pozici (jen bez transakcí)**, **Nastavit zůstatek**
(jen hotovost) a **Nová transakce**, dole všechny transakce.

### 4.5.1 Hotovost na účtu

Volné peníze na účtu u burzy nebo brokera (EUR na Coinbase, CZK na XTB). U hotovosti nezadáváš vklady a výběry,
ale **zůstatek**: tlačítko **Nastavit zůstatek** v seznamu pozic nebo v detailu pozice → datum a aktuální zůstatek.
Rozdíl proti dosavadnímu zůstatku se zapíše jako vklad (zůstatek vzrostl) nebo výběr (klesl). Hodnota i investováno
tak odpovídají zůstatku a hotovost nemá žádný výnos — utracení hotovosti za nákup se ve výnosu portfolia
neprojeví jako ztráta. Pro každou měnu na účtu založ jednu hotovost.

Zůstatek jde nastavit nejdřív ke dni posledního pohybu na pozici. Úroky a poplatky za vedení můžeš zapsat jako
běžné transakce. Ruční ocenění se u hotovosti nepoužívá — ani zůstatek z bankovního avíza (avízo zapíše jen pohyb),
takže zůstatek je vždy součet pohybů. V přehledu a alokaci patří hotovost do třídy **Hotovost**.

### 4.5.2 Penzijní spoření a DIP

Penzijko i DIP jsou investice — počítají se do výnosu (TWR, XIRR, souhrny), alokace i čistého jmění.

- **Penzijní spoření** (doplňkové penzijní spoření, transformovaný fond): instituce typu **Penzijní společnost**,
  účet (např. „Doplňkové penzijní spoření") a **ruční pozice**. Jako **vklad** zapisuj jen své vlastní příspěvky;
  státní příspěvek a příspěvek zaměstnavatele nezapisuj — projeví se v **ručním ocenění** podle výpisu a počítají
  se tak jako výnos. Nezapisuj je ani jako úrok (úroky jdou do daňového reportu). V alokaci patří do třídy
  **Fondy a ruční pozice**.
- **DIP u brokera** (XTB, Portu…): samostatný účet u brokera (např. „DIP") s běžnými tržními pozicemi
  — nákupy a prodeje jako u jiného účtu, import exportu funguje stejně.
- **DIP v penzijní společnosti nebo fondu**: ruční pozice jako penzijní spoření.
