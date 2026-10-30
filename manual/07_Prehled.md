# 7. Přehled a ocenění

Přehled je úvodní stránka aplikace (**Přehled** v menu). Ukazuje, kolik mají
investice hodnotu, kolik do nich šlo a kolik vydělaly. Vše je v **měně
zobrazení**, kterou si nastavíš v [profilu](03_Prihlaseni_a_zabezpeceni.md#34-profil)
(CZK, EUR nebo USD).

## 7.1 Jak se počítá hodnota a zisk

| Údaj | Výpočet |
|---|---|
| Hodnota | množství × poslední cena, přepočteno **dnešním** kurzem ČNB |
| Investováno | nákupní cena kusů, které ještě držíš, přepočtená **kurzem ke dni nákupu** |
| Nerealizovaný zisk | hodnota − investováno; u ručních pozic (spořicí účet, Portu) bez příjmů a poplatků, které už jsou v hodnotě |
| Realizovaný zisk | výnos z prodejů (kurzem ke dni prodeje) − nákupní cena prodaných kusů (kurzem ke dni nákupu) |
| Příjmy | dividendy, úroky a odměny (staking) kurzem ke dni připsání |
| Poplatky | samostatné poplatky brokerům a burzám kurzem ke dni zaplacení |
| Celkový výnos | nerealizovaný + realizovaný zisk + příjmy − poplatky |
| % | celkový výnos / nákupní cena všech kdy nakoupených kusů |

Protože se nákupní cena přepočítává kurzem ke dni nákupu, zahrnuje zisk
v měně zobrazení i vliv kurzu. Pokud jsi například koupil ETF v USD a dolar
mezitím posílil, promítne se to do zisku v Kč.

> 🛈 Čísla na Přehledu nejsou daňovým podkladem — daně se počítají vždy v CZK
> podle pravidel zákona o daních z příjmů.

### Chybějící cena nebo kurz

Když instrument nemá cenu nebo chybí kurz měny, hodnota pozice se **nedopočítá**.
Nikdy se nepoužije 0 ani kurz 1. Pozice je v tabulce označená výstražným trojúhelníkem a nezapočítá se
do součtů. Nahoře se objeví upozornění s počtem takových pozic. Cenu
doplníš v [Ceny a kurzy](06_Ceny_a_kurzy.md) nebo ručně u instrumentu.

## 7.2 Stránka Přehled

| Část | Obsah |
|---|---|
| Souhrn | **Hodnota portfolia** s celkovým výnosem v Kč a %, pod ní Investováno, Nerealizovaný zisk, Realizovaný zisk, Příjmy a Poplatky. Nerealizovaný + realizovaný zisk + příjmy − poplatky dají přesně celkový výnos; ikona ⓘ u každé dlaždice ukáže, co zahrnuje |
| Čisté jmění | Samostatný panel (jen když ho vedeš): investice + další majetek − závazky, odkaz na detail |
| Upozornění | Pozice bez ceny nebo kurzu, zastaralé ceny, chybějící ruční ocenění |
| Vývoj hodnoty | Graf a výnosové metriky za období — viz § 7.5 |
| Alokace | Donut s podíly podle třídy aktiv, portfolia nebo instituce (přepínač); uprostřed součet, po najetí na výseč nebo řádek legendy její částka a podíl. Máš-li cílovou alokaci, vedle je **Skutečnost a cíl** po třídách |
| Krypto napříč účty | Každá kryptoměna sečtená přes všechny účty (burzy, peněženky), celkový výnos v Kč i % (stejný jako u pozic v tabulce níž, tedy včetně prodaných kusů, odměn a poplatků); je-li na víc účtech, řádek se rozbalí na jednotlivé účty |
| Pozice | Tabulka po portfoliích (v záhlaví hodnota portfolia a jeho podíl na celku): množství, hodnota, investováno, celkový výnos v Kč i % |

Ruční pozice se v alokaci řadí jako **Hotovost** (účet u banky) nebo **Fond**
(ostatní, např. Portu).

## 7.3 FIFO loty

Každý nákup nebo odměna vytvoří **lot**: množství, datum nabytí a nákupní cenu
(u nákupu včetně poplatku). Prodej odebírá kusy z nejstarších lotů dané pozice
(**FIFO — first in, first out**) podle data nabytí — lot převedený z jiného účtu se starším
datem nabytí se prodá dřív než novější nákup na tomto účtu.

V detailu tržní pozice jsou dvě tabulky:

- **Otevřené loty** — co ještě držíš, od kdy a za kolik. Lot vzniklý převodem má
  u sebe „převod (datum nabytí zachováno)".
- **Prodeje (FIFO)** — každý prodej rozepsaný po lotech: datum prodeje a nabytí,
  počet dní držení, výnos, nákupní cena a zisk v měně zobrazení. Řádek
  s označením **Poplatek** je poplatek zaplacený v kusech instrumentu.

Loty se přepočítají automaticky po každé změně transakce (přidání, úprava,
smazání, převod). Při převodu se loty přesunou na cílovou pozici i s původním
datem nabytí — viz [Převod mezi účty](05_Transakce.md#53-převod-mezi-účty).

Odměna (staking) se ocení částkou z transakce. Bez částky se použije cena
instrumentu ke dni připsání. Když ani ta není, má odměna nákupní cenu 0
a pozice ukáže upozornění.

## 7.4 Ruční ocenění (Portu, spořicí účty)

U ručně oceňované pozice se hodnota počítá takto:

1. Vezme se **poslední ruční ocenění** (zůstatek podle výpisu k datu).
2. Přičtou se vklady a úroky **po tomto datu** a odečtou výběry a poplatky.
3. Bez ocenění je hodnota čisté vklady + úroky − poplatky.

Celkový výnos = hodnota − čisté vklady.

Ocenění zadáš v detailu pozice v části **Ruční ocenění**:

| Pole | Význam |
|---|---|
| Datum | Den, ke kterému zůstatek platí; ne v budoucnosti |
| Zůstatek | Hodnota pozice v měně pozice |
| Poznámka | Volitelně, např. „výpis 12/2024" |

Nové ocenění ke stejnému dni přepíše předchozí. Ocenění smažeš tlačítkem
**Smazat** (koš) v seznamu.

> 💡 U spořicího účtu, kam zapisuješ všechny vklady, výběry a úroky, ocenění
> nepotřebuješ — hodnota je přesná. U investiční služby typu Portu zadávej
> zůstatek z výpisu třeba jednou měsíčně, jinak pozice ukazuje jen vložené
> peníze a upozornění **Bez ručního ocenění**.

## 7.5 Vývoj hodnoty a výnosové metriky

Graf **Vývoj hodnoty** je na Přehledu a v detailu každé pozice. Plná čára je
hodnota, přerušovaná je investovaná částka (nákupní cena držených kusů, u ručních
pozic čisté vklady). Plocha mezi nimi ukazuje zisk (tyrkysová, hodnota nad
investovanou částkou) nebo ztrátu (červená). Hodnota každého dne se počítá stejně
jako na Přehledu: cenou a kurzem ČNB platnými ten den. Po najetí myší (na mobilu
po dotyku) ukáže svislá čára den a popisek hodnoty všech čar.

| Ovládání | Význam |
|---|---|
| Období | 1M, 3M, Letos, 1R, Vše (od první transakce) |
| Výběr (jen Přehled) | Celkem, jedno portfolio, nebo třída aktiv (krypto, ETF, hotovost…) |
| Porovnání (benchmark) | **Bez porovnání**, nebo **vs.** libovolný instrument z katalogu (Bitcoin je nabídnutý první); volba platí pro všechny grafy |

Metriky nad grafem se vztahují ke zvolenému období. Jsou ve dvou skupinách, **Za období**
(zisk, výnos, čisté vklady) a **Výkonnost** (TWR, XIRR, inflace, benchmark); ikona ⓘ
u každé metriky ukáže krátké vysvětlení (po najetí myší; klepnutím nebo kliknutím zůstane otevřené,
zavře ho další klepnutí, klik mimo nebo Escape):

| Metrika | Co říká |
|---|---|
| Zisk za období | konečná hodnota − počáteční hodnota − čisté vklady |
| Výnos | zisk / (počáteční hodnota + vklady v období) |
| TWR | časově vážený výnos — jak si vedly samotné investice bez ohledu na to, kdy a kolik jsi vložil; dá se srovnat s indexem. U období delšího než rok i přepočet na rok |
| XIRR | roční míra výnosu tvých peněz — zohledňuje načasování vkladů a výběrů |
| Čisté vklady | nákupy a vklady minus prodeje, výběry a vyplacené dividendy / úroky |
| Inflace ČR | růst cen za období (index HICP) a pod ním **reálné TWR** — výnos po odečtení inflace |
| Benchmark (symbol) | TWR zvoleného instrumentu za období a zisk, kdyby šly do něj tvoje peníze |

> 💡 TWR a XIRR se mohou výrazně lišit. Když jsi hodně nakoupil před poklesem,
> XIRR bude horší než TWR; když jsi přikupoval ve slevě, bude lepší.

### Benchmark a inflace

**Benchmark** ukazuje třetí čarou, kolik bys měl, kdyby počáteční hodnota a každý vklad i výběr ve stejný
den šly do zvoleného instrumentu (cenou a kurzem ČNB toho dne). Díky stejným tokům se porovnává jen výběr
investic, ne načasování. Po zvolení instrumentu se stáhne jeho historie cen od tvé první transakce a dál ji
udržuje úloha `cron-prices`. Když benchmark nemá ceny na začátku období, pod grafem je upozornění.
V legendě je čára popsaná **Stejné vklady do …** (např. Stejné vklady do VWCE). V detailu pozice téhož instrumentu
(BTC proti BTC) se benchmark nezobrazuje — srovnání by nic neříkalo.

**Inflace ČR** je harmonizovaný index spotřebitelských cen (HICP) z Eurostatu — liší se od národní inflace
ČSÚ jen nepatrně. Měří se od měsíce před začátkem období do posledního zveřejněného měsíce (Eurostat ho vydává
v polovině následujícího měsíce). Reálný výnos = (1 + výnos) / (1 + inflace) − 1, kde se inflace z dostupných
měsíců přepočte na roční míru a ta na stejnou délku jako období výnosu (index za poslední měsíce ještě nemusí být);
u období delšího než rok je pod grafem i reálné TWR ročně a reálné XIRR. Bez zveřejněného indexu se ukáže „—",
nikdy 0 %. V ročním souhrnu se inflace měří od stejného měsíce, od kterého se počítá výnos.

Převod mezi vlastními účty se v celku ani v portfoliu neprojeví jako vklad
(odchod a příjem se vyruší); v grafu jedné pozice ano.

Den, kdy některé pozici chybí cena nebo kurz, je v součtu neúplný — pod grafem
se objeví upozornění a metriky takový den přeskočí.

### Kdy se historie přepočítá

Historie se ukládá po dnech a přepočítá se sama:

- po přidání, úpravě nebo smazání transakce a po změně ručního ocenění —
  od data změny, při dalším otevření grafu;
- po doplnění historických cen nebo zadání ruční ceny;
- každou noc úloha `cron-snapshot` přepočítá posledních 7 dní
  (dohnané ceny a kurzy).

Dnešní den se přepočítá při každém otevření grafu, takže sedí s Přehledem.

## 7.6 Týdenní report e-mailem

Každé pondělí v 8:00 může přijít e-mail s přehledem posledních 7 dní. Zapneš ho v
**Účet → Profil → záložka Reporty**.

| Pole / tlačítko | Význam |
|---|---|
| Posílat týdenní report | Zapne pravidelné odesílání |
| Detailní přehled | Zaškrtnuté = portfolia, účty i jednotlivé instrumenty; nezaškrtnuté = jen portfolia a účty |
| Měsíční souhrn | 1. den v měsíci přijde souhrn za minulý měsíc ([7.8](#78-měsíční-a-roční-souhrn)); odkaz Náhled ukáže minulý měsíc |
| Roční souhrn | 1. ledna přijde souhrn za minulý rok včetně rozpadu po měsících; odkaz Náhled ukáže minulý rok |
| Příjemci | Až 5 adres oddělených čárkou; prázdné = tvůj přihlašovací e-mail |
| Náhled | Otevře e-mail v novém okně (nic se neodešle) |
| Odeslat teď | Pošle report hned na nastavené příjemce |

Co report obsahuje (v měně zobrazení a jazyce profilu):

- **Souhrn** — hodnota celého portfolia, změna hodnoty za 7 dní, vklady a výběry a výnos.
- **Portfolia → účty → pozice** — u každé úrovně hodnota před týdnem a dnes, změna, vklady a výběry
  a výnos; u účtu s jedinou pozicí je pozice uvedená přímo pod názvem účtu. Bez **detailního přehledu**
  jen portfolia a účty.
- **Transakce** za posledních 7 dní.

| Ukazatel | Výpočet |
|---|---|
| Změna hodnoty | hodnota dnes − hodnota před 7 dny |
| Vklady a výběry | nákupy a vklady − prodeje a výběry v období |
| Výnos | změna hodnoty − vklady a výběry; % = výnos / (hodnota na začátku + polovina vkladů) |

U účtu nebo portfolia bez vkladů a výběrů je výnos stejný jako změna hodnoty, proto se v řádku
uvádí jen změna. Výnos celého portfolia je vždy v souhrnu nahoře.

Čísla pocházejí z denní historie hodnoty (§ 7.5), takže odpovídají grafu. Pozice bez aktuální ceny
nebo kurzu má hodnotu „—" a součty jsou označené hvězdičkou.

> 🛈 Odeslání vyžaduje nastavené SMTP ([9.3 Nastavení](09_Administrace.md#93-nastavení)). Úloha `cron-weekly-report` pošle report
> při každém spuštění — pondělní 8:00 je dané rozvrhem cronu (viz [9.5 Plánované úlohy](09_Administrace.md#95-plánované-úlohy)). Měsíční a roční
> souhrn posílá úloha `cron-period-report` (1. den v měsíci 8:05); příjemci jsou stejní jako u týdenního reportu.

## 7.7 Upozornění e-mailem

Jednou denně (7:30) může přijít souhrn upozornění — jen když je co hlásit, a každé upozornění
přijde jen jednou. Nastavíš ho v **Účet → Profil → záložka Upozornění**.

| Pole / tlačítko | Význam |
|---|---|
| Posílat upozornění | Zapne denní souhrn |
| Denní pohyb portfolia (%) | Ohlásí portfolio (a všechna portfolia dohromady), jehož včerejší výnos byl aspoň o tolik procent nahoru nebo dolů. Prázdné = vypnuto |
| Denní pohyb pozice (%) | Totéž pro jednotlivé pozice. Prázdné = vypnuto |
| Časový test za (dní) | Ohlásí lot, který za tolik dní splní 3 roky držby — prodej od uvedeného dne je osvobozený od daně ([10. Daňový report](10_Danovy_report.md)). Prázdné = vypnuto |
| Alokace mimo toleranci | Třída, jejíž podíl se liší od cíle víc než o toleranci ([7.10](#710-cílová-alokace-a-rebalancing)); trvající odchylka se připomene nejvýš jednou za měsíc |
| Problémy s daty | Držený instrument bez ceny nebo se zastaralou cenou, zastaralé kurzy ČNB, avízo bez napojené pozice nebo s chybou, chyba IMAP schránky |
| Příjemci | Až 5 adres oddělených čárkou; prázdné = tvůj přihlašovací e-mail |
| Zkontrolovat teď | Vyhodnotí upozornění hned a pošle je, pokud je co hlásit |

Výnos u denního pohybu je změna hodnoty bez vkladů a výběrů (stejný výpočet jako v [7.6](#76-týdenní-report-e-mailem)),
za včerejší den v měně zobrazení. Upozornění na časový test vynechává odměny ze stakingu — denní
drobné loty by souhrn zahltily.

### 7.7.1 Cenové hranice

Ve stejné kartě přidáš hranici: instrument, **nad** / **pod** a cenu v měně kotace instrumentu
(volitelně poznámku). Když poslední cena hranici překročí, souhrn ji jednou ohlásí a u hranice se
zobrazí datum a cena. Tlačítkem **Znovu aktivovat** ji nastavíš znovu; úprava hranice ji také
znovu aktivuje.

> 🛈 Odeslání vyžaduje nastavené SMTP ([9.3 Nastavení](09_Administrace.md#93-nastavení)) a úlohu `cron-alerts`
> (denně 7:30, viz [9.5 Plánované úlohy](09_Administrace.md#95-plánované-úlohy)).

## 7.8 Měsíční a roční souhrn

**Portfolio → Souhrny** — tabulka a graf výnosu po měsících nebo letech (přepínač **Měsíce / Roky**),
volitelně jen pro jedno portfolio nebo účet. Vše v měně zobrazení z denní historie hodnoty (§ 7.5).

| Sloupec | Význam |
|---|---|
| Hodnota na konci | Hodnota k poslednímu dni období (u probíhajícího období k dnešku); pod ní hodnota na začátku |
| Vklady / výběry | Nákupy a vklady − prodeje a výběry v období |
| Výnos | Změna hodnoty − vklady a výběry; % = výnos / (hodnota na začátku + polovina vkladů) |
| Dividendy + úroky | Hrubě, kurzem ČNB ke dni platby; pod tím sražená daň |
| Poplatky | Transakce typu poplatek a poplatky u obchodů ve fiat měně |
| Inflace | Inflace ČR za měsíc (rok: prosinec / prosinec, u probíhajícího roku do posledního zveřejněného měsíce) a pod ní reálný výnos ([7.5](#benchmark-a-inflace)) |

Hvězdička u hodnoty znamená, že některá pozice neměla k tomu dni cenu nebo kurz a v součtu chybí — výnos takového
období (a období, které na něj navazuje) se nepočítá a ukáže se „—".
Stejná čísla posílá měsíční a roční souhrn e-mailem ([7.6](#76-týdenní-report-e-mailem)).

## 7.9 Dividendy a úroky

**Portfolio → Dividendy a úroky** — přijaté dividendy a úroky za vybraný rok (nebo všechny roky):

- **Souhrn** — dividendy a úroky hrubě, sražená daň a čistá částka.
- **Graf** po měsících vybraného roku (bez roku po letech), dividendy a úroky zvlášť.
- **Po pozicích** a **po letech**.
- **Platby** — každá výplata s původní částkou a měnou, čistou částkou a sraženou daní.

Částky jsou v měně zobrazení kurzem ČNB ke dni platby. Srážková daň je poplatek u dividendy
([5.1 Typy transakcí](05_Transakce.md#51-typy-transakcí)). Pro daňové přiznání slouží [10. Daňový report](10_Danovy_report.md).

## 7.10 Cílová alokace a rebalancing

**Portfolio → Alokace** — nastavíš cílové rozložení majetku a uvidíš, jak daleko od něj jsi a kam dát
další peníze. Nahoře vybereš rozsah: **Celý majetek**, nebo jedno portfolio (každé může mít vlastní cíle).
Počítá se vše včetně ručních pozic (Portu, spořicí účty) a hotovosti, v měně zobrazení.

| Třída | Co do ní patří |
|---|---|
| Krypto, ETF, Akcie | Tržní pozice podle třídy instrumentu |
| Fondy a ruční pozice | Ručně oceňované pozice (Portu, fondy) |
| Hotovost | Hotovost na účtech a spořicí účty u banky |

**Nastavit cíle** — ke každé třídě cílový podíl (součet 100 %) a toleranci v procentních bodech
(prázdné = 5). U krypta, ETF a akcií můžeš zaškrtnout **rozepsat po instrumentech** a zadat podíly
instrumentů uvnitř třídy (také součet 100 %); držený instrument bez cíle má cíl 0 %. Bez cílů formulář
předvyplní dnešní rozložení. **Smazat cíle** odstraní celou sadu pro vybraný rozsah.

- **Skutečnost a cíl** — hodnota a podíl třídy, cíl ± tolerance a odchylka v měně (+ nad cílem).
  Pruh ukazuje podíl, svislá čárka cíl. Třída mimo toleranci má štítek a na **Přehledu** se u alokace
  ukáže počet tříd mimo toleranci (celý majetek). Kliknutím na třídu rozbalíš instrumenty.
- **Plánuji vložit** — zadáš částku a dostaneš návrh nákupů bez prodeje: vklad se rozdělí mezi třídy
  (a instrumenty), kterým po vkladu nejvíc chybí do cíle, poměrně podle schodku.
- **Plný rebalancing** — prodeje a nákupy, po kterých bude rozložení přesně na cíli. Prodej třídy bez
  cílů instrumentů se rozdělí poměrně podle hodnoty pozic. U každé prodávané pozice je odhad kusů
  a kolik z nich pochází z lotů mladších 3 let (FIFO) — takový prodej může být zdanitelný
  ([10. Daňový report](10_Danovy_report.md)).

> 🛈 Poznámka: Pozice bez ceny nebo kurzu se do alokace nepočítají — stránka je vypíše nahoře.
> Návrhy jsou orientační: nepočítají s poplatky a nákup za třídu (bez cílů instrumentů) nechává výběr
> instrumentu na tobě.

## 7.11 Čisté jmění

**Portfolio → Čisté jmění** — celkový majetek: **investice** (ocenění všech pozic včetně hotovosti, stejně jako na
Přehledu) + **další majetek** − **závazky**, v měně zobrazení. Penzijko a DIP jsou investice — vedou se jako
pozice ([4.5.2](04_Ucty_a_portfolia.md#452-penzijní-spoření-a-dip)) a do čistého jmění se tak započítají samy. Nahoře jsou dlaždice, pod nimi graf vývoje
(čisté jmění, investice a závazky po dnech) a položky po druzích. Když máš aspoň jednu položku, ukáže se
čisté jmění i jako dlaždice na **Přehledu**.

| Druh | Jak se oceňuje |
|---|---|
| Nemovitosti | Ručně zadaná hodnota k datu (odhad, posudek); mezi oceněními platí poslední |
| Ostatní majetek | Ručně zadaná hodnota k datu (auto, podíl ve firmě, sbírka…) |
| Závazky | Úvěr s anuitními splátkami — zůstatek se dopočítá ke každému dni |

**Přidat položku** — druh, název, měna (cizí měna se přepočítá kurzem ČNB) a volitelně první ocenění. U závazku
zadáš **datum čerpání**, **jistinu**, **úrok % p. a.** a **měsíční splátku**: splátky se odečítají měsíčně ke dni
čerpání (úrok = zůstatek × úrok / 12), zůstatek neklesne pod nulu. U úvěru se zobrazí odhad doplacení; když
splátka nepokryje úrok, je tam upozornění.

Tlačítkem **Ocenění** (u závazku **Zůstatky**) zapíšeš novou hodnotu k datu — stejný den se přepíše:

- **Majetek** — nová hodnota; při prodeji zadej 0 k datu prodeje a položku pak archivuj (Upravit → Archivovat),
  ať historie sedí.
- **Závazek** — zůstatek k datu po mimořádné splátce, refixaci nebo podle výpisu banky; volitelně nový úrok
  a splátku (prázdné = beze změny). Od tohoto data se umořuje dál. Pod seznamem je výhled zůstatku na 12 měsíců.

Splacený úvěr (zůstatek 0) má u sebe tlačítko **Splaceno – archivovat**: zmizí ze seznamu, historie čistého jmění
zůstane. Archivované položky zobrazíš přepínačem **Zobrazit archivované** — jsou zašedlé a do součtů se nepočítají;
archivaci zrušíš v Upravit.

> 🛈 Poznámka: Výnosové metriky (TWR, XIRR, souhrny), alokace a daňový report položky čistého jmění nezahrnují —
> jsou jen přehled majetku. Graf čistého jmění vychází z denní historie investic, takže začíná první transakcí.

