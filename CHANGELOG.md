# Changelog

Všechny podstatné změny projektu. Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
verzování podle [SemVer](https://semver.org/lang/cs/).

## [Unreleased]

## [0.3.2-dev] — 2026-10-22

Údržbová verze: aktualizace závislostí a stabilnější build.

### Změněno
- Aktualizace závislostí: Guzzle 8, markdown-it 15 (vlastní typy), @vueuse/core 15, Pinia 4.1, Vue Router 5.4,
  Vue 3.5.44, vue-i18n 11.4.16, axios 1.20, Vite 8.3, PHPUnit 13.4 a další; GitHub Actions checkout v7,
  setup-node v7, cache v6.
- Build frontendu vyžaduje Node.js 22.12+ (kvůli @vueuse/core 15).
- Pevná verze pnpm (`packageManager` ve `web/package.json`) místo `pnpm@latest` v CI a Docker buildu.

## [0.3.1-dev] — 2026-10-22

První veřejná verze: projekt je open source na GitHubu, přibyla stránka Aktualizace.

### Přidáno
- **Aktualizace** (Systém → Aktualizace, jen správce) — nainstalovaná a nejnovější vydaná verze z GitHub releases
  (cache 6 h, tlačítko Zkontrolovat znovu), popis nové verze a příkazy pro aktualizaci podle typu instalace (Docker /
  nativně) s cestou k aplikaci a PHP. Repozitář v `update.github_repo` / `MYPORTFOLIO_UPDATE_GITHUB_REPO`.
  Endpoint `GET /api/admin/update`.

### Odebráno
- Import ze starého crypto-portfolio (`api/bin/import-legacy.php`, `LegacyImporter`, `LegacyMapper`) a konfigurace
  `legacy_db`. Už naimportovaná data i jejich dávky zůstávají beze změny.

### Změněno
- Repozitář je veřejný (MIT): `SECURITY.md` s kontaktem (GitHub private vulnerability reporting), `CODE_OF_CONDUCT.md`,
  šablony issue a PR, Dependabot, `.env.example`, anglický souhrn v README.
- Manuál odpovídá aktuální aplikaci — pořadí kapitoly 9 podle menu, odkazy na konkrétní oddíly, ikony, skupiny API.
- Testovací data bankovních avíz jen se syntetickými čísly účtů.

## [0.3.0-dev] — 2026-10-21

Vývojová verze: napojení burz přes API, cílová alokace, benchmark a inflace, čisté jmění, sdílení pro domácnost,
podklad pro daňové přiznání, manuál v aplikaci a nový vzhled celé aplikace.

### Přidáno
- **Stránka API** (Profil → API, dříve API tokeny) — záložky Tokeny a Endpointy: seznam všech endpointů dostupných
  tokenem s parametry, rozsahem, ukázkou odpovědi, příkladem curl a tlačítkem Vyzkoušet; export OpenAPI 3.1
  (`/api/openapi.json`).
- API tokenem jde volat i daňový report (vč. CSV), importy a cenové hranice.
- **Hotovost na účtu** — ruční pozice se zadávaným zůstatkem (Nastavit zůstatek); rozdíl se zapíše jako vklad
  nebo výběr, takže hotovost nemá výnos. Endpoint `POST /api/positions/{id}/cash-balance`.
- **Napojení burz přes API** (Účet → Burzy) — Coinbase, Binance, Kraken, Coinmate: read-only klíč (šifrovaně), start
  navázáním na poslední import / od data / celá historie, hodinová synchronizace (`cron-exchange-sync`) i ručně;
  co potřebuje rozhodnutí, čeká jako náhled v Importu.
- **Cílová alokace a rebalancing** (Portfolio → Alokace) — cíle tříd a volitelně instrumentů pro celý majetek
  i per portfolio, odchylka a tolerance, návrh rozdělení vkladu a plný rebalancing s odhadem zdanitelných kusů;
  badge na Přehledu a upozornění „alokace mimo toleranci" v denním souhrnu.
- **Benchmark a inflace** — v grafu vývoje porovnání se zvoleným instrumentem (stejné vklady ve stejné dny),
  inflace ČR (HICP, Eurostat) a reálný TWR / XIRR; v souhrnech inflace a reálný výnos po měsících a letech.
  Úloha `cron-cpi`.
- **Čisté jmění** (Portfolio → Čisté jmění) — investice + nemovitosti a ostatní majetek − závazky;
  úvěr jako anuita s korekcemi zůstatku (mimořádná splátka, refixace), odhad doplacení, historie a dlaždice na Přehledu.
- **Sdílení pro domácnost** (Účet → Sdílení) — sdílení celého účtu nebo vybraných portfolií s partnerem, jen pro čtení
  nebo i pro zápis (transakce, ocenění, pozice, importy), volitelně čisté jmění / daňový report / příjmy; pozvánka
  e-mailem i pro nového uživatele, přepínač „čí data" v záhlaví, štítek a log změn partnera, stránka Domácnost.
- **Podklad pro daňové přiznání** (Daňový report) — podle toho, jak přiznání podáváš (EPO / poradce / jiný program):
  hodnoty řádků DPFO (příloha 2, 3 nebo 4, ř. 38 / 40 / 74a) v celých Kč, souhrn pro poradce, nebo CSV.
- Ukázková data pokrývají nové funkce: penzijko a DIP, cílová alokace, benchmark, čisté jmění (hypotéka, auto) a z příkazové
  řádky demo partner se vzájemným sdílením.
- **Manuál v aplikaci** (Systém / Účet → Manuál) — kapitoly z `manual/*.md` ve svislých záložkách s oddíly,
  odkazy mezi kapitolami; instalace a administrace jen pro správce.
- Typ instituce **Penzijní společnost**; penzijko a DIP se vedou jako investiční pozice (manuál 4.5.2).

### Opraveno
- Sdílení: partner se zápisem nesmaže převod, jehož druhá noha je v nesdíleném portfoliu (404, nic se nesmaže);
  číslo účtu pro bankovní avíza partner nevidí ani nezmění.
- Binance: obchody při běžné synchronizaci po 24h oknech (dřív se mohly ztratit starší filly nad 1 000 na pár),
  páry i z aktiv účtu (uzavřené pozice), stránkování vkladů, výběrů, Convertu, fiat plateb a dividend, okna bez mezery.
- Coinbase: fill Advanced Trade proti USDC / USDT jen jednou (množství základu), poplatek v nefiat kotaci přepočten.
- Synchronizace burz: zpožděný pohyb z týdenního překryvu se už tiše nevynechá — přeskočí se jen to, co uživatel
  v náhledu vynechal; chyba spojení neukládá URL s podpisem.
- Hotovost: zůstatek z bankovního avíza se nepřičítá k pohybům (dřív se zůstatek zdvojil).
- Benchmark končí, když cena chybí déle než 14 dní, a výběr neprodá víc kusů, než se drží.
- Reálný výnos srovnává stejně dlouhá období (inflace přepočtená na délku výnosu); roční souhrn měří inflaci od stejného měsíce.
- Podklad k přiznání: ř. 413 nejvýš daň z ř. 410, ř. 322 se u dividend neuvádí.
- Binance: den s víc než 1 000 obchody na páru se stáhne celý (okno se půlí); plné okno i po zúžení = chyba místo ztráty dat.
- Domácnost: investováno, výnos i alokace jen z aktivních pozic.
- Bankovní avíza: MONETA bere směr z předmětu, CREDITAS z věty o zůstatku, karta Raiffeisenbank je výdaj, úrok jen podle
  typu pohybu (ne „Eurokonto"); maskované číslo účtu odpovídající víc účtům se nepřiřadí.
- Bezpečnost: ověření Authentication-Results jen z horní hlavičky s přesným authserv-id a DKIM jen na doménu banky;
  IMAP jen na veřejné adresy (`bank_notices.allow_private_imap`); záloha `cfg.php` z instalace mimo web a bez rotace
  pepperu / klíče; webserver blokuje i `cfg*.php*` a `*.bak`; SMTP debug log bez AUTH; XLSX se strop rozbalených dat
  a bez alokace děr; text v daňovém CSV se neotevře jako vzorec.
- Daňový report: poplatek v kusech prodávaného aktiva je výdaj; poplatek v jiném kryptu (BNB) report neshodí — vynechá se
  s varováním.
- Měsíční / roční souhrn: neúplné období (chybí cena) výnos nemá a další období na něj nenavazuje.
- Graf: tooltip po změně období zůstával na neplatném bodě (a mohl viset poloprůhledný).
- Přehled: popisek Čistého jmění byl posunutý; ETF bez vlastního loga přebíralo ikonu instituce.
- Tmavý motiv: hover primárního tlačítka měl nečitelný bílý text; tlačítko zapisovacích metod v katalogu API.
- Lokální vývojový server (`api/bin/dev-server-router.php`) posílá statiku z `web/dist` i logo ze `styles/` sám —
  dřív fungoval jen s `-t web/dist` a bez loga.
- Drobnosti: výhled splátek u zůstatku s víc desetinnými místy, pozvánka na deaktivovaný e-mail, investice v Domácnosti
  bez archivovaných pozic, odhad zdanitelných kusů v rebalancingu podle stejného časového testu jako daňový report.

### Změněno
- **Nový vzhled aplikace** — zelená značka, nové logo (prstenec alokace) a favicon, písmo Raleway s tabulkovými
  číslicemi z Montserrat a JetBrains Mono (vše přibalené), světlý i tmavý motiv s kontrastem textu aspoň 4,5:1.
- **Přehled** — hlavní číslo hodnota portfolia s celkovým výnosem, čisté jmění jako samostatný panel s rozpadem,
  větší donut alokace se součtem a propojenou legendou, skutečnost vs. cíl, krypto napříč účty s rozbalením po účtech,
  portfolia se součtem a podílem na celku.
- **Graf vývoje hodnoty** — metriky ve skupinách Za období / Výkonnost s vysvětlením (TWR vs. XIRR), plocha zisku
  a ztráty mezi hodnotou a investovanými penězi, svislá čára a popisek po najetí i dotyku, přepínač období.
- Všechny stránky ze sdílených komponent: tabulky se na mobilu mění na karty, jednotné karty sekcí, štítky
  a chybová hlášení, zarovnané sloupce napříč sekcemi; detail pozice a čisté jmění se souhrnnou kartou.
- Čísla: typografické minus „−", formát podle jazyka všude (dřív „54.9 %"), procenta výnosu se znaménkem,
  množství a kurzy kryptoměn v neproporcionálním písmu.
- Ikony místo znaků (⚠ ✓ 🔒 👁); PWA ikony mají nové názvy, aby se po aktualizaci nedržely z cache service workeru.
- Oprávnění tokenu určuje katalog `ApiCatalog` (metoda + cesta, rozsah per endpoint) místo seznamu cest —
  token už nesmí metodu, kterou endpoint nemá (např. POST na detail).

## [0.2.0-dev] — 2026-10-11

Vývojová verze: všechny milníky M1–M7 a navazující funkce. Importy z burz a brokerů jsou ověřené na
syntetických exportech, ne na skutečných souborech.

### Přidáno
- **Katalog** instituce a instrumentů (sdílený; zakládá každý uživatel, upravuje admin) se zdroji cen.
- **Účty, portfolia, pozice** — tržní pozice (instrument × účet × portfolio) i ručně oceňované (Portu, spořicí účet).
- **Transakce** — nákup, prodej, odměna, split, vklad, výběr, dividenda, úrok, poplatek; převody mezi účty jako pár.
- Kontrola drženého množství v čase — nelze prodat ani vybrat víc, než pozice kdy držela.
- Čísla bez floatů (`brick/math`), testovací DB `myportfolio_test`.
- **Kurzy ČNB** (denní lístek, roční soubor pro backfill) a doplnění kurzu k transakcím; chybějící kurz nikdy není 1.
- **Ceny instrumentů** — CoinGecko + Yahoo (krypto, historie od první transakce), XTB → Yahoo (ETF), ruční ceny,
  příznak zastaralé ceny, admin přehled „Ceny a kurzy", cron `cron-fx` a `cron-prices` (každou hodinu).
- Ukázková data `api/bin/seed-demo.php`; poplatek s přesností 18 míst (poplatky v kryptu).
- **FIFO loty** — přepočet po každé změně transakce, převody zachovávají datum nabytí, poplatek v měně
  instrumentu snižuje množství; CLI `api/bin/rebuild-lots.php`.
- **Přehled (dashboard)** v měně zobrazení — hodnota, investováno, realizovaný / nerealizovaný zisk, příjmy,
  alokace (donut), krypto napříč účty, upozornění na chybějící ceny a kurzy.
- **Detail pozice** s oceněním, otevřenými loty a prodeji; **ruční ocenění** pro Portu a spořicí účty.
- Měna poplatku může být i symbol kryptoměny (`USDT`, `BNB`).
- **Historie hodnoty** — denní snapshoty pozic (CZK / EUR / USD) s automatickým přepočtem po změnách, graf
  hodnoty a investováno (celkem, portfolio, pozice, třída aktiv; 1M–Vše), metriky TWR, XIRR a výnos za období;
  cron `cron-snapshot` a `api/bin/backfill-snapshots.php`.
- **Import ze starého crypto-portfolio** (`api/bin/import-legacy.php`) — instituce, instrumenty, pozice, transakce,
  ruční ocenění a ikony; dry-run s kontrolními součty, idempotentní.
- **Ikony** institucí a instrumentů (upload, úložiště v `storage/`, převzetí ze staré aplikace, součást zálohy).
- U ručních pozic dnešní hodnota ve sloupci Poslední cena; tlačítko Detail u pozic; jednotná ikonová tlačítka s tooltipem.
- **Bankovní e-mailová avíza přes IMAP** — Fio, Air Bank, MONETA, Raiffeisenbank, ČSOB, Česká spořitelna, CREDITAS,
  UniCredit; vklady, výběry, úroky a zůstatek jako ruční ocenění spořicího účtu.
- **Nastavení SMTP v administraci** se zkušebním e-mailem.
- **Týdenní e-mailový report** (pondělí 8:00) — portfolia, účty, volitelně pozice, transakce; čitelný na mobilu.
- **Obnova ze zálohy** (`api/bin/restore-backup.php`, i do jiné DB na zkoušku).
- **Import z burz a brokerů** (CSV / XLSX / ZIP) — Coinbase, Anycoin, Coinmate, Binance, Kraken, XTB, Trading 212,
  Revolut, DEGIRO, Interactive Brokers; náhled s párováním instrumentů a převodů, idempotence, vrácení dávky.
  Dividenda se srážkovou daní = hrubá částka + daň jako poplatek.
- **Daňový report** za rok (ČR) — prodeje CP a kryptoaktiv po FIFO lotech (limit 100 000 Kč, časový test 3 roky,
  krypto od 15. 2. 2025, strop 40 mil.), denní nebo jednotný kurz, zahraniční dividendy po zemích se zápočtem,
  úroky, CSV a tisk.
- **E-mailová upozornění** — denní souhrn: cenové hranice, velký denní pohyb, blížící se časový test, problémy s daty.
- **Měsíční a roční souhrn** (stránka i e-mail) a **přehled dividend a úroků**.
- **Instalační průvodce** `cmd/install.sh` (Docker / nativně) a rozšířený webový průvodce `/setup` (správce, jazyk
  a měna, MFA, SMTP se zkušebním e-mailem, ukázková data); bohatší ukázková data (~5 let) a
  `api/bin/backfill-market.php`.

### Změněno
- FIFO odebírá loty podle data nabytí i u lotů převedených z jiného účtu (dřív podle pořadí příchodu na pozici).
- Docker image `latest` jen pro verze bez pre-release přípony.

### Opraveno
- Historie: dny vynechané bez běžícího cronu se dopočítají při dalším zobrazení; `cron-prices` doplní i mezery
  uvnitř historie cen.
- Spořicí účty z importu staré DB: změny zůstatku jako vklady a výběry.

## [0.1.0] — 2026-09-28

### Přidáno
- **Aplikační skelet převzatý z MyInvoice** — PHP 8.5 + Slim 4 backend, Vue 3 + Vite + Tailwind frontend,
  MariaDB migrace, Docker image (nginx + php-fpm), GitHub Actions CI a publikace do GHCR.
- **Autentizace a zabezpečení** — setup wizard, přihlášení, passkeys, TOTP, e-mailové OTP, záložní kódy,
  step-up, zámek session, brute-force ochrana, CSRF, IP allowlist, API tokeny se scope.
- **Více uživatelů** — role `admin` a `user`, správa uživatelů, audit log.
- **Profil** — jméno, jazyk UI a měna zobrazení (CZK / EUR / USD).
- **Plánované úlohy** — úklid (`cron-cleanup`) a záloha databáze (`cron-backup`), přehled v administraci.
- Dokumentace: `AGENTS.md`, spec v `source/`, roadmapa M0–M7.
