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
| Množství | Kolik se přesouvá |
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

## 5.5 Import z burzy / brokera

**Portfolio → Import** (nebo tlačítko **Import** na stránce Transakce) — nahraješ export
transakcí z burzy nebo brokera, zkontroluješ náhled a potvrdíš. Nic se nezapíše, dokud import
nepotvrdíš.

| Zdroj | Jaký soubor stáhnout |
|---|---|
| Coinbase | Transaction history (CSV) |
| Anycoin | Export historie (CSV) |
| Coinmate | Historie transakcí (CSV, česky i anglicky) |
| Binance | Transaction History (CSV nebo XLSX) |
| Kraken | Ledgers (`ledgers.csv`, i v ZIPu) |
| XTB | Historie → Peněžní operace → Export (XLSX, starší CSV) |
| Trading 212 | Historie → Export (CSV) |
| Revolut | Akcie → Dokumenty → Výpis účtu (CSV) |
| DEGIRO | `Transactions.csv` (obchody) a `Account.csv` (dividendy) — zvlášť nebo spolu v ZIPu |
| Interactive Brokers | Activity Statement (CSV) nebo Flex Query (CSV) |

| Pole | Význam |
|---|---|
| Účet | Účet u burzy / brokera, ze kterého export pochází — do něj se zapíšou pozice |
| Portfolio | Portfolio, do kterého se založí nové pozice |
| Zdroj | Obvykle se pozná sám z hlavičky souboru; jinak ho vyber |
| Soubor | CSV, XLSX nebo ZIP, nejvýš 20 MB |

### 5.5.1 Náhled

- **Souhrn** — kolik řádků je nových, už importovaných a přeskočených.
- **Instrumenty** — ke každému symbolu z exportu vyber **existující** instrument z katalogu,
  **založ nový** (symbol, název, třída a měna kotace jsou předvyplněné) nebo ho **vynech**.
  Známé instrumenty se spárují samy podle ISIN nebo symbolu.
- **Odeslání a příjmy** (krypto) — převod mezi vlastními účty zachová nákupní cenu i datum
  nabytí. Vyber druhou stranu: existující pozici, nebo jiný účet (pozice se založí). Bez výběru
  se řádek vynechá.
- **Řádky** — všechny pohyby se stavem; u přeskočených je důvod.

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

### 5.5.2 Opakovaný import

Každý řádek si pamatuje své ID z exportu (nebo otisk řádku, když export ID nemá). Stejný
nebo překrývající se soubor můžeš nahrát znovu — už importované řádky se přeskočí.

### 5.5.3 Vrácení importu

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

Tlačítkem **Ověřit klíč** zkontroluješ klíč před uložením; když má klíč zbytečně silná práva
(obchodování, výběry), uvidíš varování.

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
