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

Akce u položek (řádek tabulky, karta) jsou ikonová tlačítka. Název akce ukáže
popisek po najetí myší nebo při přechodu klávesou Tab.

| Ikona | Akce |
|---|---|
| tužka | Upravit / přejmenovat |
| koš (červená) | Smazat, odebrat, zrušit nebo opustit sdílení |
| šipka › | Detail |
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

Delší seznamy mají nad tabulkou lištu filtrů. Každý filtr má nad sebou popisek, první je vždy
**Hledat**. Na mobilu jsou ostatní filtry schované pod tlačítkem **Filtry** s počtem nastavených.
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
| Týdenní report, měsíční a roční souhrn e-mailem | [7.6](07_Prehled.md#76-týdenní-report-e-mailem) |
| Upozornění e-mailem (cenové hranice, denní pohyb, časový test, alokace, problémy s daty) | [7.7](07_Prehled.md#77-upozornění-e-mailem) |
| Měsíční a roční souhrny, přehled dividend a úroků | [7.8](07_Prehled.md#78-měsíční-a-roční-souhrn), [7.9](07_Prehled.md#79-dividendy-a-úroky) |
| Cílová alokace a rebalancing | [7.10](07_Prehled.md#710-cílová-alokace-a-rebalancing) |
| Čisté jmění (nemovitosti, další majetek, úvěry) | [7.11](07_Prehled.md#711-čisté-jmění) |
| Bankovní e-mailová avíza (Fio, Air Bank, Moneta, Raiffeisenbank, ČSOB, Česká spořitelna, CREDITAS, UniCredit) | [8](08_Bankovni_aviza.md) |
| Daňový report (cenné papíry, krypto, zahraniční dividendy, úroky) a podklad pro přiznání | [10](10_Danovy_report.md) |
| Sdílení s partnerem a přehled domácnosti | [11](11_Sdileni.md) |
| API tokeny a popis API (OpenAPI) | [3.7](03_Prihlaseni_a_zabezpeceni.md#37-api) |
