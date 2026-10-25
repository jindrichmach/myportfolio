# 2. Instalace

Instalaci provede průvodce v terminálu a nastavení dokončíš v prohlížeči.

```bash
cmd/install.sh            # zeptá se na způsob nasazení (Docker / nativně) a všechna nastavení
cmd/install.sh --docker   # rovnou Docker
cmd/install.sh --native   # rovnou nativní instalace
cmd/install.sh --yes      # bez dotazů, s výchozími hodnotami
```

Skript připraví prostředí (konfiguraci, databázi, závislosti, build, migrace, cron) a na konci
vypíše adresu **webového průvodce** (`/setup`, [3.1 Úvodní nastavení](03_Prihlaseni_a_zabezpeceni.md#31-úvodní-nastavení)).
Tam založíš správce, nastavíš e-mail a můžeš zaškrtnout **ukázková data**.
Skript lze spustit znovu — existující konfiguraci nepřepíše bez dotazu. Při přepsání uloží zálohu `cfg.php` do
`storage/backup/config/` (mimo dosah webu) a ponechá stávající pepper a šifrovací klíč — nové by zneplatnily hesla
uživatelů i uložená tajemství (SMTP, IMAP, klíče burz).

## 2.1 Docker (doporučeno)

Průvodce se zeptá na port, veřejnou adresu (URL) a prostředí (`production` / `development`)
a spustí `cmd/docker-install.sh`: vygeneruje `.env` s náhodnými hesly k databázi a
`cfg.docker.php` (pepper, šifrovací klíč, adresa aplikace), postaví image a spustí aplikaci
s databází.

| Soubor | Význam |
|---|---|
| `.env` | Porty a hesla databáze pro Docker Compose |
| `cfg.docker.php` | Konfigurace aplikace |

Data leží ve Docker volumech `db-data` (databáze) a `app-data` (logy, zálohy,
ikony katalogu, nastavení z úvodního průvodce). Migrace databáze proběhnou automaticky při
každém startu kontejneru. Vestavěný cron stahuje kurzy a ceny, zálohuje databázi a posílá
reporty a upozornění.

> ⚠️ `docker compose down -v` smaže i volumy — tedy všechna data.

## 2.2 Nativní instalace (Apache / nginx + PHP-FPM)

Požadavky: PHP 8.5 (rozšíření pdo_mysql, mbstring, openssl, zip, gd, simplexml, curl;
doporučené bcmath), MariaDB 11, Composer, Node.js 22.12+ a pnpm. Redis je volitelný.

Průvodce postupně:

| Krok | Co udělá |
|---|---|
| 1. Kontrola požadavků | PHP a rozšíření, Composer, Node.js, pnpm (nabídne zapnutí přes corepack), klient MariaDB |
| 2. Konfigurace | Zeptá se na adresu aplikace, prostředí, časové pásmo, připojení k databázi a Redis; vygeneruje `cfg.php` z `cfg.sample.php` s náhodným pepperem a šifrovacím klíčem. Pro adresu `http://` nastaví cookies bez příznaku Secure |
| 3. Databáze | Volitelně založí databázi a uživatele (potřebuje účet s právem CREATE USER, např. root) |
| 4. Závislosti | `composer install` (v produkci bez vývojových balíčků) a build frontendu; výpis je v `log/install.log` |
| 5. Migrace | Schéma databáze; nastaví vlastníka `storage/` a `log/` na uživatele webserveru |
| 6. Cron | Vypíše plánované úlohy s cestou instalace a na přání je přidá do crontabu |
| 7. Webový server | Vypíše, jak nastavit Apache (`.htaccess`) nebo nginx (`docker/nginx.conf`) |

Virtuální host míří na kořen repozitáře. Plánované úlohy (`cmd/crontab`):

| Úloha | Kdy | Co dělá |
|---|---|---|
| `cron-prices` | každou hodinu | aktuální ceny + chybějící historie |
| `cron-fx` | pracovní dny 14:45 | kurzy ČNB |
| `cron-snapshot` | denně 00:30 | denní historie hodnoty (grafy) |
| `cron-weekly-report` | pondělí 08:00 | týdenní e-mailový report ([7.6](07_Prehled.md#76-týdenní-report-e-mailem)) |
| `cron-period-report` | 1. den v měsíci 08:05 | měsíční a roční souhrn e-mailem ([7.6](07_Prehled.md#76-týdenní-report-e-mailem)) |
| `cron-exchange-sync` | každou hodinu | synchronizace transakcí přes API burz ([5.6](05_Transakce.md#56-napojení-burzy-přes-api)) |
| `cron-cpi` | denně 6:40 | inflace ČR pro reálný výnos ([7.5](07_Prehled.md#benchmark-a-inflace)) |
| `cron-alerts` | denně 07:30 | e-mailová upozornění ([7.7](07_Prehled.md#77-upozornění-e-mailem)) |
| `cron-bank-notices` | každých 15 min | bankovní e-mailová avíza ([8. Bankovní avíza](08_Bankovni_aviza.md)) |
| `cron-backup` | denně 02:00 | záloha databáze a ikon katalogu |
| `cron-cleanup` | denně 03:00 | úklid session a logů |

## 2.3 Ukázková data

Nejjednodušší je zaškrtnout **Vygenerovat ukázková data** ve webovém průvodci. Data se založí do
účtu správce a obsahují asi 5 let historie: krypto (nákupy, prodeje před i po časovém testu,
staking, převod na hardware peněženku), měsíční nákupy ETF, akcie s dividendami a srážkovou daní
(USA i ČR), Portu s ručním oceněním, spořicí účet s úroky, hotovost u brokera, penzijní spoření
(vlastní vklady a ocenění s příspěvky) a DIP, dvě cenové hranice, cílovou alokaci, benchmark Bitcoin
a čisté jmění (byt s hypotékou po refixaci a mimořádné splátce, auto s doplaceným úvěrem) — tedy data
pro všechny stránky včetně souhrnů, dividend, alokace a daňového reportu. Instrumenty jsou skutečné; kurzy ČNB
a ceny se stáhnou na pozadí (pár minut, potřebuje internet). Částky obchodů jsou ilustrativní.

Z příkazové řádky (jen do uživatele bez účtů):

```bash
php8.5 api/bin/seed-demo.php --user=<email> --backfill   # založí data a stáhne kurzy a ceny
php8.5 api/bin/seed-demo.php --user=<email> --reset      # jen development: smaže doménová data a založí znovu
php8.5 api/bin/seed-demo.php --user=<email> --no-partner # bez demo partnera
php8.5 api/bin/backfill-market.php --from=RRRR-MM-DD            # kurzy ČNB, inflace a ceny od data
```

Příkazová řádka navíc založí **demo partnera** `partner.<jméno>@example.invalid` s vlastním spořicím
účtem a ETF a s náhodným heslem (vypíše ho) — partner ti sdílí své portfolio pro zápis a ty jemu vše
pro čtení včetně čistého jmění. Ukáže tak přepínač sdílených dat a stránku Domácnost
([11. Sdílení pro domácnost](11_Sdileni.md)). Webový průvodce partnera nezakládá. `--reset` smaže
i demo partnera.

> ⚠️ Ukázková data jsou v účtu správce. Pro ostrý provoz je smaž (nebo si založ samostatného
> uživatele a ukázková data nech správci).

## 2.4 Aktualizace

Aktuální verze je vidět dole v levém menu („MyPortfolio v…") a v souboru `VERSION`; změny popisuje `CHANGELOG.md`.
Jestli vyšla nová verze, ukáže správci stránka **Systém → Aktualizace** i s příkazy pro tuto instalaci
(viz [9.6 Aktualizace](09_Administrace.md#96-aktualizace)).
Před aktualizací si stáhni zálohu (`cron-backup`, viz [9.7 Zálohy a obnova](09_Administrace.md#97-zálohy-a-obnova)).

**Docker** (image z GHCR, `docker-compose.production.yml`):

```bash
docker compose -f docker-compose.production.yml pull
docker compose -f docker-compose.production.yml up -d   # migrace proběhnou při startu kontejneru
```

Verze s příponou (např. `0.4.0-dev`) jsou vývojové a nedostávají značku `latest` — pro ně uveď v compose
konkrétní verzi image.

**Lokální build Dockeru:** `git pull`, pak `cmd/docker-build.sh` a `docker compose up -d`.

**Nativní instalace:**

```bash
git pull
cd api && composer install --no-dev --optimize-autoloader && cd ..   # ve vývoji bez --no-dev
php8.5 api/bin/migrate.php
cd web && pnpm install --frozen-lockfile && pnpm build && cd ..
```

Nové plánované úlohy přidá znovu spuštěný `cmd/install.sh` (krok Cron), nebo je doplň podle `cmd/crontab`.
