# 5. Transakce

Transakce jsou **zdroj pravdy** — z nich se počítá držené množství, a později
i nákupní cena, zisk a daně. Zakládají se v **detailu pozice** (Nová transakce)
nebo v přehledu **Portfolio → Transakce**, kde vybereš i pozici.

## 5.1 Typy transakcí

| Typ | Tržní pozice | Ruční ocenění | Co vyplnit |
|---|---|---|---|
| Nákup | ✅ | — | množství + celková částka **nebo** cena za kus |
| Prodej | ✅ | — | množství + celková částka **nebo** cena za kus |
| Odměna / staking | ✅ | — | množství, volitelně hodnota v době připsání |
| Split | ✅ | — | poměr (10 = z 1 kusu je 10; 0.5 = reverse split 2:1) |
| Vklad | — | ✅ | částka |
| Výběr | — | ✅ | částka |
| Dividenda, Úrok | ✅ | ✅ | částka |
| Poplatek | ✅ | ✅ | částka (samostatný poplatek, např. za vedení účtu) |
| Převod | ✅ | — | zakládá se tlačítkem **Převést** — viz [5.3](#53-převod-mezi-účty) |

Split rozdělí nebo sloučí kusy všech lotů pozice; nabývací cena a datum nabytí zůstávají. Když broker při reverse
splitu vyplatí zlomek kusu v penězích, zapiš ho po splitu jako **Prodej** toho zlomku za vyplacenou částku.

## 5.2 Formulář

| Pole | Význam |
|---|---|
| Datum | Datum a čas provedení; nesmí být v budoucnosti |
| Množství | Kusy / coiny, až 18 desetinných míst |
| Celková částka / Cena za kus | U nákupu a prodeje přepínač, jak cenu zadáš: **Celková částka** (hrubá částka obchodu **bez** poplatku), nebo **Cena za kus**. Vyplníš jen jedno, druhé se dopočítá z množství |
| Částka | U ostatních typů (vklad, výběr, dividenda, úrok…) částka pohybu |
| Měna | Výběr z CZK a měn s kurzem ČNB; výchozí je měna pozice (lze změnit, např. SOL placený v CZK) |
| Poplatek + měna poplatku | Např. `12.5 EUR` nebo `0.00005 BTC`; měnou může být i symbol kryptoměny |
| Poznámka | Volitelně |

Jak se poplatek započítá:

| Poplatek | Nákup | Prodej |
|---|---|---|
| Ve fiat měně (EUR, CZK…) | přičte se k nákupní ceně | sníží výnos z prodeje |
| V měně instrumentu (např. BTC u BTC pozice) | sníží nakoupené množství | spotřebuje další kusy (FIFO) |
| V jiné kryptoměně (např. BNB) | jen se eviduje | jen se eviduje |

Čísla piš s desetinnou **čárkou i tečkou**, mezery jako oddělovač tisíců jsou
povolené (`1 234,56`).

> ⚠️ **Nejde prodat víc, než pozice držela.** Kontroluje se celá historie:
> prodej s datem před nákupem odmítne i tehdy, když je dnešní stav v pořádku.
> Stejně tak výběr nad součet vkladů. Pro opravu nejdřív uprav nebo přidej
> starší transakci.

## 5.3 Převod mezi účty

Když přesuneš coiny z burzy do peněženky (nebo mezi burzami), nejde o prodej.
V detailu tržní pozice klikni **Převést**:

| Pole | Význam |
|---|---|
| Na pozici | Pozice **stejného instrumentu** na jiném účtu (založ ji předem) |
| Množství | Kolik se přesouvá; tlačítko **Vše** vyplní celé držené množství (poplatek v převáděné měně odečte) |
| Poplatek + měna | Volitelně; síťový poplatek zadej v převáděné měně (např. `0.00005 BTC`) |

Vzniknou dvě spojené transakce: **Převod — odchod** a **Převod — příjem**.
Nákupní cena i datum nabytí se přenesou (důležité pro časový test u daní).
Poplatek v převáděné měně sníží množství, ale jeho nákupní cena přejde na
převedené kusy — převod tak nevytváří zisk ani ztrátu. Poplatek ve fiat měně
se započte jako samostatný náklad pozice.
Převod nejde upravit; smazáním jedné části se smaže celý převod.

## 5.4 Přehled transakcí

**Portfolio → Transakce** — všechny transakce s filtry **Portfolio**, **Účet**,
**Typ** a **Období** (viz [1.4 Seznamy s filtry](01_Uvod.md#seznamy-s-filtry)). U každého řádku je odkaz na pozici,
Upravit a Smazat. Transakce jedné pozice jsou i na jejím detailu, se stejným stránkováním.

Množství má znaménko podle směru pohybu kusů: **+**, když na pozici přibývají (nákup, odměna,
převod dovnitř), **−**, když ubývají (prodej, převod ven). Směr barevně rozlišuje štítek typu transakce.
Částka má znaménko podle peněžního toku: **+**, když peníze přišly (prodej, dividenda, úrok, vklad), **−**, když
odešly (nákup, výběr, poplatek). Odměna a převod jsou bez znaménka.

## 5.5 Import z burzy / brokera

**Portfolio → Import** (nebo tlačítko **Import** na stránce Transakce) — nahraješ export
transakcí z burzy nebo brokera, zkontroluješ náhled a potvrdíš. Nic se nezapíše, dokud import
nepotvrdíš.

| Zdroj | Jaký soubor stáhnout |
|---|---|
| Coinbase | Statements → výpis (CSV), i výpis účtu Coinbase Pro — [návod](#551-export-z-coinbase) |
| Anycoin | Transakce → Všechny měny → Export (CSV), nebo Objednávky → Export (CSV) — [návod](#552-export-z-anycoinu) |
| Coinmate | Historie → CSV All (česky i anglicky) — [návod](#553-export-z-coinmate) |
| Binance | Export Transaction Records (CSV nebo XLSX) — [návod](#554-export-z-binance) |
| Kraken | Ledgers (`ledgers.csv`, i v ZIPu) — [návod](#555-export-z-krakenu) |
| XTB | Historie → Peněžní operace → Export (XLSX, starší CSV) — [návod](#556-export-z-xtb) |
| Trading 212 | Historie → Export (CSV) — [návod](#557-export-z-trading-212) |
| Revolut | Invest → Dokumenty → Výpis účtu — [návod](#558-export-z-revolutu) |
| DEGIRO | `Transactions.csv` (obchody) a `Account.csv` (dividendy) — zvlášť nebo spolu v ZIPu — [návod](#559-export-z-degiro) |
| Interactive Brokers | Activity Statement (CSV) nebo Flex Query (CSV) — [návod](#5510-export-z-interactive-brokers) |

| Pole | Význam |
|---|---|
| Účet | Účet u burzy / brokera, ze kterého export pochází — do něj se zapíšou pozice |
| Portfolio | Portfolio, do kterého se založí nové pozice |
| Zdroj | Obvykle se pozná sám z hlavičky souboru; jinak ho vyber — pod polem se ukáže krátká nápověda a odkaz na návod |
| Soubor | CSV, XLSX nebo ZIP, nejvýš 20 MB |

Návody níže popisují, jak export stáhnout. Burzy a brokeři menu občas přejmenují — když se popisek
liší, hledej podobně pojmenovanou volbu. Názvy v závorce jsou z anglického rozhraní. Stahuj vždy
**celou historii** (od založení účtu) a formát **CSV** nebo **Excel**, ne PDF. Překrývající se
soubory nevadí — už importované řádky se přeskočí ([5.5.12](#5512-opakovaný-import)).

### 5.5.1 Export z Coinbase

CSV s transakcemi stáhneš na stránce **Statements** (Výpisy):

1. Přihlas se na coinbase.com.
2. Klikni vpravo nahoře na svůj profilový avatar → **Settings** → **Accounts** a v menu vyber **Statements**.
   Stránku můžeš otevřít i rovnou: `coinbase.com/statements`.
3. Zvol jednu z možností:
   - **Měsíční výpis** — u vybraného měsíce zvol formát **CSV** a stáhni ho.
   - **Vlastní výpis** (custom statement) — vyber aktivum (např. **All assets**), typ transakce
     (**All transactions**) a období, formát **CSV** a klikni na **Generate**. Hodí se pro celou historii najednou.

Převody krypto za krypto (Convert, Advanced Trade) se zapíšou jako prodej + nákup.

Výpis z dřívějšího **Coinbase Pro** je na stejné stránce v záložce **Other**. Stáhni výpis účtu v CSV
(sloupce `portfolio, type, time, amount, balance, …`) a importuj ho jako Coinbase — formát se rozpozná sám:

- obchod (řádky `match` a `fee` se stejným order a trade id) = nákup nebo prodej s poplatkem,
- obchod za USDT nebo USDC = nákup + prodej stablecoinu oceněný v USD,
- obchod krypto za krypto bez fiat ani stablecoinu (např. DOGE → BTC) se přeskočí — zadej ho ručně,
- vklad a výběr kryptoměny = příchozí a odchozí převod, vklady a výběry fiat měn (EUR, USD) se neimportují.

Oficiální nápověda:
[Coinbase — statements](https://help.coinbase.com/en/coinbase/taxes/tools/statements),
[Coinbase Pro — cost basis](https://help.coinbase.com/en/coinbase/managing-my-account/tax-locate-cost-basis-document).

### 5.5.2 Export z Anycoinu

Anycoin nabízí dva exporty; stačí jeden z nich.

- **Historie transakcí** (doporučený): **Můj účet → Transakce → Všechny měny → Export (CSV)**.
  Obsahuje obchody, vklady, výběry do peněženky, staking i odměny.
- **Objednávky**: stránka **Objednávky** (`anycoin.cz/cs/orders`) → **Export** (CSV). Jen nákupy a prodeje.

Výběry kryptoměn do vlastní peněženky jsou v náhledu jako **Odeslání** — v části **Odeslání a příjmy** vyber druhou stranu
převodu, nebo je nech vynechat. Zrušené objednávky, blokace výběru a úvěry se přeskočí.
Oba exporty můžeš kombinovat: obchod, který už je importovaný z druhého exportu, se
spáruje a přeskočí ([5.5.11](#5511-náhled)).

### 5.5.3 Export z Coinmate

1. Na webu otevři menu vpravo nahoře → **Historie** (History).
2. Klikni na **CSV All** — stáhne se celá historie.

Hlavička souboru je česky nebo anglicky podle jazyka rozhraní, import zvládne obojí. Obchody
kryptoměny za kryptoměnu (např. ETH/BTC) se neimportují — zadej je ručně.

### 5.5.4 Export z Binance

1. Na webu **Assets → Asset History** (nebo **Wallet → Transaction History**).
2. Najeď na ikonu exportu a zvol **Export Transaction Records**.
3. Vyber období, **všechny účty a všechny coiny** (starší období přes **Customize**) → **Generate**.
4. Až je soubor připravený (přijde SMS / e-mail, může to trvat i hodiny), stáhni ho v okně
   **Export Transaction Records** — odkaz platí 7 dní.

Jeden export pokryje nejvýš rok a měsíčně jich jde vytvořit jen omezený počet — delší historii
stáhni po letech a nahraj postupně. Nepleť si ho s **Orders → Spot Order → Export Trade History**,
to je jiný soubor. Futures a margin se neimportují. Oficiální nápověda:
[Binance — Transaction History](https://www.binance.com/en/support/faq/990afa0a0a9341f78e7a9298a9575163).

### 5.5.5 Export z Krakenu

1. Na webu klikni na profil → **Documents**.
2. V části **Exports** zvol **Create Export → Ledgers**.
3. Začátek období nastav na datum založení účtu, nech vybraná všechna aktiva i všechna pole,
   formát **CSV** → **Generate**.
4. Export se připravuje minuty až dny a e-mail o tom nepřijde — vrať se na **Documents** a stáhni
   ho, až je ikona stažení aktivní.

Stažený ZIP můžeš nahrát rovnou, nebo z něj `ledgers.csv`. Oficiální nápověda:
[Kraken — export account history](https://support.kraken.com/articles/208267878-how-to-export-your-account-history).

### 5.5.6 Export z XTB

1. V xStation 5 otevři záložku **Historie** (History) → **Peněžní operace** (Cash operations).
2. Nastav období od založení účtu a exportuj do **Excelu**.

V mobilní aplikaci: profil → **Historie transakcí** → **Peněžní operace** → šipka vpravo nahoře →
**Nový report** → období a účet → **Vygenerovat**; soubor najdeš v historii reportů.

Každý účet (i měnový podúčet) exportuj zvlášť a nahraj ho do odpovídajícího účtu v MyPortfolio —
částky jsou v měně účtu. Množství a cenu import čte z komentáře operace. Oficiální nápověda:
[XTB — history of cash operations](https://www.xtb.com/int/help-center/our-platforms/history-of-cash-operations-web-and-mobile-app).

### 5.5.7 Export z Trading 212

1. ☰ Menu → **Historie** (History) → ikona exportu vpravo nahoře.
2. Vyber období (nejvýš 1 rok) a zaškrtni všechny typy dat — objednávky, dividendy, transakce.
3. Až je soubor připravený, stáhni CSV.

Delší historii exportuj po letech. Účty Invest a ISA exportuj zvlášť; export z CFD účtu se
neimportuje. Oficiální nápověda:
[Trading 212 — export trading data](https://helpcentre.trading212.com/hc/en-us/articles/360016898917-Can-I-export-the-trading-data-from-my-account).

### 5.5.8 Export z Revolutu

1. V aplikaci otevři **Invest** → **Více** (More) → **Dokumenty** (Documents) a vyber brokerský účet.
2. Zvol **Výpis účtu** (Account statement), formát **Excel** a období od začátku obchodování.

Dividendy jsou ve výpisu v čisté výši (bez srážkové daně) a split uvádí jen přidané kusy —
splity zadej ručně. Peněžní fondy (Flexible Cash Funds) ve výpisu akcií nejsou.

### 5.5.9 Export z DEGIRO

Oba soubory najdeš ve **Schránce** (Inbox) — na webu v levém menu, v aplikaci vpravo dole:

- **Transakce** (Transactions) — obchody, soubor `Transactions.csv`;
- **Výpis z účtu** (Account statement) — dividendy, soubor `Account.csv`.

U obou nastav **počáteční datum** před první obchod (výchozí je jen poslední měsíc), klikni na
export a zvol **CSV**. Soubory nahraj zvlášť, nebo oba v jednom ZIPu. Daňové PDF se neimportují.
Oficiální nápověda:
[DEGIRO — reports](https://www.degiro.com/uk/helpdesk/tax/tax-treaties/which-reports-are-there-and-where-can-i-find-them).

### 5.5.10 Export z Interactive Brokers

**Activity Statement** — jednorázově:

1. V Client Portalu **Performance & Reports → Statements**.
2. U **Activity** klikni na šipku (Run), období **Annual** a rok.
3. V **Advanced Options** zvol formát **CSV** → **Download**.

Vlastní rozsah je nejvýš 365 dní — historii stahuj po letech.

**Flex Query** — když stahuješ pravidelně:

1. **Performance & Reports → Flex Queries** → **+** u **Activity Flex Query**.
2. Přidej sekce **Trades** a **Cash Transactions** (v každé vyber všechna pole), formát **CSV** → **Create**.
3. Query spusť (Run), zvol období a soubor stáhni.

Importují se akcie a ETF; dividenda se spojí se srážkovou daní. Oficiální nápověda:
[IBKR — Activity Flex Query](https://www.ibkrguides.com/clientportal/performanceandstatements/activityflex.htm).

### 5.5.11 Náhled

- **Souhrn** — kolik řádků je nových, už importovaných, spárovaných a přeskočených.
- **Instrumenty** — ke každému symbolu z exportu vyber **existující** instrument z katalogu,
  **založ nový** (symbol, název, třída a měna kotace jsou předvyplněné) nebo ho **vynech**.
  Známé instrumenty se spárují samy podle ISIN nebo symbolu.
- **Odeslání a příjmy** (krypto) — převod mezi vlastními účty zachová nákupní cenu i datum
  nabytí. Vyber druhou stranu: existující pozici, nebo jiný účet (pozice se založí). Bez výběru
  se řádek vynechá.
- **Řádky** — všechny pohyby se stavem; u přeskočených je důvod.
- **Spárované** — nákup nebo prodej, který už na účtu máš zadaný ručně (stejný instrument, typ a množství,
  čas nejvýš den vedle). Import ho nezapíše podruhé: ručně zadanou transakci přepíše časem, částkou a
  poplatkem z exportu (poznámka zůstane) a přiřadí ji ke zdroji, takže ji další import pozná jako už
  importovanou. Vrácení dávky spárované transakce nesmaže. Transakce z jiného importu se jen přeskočí.

| V exportu | V MyPortfolio |
|---|---|
| Nákup, prodej | Nákup, prodej (částka bez poplatku + poplatek) |
| Směna kryptoměny za kryptoměnu s hodnotou ve fiat (Coinbase Convert) | Prodej + nákup ve stejné hodnotě |
| Směna kryptoměny za stablecoin (USDC, USDT…) | Prodej + nákup oceněné v USD |
| Směna kryptoměny za kryptoměnu bez hodnoty ve fiat | přeskočeno — zadej ručně |
| Staking, odměny, earn | Odměna |
| Dividenda se srážkovou daní | **Dividenda** v hrubé výši, daň jako poplatek |
| Split | Split (u Revolutu, který uvádí jen přidané kusy, přeskočeno) |
| Vklad a výběr hotovosti, směna měn, úrok z hotovosti, karta | přeskočeno |
| Futures, margin, opce, CFD, forex | přeskočeno |

> 🛈 Poznámka: Revolut uvádí dividendy jen v čisté výši — srážková daň ve výpisu chybí.
> Trading 212 uvádí srážkovou daň v měně instrumentu; dividenda se pak zapíše v té měně.

### 5.5.12 Opakovaný import

Každý řádek si pamatuje své ID z exportu (nebo otisk řádku, když export ID nemá). Stejný
nebo překrývající se soubor můžeš nahrát znovu — už importované řádky se přeskočí.

### 5.5.13 Vrácení importu

Dole na stránce je **Historie importů**. Tlačítko **Vrátit import** smaže všechny transakce
dávky (u převodu obě části). Když by po vrácení pozdější transakce přestaly sedět (prodej
víc kusů, než pozice drží), vrácení se odmítne.

> 💡 Tip: Náhled platí 24 hodin. Pozice, které import založil, po vrácení zůstanou prázdné — smaž je ručně.

## 5.6 Napojení burzy přes API

**Účet → Burzy (API)** — místo ručního nahrávání exportů si MyPortfolio stahuje pohyby přímo
z burzy. Podporované burzy: **Coinbase**, **Binance**, **Kraken** a **Coinmate** (Anycoin API nemá —
zůstává import CSV).

Na burze vytvoř API klíč **jen pro čtení** — bez práva obchodovat a vybírat. Klíč se uloží
zašifrovaný a v aplikaci už ho nikdo neuvidí (v seznamu je jen začátek veřejné části).

| Burza | Co zadat | Potřebná práva klíče |
|---|---|---|
| Coinbase | Název klíče (`organizations/…/apiKeys/…`) a privátní klíč (CDP / Advanced Trade) | View |
| Binance | API klíč a Secret key | Enable Reading |
| Kraken | API klíč a Private key | Query Ledger Entries |
| Coinmate | Client ID, Public key, Private key | Jen čtení (bez obchodování a výběrů) |

| Pole | Význam |
|---|---|
| Účet | Účet u burzy — do něj se zapisují pozice (jako u importu) |
| Portfolio | Kam se založí nové pozice |
| Odkud stahovat | **Navázat na poslední import** (od poslední transakce na účtu, např. po dřívějším importu CSV), **Od data**, nebo **Celá historie** |
| Synchronizovat automaticky | Vypnuté napojení stáhne data jen tlačítkem **Synchronizovat teď** |

Tlačítkem **Ověřit klíč** zkontroluješ klíč před uložením. Klíč s právem obchodovat nebo vybírat
(Binance, Kraken, Coinbase to umí zjistit) aplikace neuloží — vytvoř na burze klíč jen pro čtení. Správce to
může výjimečně povolit v `cfg.php` (`exchanges.allow_dangerous_keys`); pak uvidíš jen varování. Coinmate práva
ověřit neumí, proto u něj varování uvidíš vždy.

### 5.6.1 Jak synchronizace probíhá

- Zapnutá napojení se synchronizují **každou hodinu** (úloha `cron-exchange-sync`), ručně
  tlačítkem **Synchronizovat teď**.
- Pohyby se převedou stejně jako při importu souboru ([5.5](#55-import-z-burzy--brokera)) a zapíšou
  se jako importní dávka — jde ji vrátit v **Historii importů**.
- Když není co rozhodovat, zapíše se vše hned. Když se objeví **nový instrument** nebo
  **odeslání / příjem** krypta, synchronizace se zastaví ve stavu **Čeká na rozhodnutí**: odkaz
  **Otevřít náhled importu** otevře náhled v Importu, kde rozhodneš a potvrdíš.
- Každá synchronizace stahuje i poslední týden znovu (pohyby, které burza dokončí se zpožděním);
  už zapsané pohyby se podle ID přeskočí. Co jsi v náhledu jednou vynechal, si napojení pamatuje a znovu
  to nenabízí; nový pohyb, který burza dodala se zpožděním, ale vždy počká na rozhodnutí.

> 🛈 Poznámka: Coinbase v API neuvádí poplatek u jednoduchého nákupu / prodeje (Buy / Sell) —
> částka obsahuje poplatek. U Binance se obchody hledají u párů z aktiv, která na burze držíš, která se objevila
> v historii (vklady, výběry, Convert, odměny) nebo která už na napojeném účtu v aplikaci jsou (i uzavřené pozice).
> Obchod v páru, jehož aktivum se na účtu nikdy neobjevilo (koupeno i prodáno ještě před napojením), doplň importem CSV.

> 💡 Tip: Když náhled do 24 hodin nepotvrdíš, vyprší a další synchronizace stáhne stejné období znovu.
