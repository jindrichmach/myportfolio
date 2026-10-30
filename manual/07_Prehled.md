# 7. Přehled a výpočty

Přehled je úvodní stránka aplikace (**Přehled** v menu). Ukazuje, kolik mají
investice hodnotu, kolik do nich šlo a kolik vydělaly. Vše je v **měně
zobrazení**, kterou si nastavíš v [profilu](03_Prihlaseni_a_zabezpeceni.md#33-profil)
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
| Krypto napříč účty | Každá kryptoměna sečtená přes všechny účty (burzy, peněženky), celkový výnos v Kč i % (stejný jako u pozic v tabulce níž, tedy včetně prodaných kusů, odměn a poplatků); je-li na víc účtech, kliknutím kamkoli na řádek se rozbalí na jednotlivé účty (na jednom účtu vede klik rovnou do detailu pozice) |
| Portfolia | Každé portfolio jako jeden řádek: počet pozic, podíl na celku, hodnota a celkový výnos v Kč i %. Kliknutím se rozbalí tabulka pozic (množství, hodnota, investováno, celkový výnos; u ETF a akcií je pod tickerem i název fondu či firmy — stejný ticker může na jiné burze znamenat jiný fond); rozbalená portfolia si prohlížeč pamatuje. **Všechny pozice** vede na stránku Pozice |

Tlačítko **Přidat** v záhlaví nabídne **Novou investici** (průvodce: instrument podle tickeru, ISINu nebo názvu —
případně založení nového —, pak účet a portfolio; existující pozici použije, jinak ji založí a hned otevře zápis
prvního nákupu), **Novou transakci** a **Import**. Nadpis **Alokace** vede na stránku Alokace.

Dokud nemáš žádné pozice, ukazuje Přehled místo čísel checklist prvních kroků (účet, portfolio, import nebo ruční
nákup) — stejnou cestu popisuje [0. Za 5 minut k prvnímu přehledu](00_Prvni_kroky.md).

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
(zisk, výnos, čisté vklady) a **Výkonnost** (TWR, XIRR, inflace, benchmark). Výkonnost je sbalená pod
**Podrobnosti** — rozbalíš ji jedním klikem a volba se zapamatuje; ikona ⓘ
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

