# 12. Daňový report

**Portfolio → Daňový report** — podklad pro přiznání k dani z příjmů fyzických osob za kalendářní
rok: prodeje cenných papírů a kryptoaktiv, zahraniční dividendy a přehled úroků. Všechno v Kč.

> ⚠️ Pozor: Report je orientační podklad, ne daňové poradenství. Počítá z transakcí v aplikaci —
> chybějící nebo špatně zadaný nákup změní výsledek. Před podáním přiznání ho ověř.

| Volba | Význam |
|---|---|
| Rok | Zdaňovací období (kalendářní rok) |
| Kurz | **Denní kurz ČNB** ke dni každého obchodu, nebo **jednotný kurz** za rok (průměr kurzů ČNB k poslednímu dni měsíců — stejné hodnoty, jaké vyhlašuje GFŘ). Nákup se přepočte jednotným kurzem roku nákupu. V jednom roce použij pro všechno jen jeden způsob |
| CSV | Detail prodejů po lotech a dividend pro Excel |
| Tisk | Tisková verze bez menu |

Jednotný kurz jde spočítat jen pro uzavřený rok, ke kterému jsou v aplikaci kurzy ČNB za všech
12 měsíců. Když chybí, report to napíše.

## 12.1 Prodeje

Cenné papíry (akcie, ETF, fondy) a kryptoaktiva jsou dva samostatné bloky — zisky a ztráty se mezi
nimi nesčítají. Uvnitř bloku se v roce vzájemně započítají (ztráta jednoho prodeje sníží zisk jiného);
celková ztráta se nepřenáší a základ je 0.

Každý prodej se rozpadne na **loty** (nákupy) metodou FIFO podle data nabytí
(viz [7.3 FIFO loty](07_Prehled.md#73-fifo-loty)). U lotu je:

| Sloupec | Význam |
|---|---|
| Příjem | Podíl hrubé prodejní ceny |
| Výdaj | Nákupní cena lotu včetně poplatku při nákupu + podíl poplatku z prodeje. Poplatek zaplacený kusy prodávaného aktiva je samostatný řádek „kusy na poplatek prodeje" (příjem 0, výdaj = jejich nákupní cena). Poplatek v jiném kryptu (např. BNB) kurz ČNB neocení — ve výdajích není a řádek na to upozorní |
| Nabytí | Datum nákupu; převod mezi účty datum nabytí zachovává |
| Osvobození | Proč je lot osvobozený, nebo „zdanitelné" |

Pravidla osvobození:

| Pravidlo | Cenné papíry | Kryptoaktiva |
|---|---|---|
| Limit | Úhrn **hrubých příjmů** z prodejů za rok do 100 000 Kč → osvobozené všechny prodeje | Stejně, ale počítají se jen prodeje od 15. 2. 2025 |
| Časový test | Lot držený **déle než 3 roky** (den prodeje je až po výročí 3 let) | Stejně, jen prodeje od 15. 2. 2025 |
| Pořadí | Nejdřív limit (do úhrnu jdou i loty, které by splnily časový test); při překročení se osvobodí jen loty s časovým testem | Stejně |
| Strop 40 000 000 Kč | Rok 2025: společný s kryptem | Rok 2025 společný s cennými papíry, od 2026 jen krypto |

Prodeje kryptoaktiv do 14. 2. 2025 a v dřívějších letech jsou zdanitelné. Směna kryptoměny za
stablecoin je prodej. Odměny ze stakingu vstupují do lotů hodnotou při připsání.

Dílčí základ daně patří do přílohy č. 2 přiznání (ostatní příjmy podle § 10).

> 🛈 Poznámka: Pozice s ručním oceněním (Portu, spořicí účty) nemají loty, v prodejích nejsou.
> Portu vydává vlastní daňový podklad.

## 12.2 Zahraniční dividendy

Dividendy po zemích — země je podle ISIN instrumentu (první dvě písmena). U každé země:

| Sloupec | Význam |
|---|---|
| Hrubě | Dividendy před srážkovou daní |
| Sraženo | Srážková daň (poplatek u dividendy — viz [5.1 Typy transakcí](05_Transakce.md#51-typy-transakcí)) |
| Započitatelné | Sražená daň, nejvýš 15 % hrubé dividendy |
| Česká daň 15 % | Orientační daň z hrubé dividendy |
| Doplatek | Česká daň − započitatelná část |

Smlouva o zamezení dvojího zdanění může zápočet omezit níž než na 15 % — ověř u konkrétní země.
Patří do přílohy č. 4 (samostatný základ daně) nebo na ř. 38 s přílohou č. 3 — viz [12.4](#124-podklad-pro-přiznání). České dividendy (ISIN `CZ…`) zdanil plátce srážkou, report je jen zmíní.

## 12.3 Úroky

Úroky ze spořicích účtů po pozicích. Česká banka daň srazila sama, do přiznání se neuvádějí —
přehled je informativní.

## 12.4 Podklad pro přiznání

Pod souhrnem prodejů je karta **Podklad pro přiznání**. Nejdřív zvolíš, jak přiznání podáváš — aplikace si volbu
pamatuje a podle ní nabídne výstup:

| Volba | Co dostaneš |
|---|---|
| Sám v EPO / Moje daně | Hodnoty k přepsání do řádků tiskopisu DPFO v celých Kč |
| Přes daňového poradce | Souhrn (zdanitelné a osvobozené příjmy, dividendy, úroky) a soubory k předání: detail prodejů po lotech a podklad v CSV |
| Jiným programem | CSV s řádky formuláře (oddíl; řádek; popis; Kč) a detail prodejů |

Máš-li zahraniční dividendy, zvolíš i způsob zdanění:

- **Samostatný základ daně (§ 16a)** — příloha č. 4: ř. 401a (hrubé dividendy), 406, 409 (zaokrouhleno na sta Kč dolů),
  410 (daň 15 %), 411–413 (zápočet daně sražené v zahraničí, nejvýš 15 % a nejvýš daň z ř. 410), 414 → ř. 74a. Bez vlivu na progresi a slevy,
  proti této dani ale nejde uplatnit odčitatelné položky ani slevy.
- **Obecný základ daně** — dividendy na ř. 38, zápočet přes přílohu č. 3 za každý stát: ř. 321 (příjmy) a 323 (daň
  zaplacená v zahraničí); ř. 322 (výdaje) se u dividend neuvádí. Řádky 324–329 dopočítá EPO z celého přiznání.

Prodeje jdou do přílohy č. 2: každý druh na samostatný řádek tabulky — **D** prodej cenných papírů, **C** kryptoaktiva
(pokyny k tiskopisu kód pro kryptoaktiva neuvádějí, běžně se používá C — prodej movitých věcí), pak ř. 207 (příjmy),
ř. 208 (výdaje, u každého druhu nejvýš do výše příjmů) a ř. 209 → ř. 40 základní části. Osvobozené prodeje (časový
test, limit 100 000 Kč) se do přiznání neuvádějí.

> ⚠️ Pozor: Čísla řádků jsou ověřená na tiskopisech za roky 2023–2025; pro jiný rok podklad upozorní. Podklad
> nevyplní zbytek přiznání (zaměstnání, slevy, osobní údaje) a EPO neumí načíst CSV — hodnoty přepiš a před podáním
> zkontroluj. Úroky ze zahraničních účtů (u brokera) patří také k § 8 — report je od tuzemských úroků nerozliší.

