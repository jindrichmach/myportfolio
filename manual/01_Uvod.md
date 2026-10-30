# 1. Úvod — co MyPortfolio umí

MyPortfolio je **self-hosted přehled osobních investic** na jednom místě: kryptoměny,
ETF a akcie, robo-advisor Portu i hotovost na spořicích účtech. Běží na vlastním
serveru nebo v Dockeru, data zůstávají u tebe.

Jedna instalace slouží **více uživatelům** — každý vidí jen svá data. Sdílený je
jen katalog institucí a instrumentů a ceny s kurzy.

Tento manuál je i přímo v aplikaci: **Účet → Manuál** (správce ho má v sekci **Systém**). Kapitoly
jsou ve svislých záložkách vlevo, u otevřené kapitoly i její oddíly. Kapitoly 2. Instalace
a 9. Administrace vidí jen správce.

## 1.1 Základní pojmy

| Pojem | Význam | Příklad |
|---|---|---|
| Instituce | Kde aktivum držíš | Coinbase, XTB, Portu, Air Bank |
| Instrument | Co držíš (sdílený katalog) | BTC, ETH, CSPX, VWCE |
| Účet | Tvůj účet u instituce | „XTB — Investiční účet" |
| Portfolio | Tvoje seskupení pozic | „Dlouhodobé", „Spekulace" |
| Pozice | Instrument na účtu v portfoliu, nebo ručně oceňovaná hodnota | BTC na Coinbase v portfoliu Dlouhodobé |
| Transakce | Co se s pozicí dělo | nákup, prodej, vklad, převod, dividenda |

## 1.2 Dva druhy pozic

- **Tržní** — krypto, ETF, akcie. Hodnota = počet kusů × tržní cena. Kusy se
  počítají z transakcí (nákup, prodej, odměna, převod, split).
- **Ruční ocenění** — Portu strategie, spořicí účet. Evidují se vklady a výběry,
  aktuální hodnotu zadáváš ručně (případně s úrokem p.a.).

## 1.3 Přesnost

Všechny částky, ceny a množství se počítají **přesně v desítkové soustavě**
(až 18 desetinných míst u množství) — žádné zaokrouhlovací chyby jako u čísel
s plovoucí čárkou. Aplikace nedovolí prodat víc kusů, než pozice v daný
okamžik držela.

## 1.4 Ovládání

Na počítači jde levé menu sbalit jen na ikony tlačítkem **Sbalit menu** dole v menu (název položky pak ukáže
popisek po najetí myší); volba se zapamatuje. Účty, portfolia a sdílený katalog instrumentů a institucí jsou
v menu pohromadě ve skupině **Účty a katalog**.

Řádek pozice je celý proklikem do detailu. Akce u položek (řádek tabulky, karta) jsou ikonová tlačítka. Název akce ukáže
popisek po najetí myší nebo při přechodu klávesou Tab. Vzácné a nevratné akce (Převést, Vrátit import,
Deaktivovat uživatele, Zrušit token) mají název napsaný přímo vedle ikony.

| Ikona | Akce |
|---|---|
| tužka | Upravit / přejmenovat |
| koš (červená) | Smazat, odebrat, zrušit nebo opustit sdílení |
| šipka › | Detail |
| tři tečky ⋯ | Menu dalších akcí u řádku (u pozic: Detail, Nová transakce, Převést, Nastavit zůstatek) |
| šipky nahoru / dolů | Posunout výš / níž (pořadí portfolií) |
| cenovka | Zadat cenu, Nastavit zůstatek, Ocenění / Zůstatky |
| obrázek | Změnit ikonu |
| dvě šipky | Převést mezi účty |
| blesk | Test připojení |
| kruhové šipky | Načíst teď, Synchronizovat teď, Znovu aktivovat, Poslat pozvánku znovu |
| zahnutá šipka zpět (červená) | Vrátit import |
| přehrát | Spustit nyní |
| přeškrtnuté kolečko (červená) | Deaktivovat uživatele / zrušit token |
| štít | Dvoufaktorové ověření (TOTP) |

### Seznamy s filtry

Delší seznamy mají nad tabulkou lištu filtrů. Každý filtr má nad sebou popisek. Seznamy s textem k hledání
(účty, instrumenty, instituce, uživatelé…) mají jako první filtr **Hledat**; Pozice a Transakce se filtrují jen
výběrem (portfolio, účet, typ, období). Na mobilu jsou filtry kromě prvního schované pod tlačítkem **Filtry**
s počtem nastavených.
Tlačítko **Zrušit filtr** se ukáže, jen když je nějaký filtr nastavený, a vrátí všechny do výchozího stavu.

Pod tabulkou je počet záznamů („1–25 z 137“), číslované stránky (první, poslední a pár kolem aktuální,
mezi nimi „…“) a volba **Na stránku** (10, 25, 50 nebo 100). Změna filtru nebo počtu na stránku vrátí
seznam na první stránku. Filtry se při obnovení stránky vynulují.

## 1.5 Co MyPortfolio umí

| Oblast | Kapitola |
|---|---|
| Účty, portfolia, pozice, transakce, převody mezi účty | [4](04_Ucty_a_portfolia.md), [5](05_Transakce.md) |
| Hotovost na účtech u burz a brokerů (zadávaný zůstatek) | [4.5.1](04_Ucty_a_portfolia.md#451-hotovost-na-účtu) |
| Penzijní spoření a DIP | [4.5.2](04_Ucty_a_portfolia.md#452-penzijní-spoření-a-dip) |
| Import z burz a brokerů (Coinbase, Anycoin, Coinmate, Binance, Kraken, XTB, Trading 212, Revolut, DEGIRO, Interactive Brokers) | [5.5](05_Transakce.md#55-import-z-burzy--brokera) |
| Napojení burz přes API (Coinbase, Binance, Kraken, Coinmate) s automatickou synchronizací | [5.6](05_Transakce.md#56-napojení-burzy-přes-api) |
| Kurzy ČNB, ceny krypta a ETF, ruční ceny | [6](06_Ceny_a_kurzy.md) |
| Ocenění, zisk/ztráta (FIFO), přehled | [7.1](07_Prehled.md#71-jak-se-počítá-hodnota-a-zisk) |
| Historie hodnoty, výnosové metriky (TWR, XIRR), benchmark, inflace a reálný výnos | [7.5](07_Prehled.md#75-vývoj-hodnoty-a-výnosové-metriky) |
| Týdenní report, měsíční a roční souhrn e-mailem | [8.1](08_Reporty_a_upozorneni.md#81-týdenní-report-e-mailem) |
| Upozornění e-mailem (cenové hranice, denní pohyb, časový test, alokace, problémy s daty) | [8.2](08_Reporty_a_upozorneni.md#82-upozornění-e-mailem) |
| Měsíční a roční souhrny, přehled dividend a úroků | [8.3](08_Reporty_a_upozorneni.md#83-měsíční-a-roční-souhrn), [8.4](08_Reporty_a_upozorneni.md#84-dividendy-a-úroky) |
| Cílová alokace a rebalancing | [9.1](09_Alokace_a_ciste_jmeni.md#91-cílová-alokace-a-rebalancing) |
| Čisté jmění (nemovitosti, další majetek, úvěry) | [9.2](09_Alokace_a_ciste_jmeni.md#92-čisté-jmění) |
| Bankovní e-mailová avíza (Fio, Air Bank, Moneta, Raiffeisenbank, ČSOB, Česká spořitelna, CREDITAS, UniCredit) | [10](10_Bankovni_aviza.md) |
| Daňový report (cenné papíry, krypto, zahraniční dividendy, úroky) a podklad pro přiznání | [12](12_Danovy_report.md) |
| Sdílení s partnerem a přehled domácnosti | [13](13_Sdileni.md) |
| API tokeny a popis API (OpenAPI) | [3.6](03_Prihlaseni_a_zabezpeceni.md#36-api) |
