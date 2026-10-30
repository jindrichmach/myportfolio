# 8. Reporty, upozornění a dividendy

Co ti aplikace sama pošle e-mailem a kde najdeš souhrny za období a přijaté dividendy a úroky.
Jak se počítají hodnota a výnos, je v [7. Přehled a výpočty](07_Prehled.md).

## 8.1 Týdenní report e-mailem

Každé pondělí v 8:00 může přijít e-mail s přehledem posledních 7 dní. Zapneš ho v
**Účet → Profil → záložka Reporty**.

| Pole / tlačítko | Význam |
|---|---|
| Posílat týdenní report | Zapne pravidelné odesílání |
| Detailní přehled | Zaškrtnuté = portfolia, účty i jednotlivé instrumenty; nezaškrtnuté = jen portfolia a účty |
| Měsíční souhrn | 1. den v měsíci přijde souhrn za minulý měsíc ([8.3](#83-měsíční-a-roční-souhrn)); odkaz Náhled ukáže minulý měsíc |
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

> 🛈 Odeslání vyžaduje nastavené SMTP ([11.4 Nastavení](11_Administrace.md#114-nastavení)). Úloha `cron-weekly-report` pošle report
> při každém spuštění — pondělní 8:00 je dané rozvrhem cronu (viz [11.6 Plánované úlohy](11_Administrace.md#116-plánované-úlohy)). Měsíční a roční
> souhrn posílá úloha `cron-period-report` (1. den v měsíci 8:05); příjemci jsou stejní jako u týdenního reportu.

## 8.2 Upozornění e-mailem

Jednou denně (7:30) může přijít souhrn upozornění — jen když je co hlásit, a každé upozornění
přijde jen jednou. Nastavíš ho v **Účet → Profil → záložka Upozornění**.

| Pole / tlačítko | Význam |
|---|---|
| Posílat upozornění | Zapne denní souhrn |
| Denní pohyb portfolia (%) | Ohlásí portfolio (a všechna portfolia dohromady), jehož včerejší výnos byl aspoň o tolik procent nahoru nebo dolů. Prázdné = vypnuto |
| Denní pohyb pozice (%) | Totéž pro jednotlivé pozice. Prázdné = vypnuto |
| Časový test za (dní) | Ohlásí lot, který za tolik dní splní 3 roky držby — prodej od uvedeného dne je osvobozený od daně ([12. Daňový report](12_Danovy_report.md)). Prázdné = vypnuto |
| Alokace mimo toleranci | Třída, jejíž podíl se liší od cíle víc než o toleranci ([9.1](09_Alokace_a_ciste_jmeni.md#91-cílová-alokace-a-rebalancing)); trvající odchylka se připomene nejvýš jednou za měsíc |
| Problémy s daty | Držený instrument bez ceny nebo se zastaralou cenou, zastaralé kurzy ČNB, avízo bez napojené pozice nebo s chybou, chyba IMAP schránky |
| Příjemci | Až 5 adres oddělených čárkou; prázdné = tvůj přihlašovací e-mail |
| Zkontrolovat teď | Vyhodnotí upozornění hned a pošle je, pokud je co hlásit |

Výnos u denního pohybu je změna hodnoty bez vkladů a výběrů (stejný výpočet jako v [8.1](#81-týdenní-report-e-mailem)),
za včerejší den v měně zobrazení. Upozornění na časový test vynechává odměny ze stakingu — denní
drobné loty by souhrn zahltily.

### 8.2.1 Cenové hranice

Ve stejné kartě přidáš hranici: instrument, **nad** / **pod** a cenu v měně kotace instrumentu
(volitelně poznámku). Když poslední cena hranici překročí, souhrn ji jednou ohlásí a u hranice se
zobrazí datum a cena. Tlačítkem **Znovu aktivovat** ji nastavíš znovu; úprava hranice ji také
znovu aktivuje.

> 🛈 Odeslání vyžaduje nastavené SMTP ([11.4 Nastavení](11_Administrace.md#114-nastavení)) a úlohu `cron-alerts`
> (denně 7:30, viz [11.6 Plánované úlohy](11_Administrace.md#116-plánované-úlohy)).

## 8.3 Měsíční a roční souhrn

**Portfolio → Souhrny** — tabulka a graf výnosu po měsících nebo letech (přepínač **Měsíce / Roky**),
volitelně jen pro jedno portfolio nebo účet. Vše v měně zobrazení z denní historie hodnoty (§ 7.5).

| Sloupec | Význam |
|---|---|
| Hodnota na konci | Hodnota k poslednímu dni období (u probíhajícího období k dnešku); pod ní hodnota na začátku |
| Vklady / výběry | Nákupy a vklady − prodeje a výběry v období |
| Výnos | Změna hodnoty − vklady a výběry; % = výnos / (hodnota na začátku + polovina vkladů) |
| Dividendy + úroky | Hrubě, kurzem ČNB ke dni platby; pod tím sražená daň |
| Poplatky | Transakce typu poplatek a poplatky u obchodů ve fiat měně |
| Inflace | Inflace ČR za měsíc (rok: prosinec / prosinec, u probíhajícího roku do posledního zveřejněného měsíce) a pod ní reálný výnos ([7.5](07_Prehled.md#benchmark-a-inflace)) |

Hvězdička u hodnoty znamená, že některá pozice neměla k tomu dni cenu nebo kurz a v součtu chybí — výnos takového
období (a období, které na něj navazuje) se nepočítá a ukáže se „—".
Stejná čísla posílá měsíční a roční souhrn e-mailem ([8.1](#81-týdenní-report-e-mailem)).

## 8.4 Dividendy a úroky

**Portfolio → Dividendy a úroky** — přijaté dividendy a úroky za vybraný rok (nebo všechny roky):

- **Souhrn** — dividendy a úroky hrubě, sražená daň a čistá částka.
- **Graf** po měsících vybraného roku (bez roku po letech), dividendy a úroky zvlášť.
- **Po pozicích** a **po letech**.
- **Platby** — každá výplata s původní částkou a měnou, čistou částkou a sraženou daní.

Částky jsou v měně zobrazení kurzem ČNB ke dni platby. Srážková daň je poplatek u dividendy
([5.1 Typy transakcí](05_Transakce.md#51-typy-transakcí)). Pro daňové přiznání slouží [12. Daňový report](12_Danovy_report.md).

