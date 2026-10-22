# AGENTS.md — pravidla pro AI agenty a přispěvatele

Pokyny pro práci s tímto repozitářem (Claude Code, Codex, Cursor, Copilot a další).
Platí pro celý repozitář. Obecný popis projektu je v [README.md](README.md),
uživatelský manuál v [`manual/`](manual/INDEX.md).

## O projektu

MyPortfolio — self-hosted správa osobních investic: krypto, ETF, Portu (robo-advisor),
hotovost na spořicích účtech. Více uživatelů, každý vidí jen svá data.
Backend PHP 8.5 + Slim 4, frontend Vue 3 + TypeScript + Vite + Tailwind,
databáze MariaDB 11.x. Nasazení Linux + Docker (nginx + php-fpm).

Skelet (auth, MFA, session, middleware, migrace, Docker, CI) je převzatý z MyInvoice
(MIT); doménová vrstva investic je nová.

## Layout repozitáře

- `api/` — PHP backend (Slim, autowired actions, services, repositories); `api/bin/` = CLI skripty, `api/tests/` = PHPUnit
- `web/` — Vue 3 + TS frontend; zdrojáky ve `web/src/`, lokalizace ve `web/src/i18n/`
- `web/dist/` — produkční build frontendu; **gitignorovaný**, staví se lokálně a v CI / Docker buildu
- `db/migrations/` — SQL migrace (číslované, idempotentní)
- `docker/` — nginx, php-fpm, entrypoint, cron wrapper pro Docker image
- `cmd/` — shell wrappery (testy, Docker, cron)
- `tools/` — pomocné skripty (generování crontabu pro Docker)
- `manual/` — uživatelský manuál (Markdown, česky; je i přímo v aplikaci)
- `docs/screenshots/` — screenshoty pro README (jen z ukázkových dat `seed-demo.php`)
- `styles/` — statika servírovaná z rootu (logo)

## Příkazy

```bash
# Projekt vyžaduje PHP 8.5 — pokud výchozí `php` je starší, volej php8.5 explicitně.

# Frontend — build (NUTNÉ po každé změně web/src; web/dist/ se necommituje)
cd web && pnpm build            # = vue-tsc --noEmit && vite build
cd web && pnpm type-check       # jen typová kontrola
cd web && pnpm test:pwa         # node:test testy frontendu

# PHP testy (PHPUnit 13)
cd api && php8.5 vendor/bin/phpunit                  # vše
cd api && php8.5 vendor/bin/phpunit --filter Xyz     # podmnožina
cmd/test.sh                                          # PHPUnit + pnpm build

# Migrace — VŽDY přes migrate.php, NIKDY mysql klientem přímo
php8.5 api/bin/migrate.php
php8.5 api/bin/migrate.php --status

# Instalace (průvodce: Docker / nativně) — webový průvodce /setup pak založí admina, SMTP a ukázková data
cmd/install.sh [--docker|--native] [--yes]

# Ukázková data (DemoDataSeeder) do uživatele bez účtů; --reset jen app.env = development
php8.5 api/bin/seed-demo.php --user=<email> [--backfill] [--reset] [--no-partner]   # + demo partner se sdílením
php8.5 api/bin/backfill-market.php --from=YYYY-MM-DD    # kurzy ČNB + historie cen od data

# Přepočet FIFO lotů a denních snapshotů z transakcí (po importu / opravě dat; běžně automaticky)
php8.5 api/bin/rebuild-lots.php [--user=<id>]
php8.5 api/bin/backfill-snapshots.php [--user=<id>] [--from=YYYY-MM-DD]

# Obnova ze zálohy cron-backup (bez --yes jen vypíše obsah; --db=<jiná> pro zkoušku)
php8.5 api/bin/restore-backup.php <zip> [--yes] [--db=<název>]

# Týdenní e-mailový report (cron pondělí 8:00; ruční spuštění pošle hned)
php8.5 api/bin/cron-weekly-report.php [--user=<id>]

# Měsíční / roční souhrn e-mailem (cron 1. den v měsíci 8:05; v lednu i roční)
php8.5 api/bin/cron-period-report.php [--period=YYYY-MM|YYYY] [--user=<id>]

# Synchronizace transakcí přes API burz (cron každou hodinu)
php8.5 api/bin/cron-exchange-sync.php [--user=<id>] [--connection=<id>]

# Inflace ČR — index HICP z Eurostatu (cron denně 6:40; první běh stáhne celou řadu od 1996)
php8.5 api/bin/cron-cpi.php

# Denní souhrn e-mailových upozornění (cron denně 7:30; posílá jen když je co hlásit)
php8.5 api/bin/cron-alerts.php [--user=<id>]

# Bankovní e-mailová avíza z IMAP schránek uživatelů (běžně cron každých 15 min)
php8.5 api/bin/cron-bank-notices.php [--user=<id>]

# Docker
cmd/docker-install.sh           # první instalace (.env, cfg.docker.php, build, up)
docker compose up -d / down
```

## Tvrdá pravidla

### Peníze a množství
- **Nikdy float.** Částky, ceny, kurzy a množství se v PHP počítají přes `Brick\Math\BigDecimal`
  (knihovna `brick/math`), v DB jsou `DECIMAL`. Do/z DB putují jako string.
- Přesnost sloupců: množství `DECIMAL(36,18)`, jednotkové ceny a kurzy `DECIMAL(28,10)`,
  částky `DECIMAL(20,4)`. Zaokrouhlení jen na výstupu (zobrazení, report), nikdy v mezivýpočtu.
- Jediná výjimka: poměrové metriky výnosu (TWR, XIRR, % v `Service/History/ReturnsCalculator`) jsou `float` —
  XIRR potřebuje neceločíselné mocniny. Částky (zisk, toky) se i tam sčítají v BigDecimal.
- Ve frontendu jsou hodnoty z API stringy; převod na `number` jen pro zobrazení a grafy.

### Kurzy a ceny
- Základní měna je **CZK** (kurzy ČNB). Zobrazovací měna je per uživatel (`users.display_currency`).
- Kurzy a ceny se získávají **jen** přes `Service/Fx/FxService` (kurzy ČNB z `CnbFxProvider`) a
  `Service/Price/PriceService` (implementace `PriceProvider`, řetězec zdrojů s fallbackem). Žádné ad-hoc HTTP volání
  na burzu v Action nebo Repository.
- Chybějící kurz nebo cena **nikdy nesmí tiše spadnout na 1** (chyba starého projektu).
  Použij poslední známou hodnotu s příznakem `stale`, nebo vrať chybu.

### Transakce, loty, daně
- Zdrojem pravdy jsou `transactions`. `tax_lots` / `lot_disposals` / `daily_snapshots` jsou **odvozená data** —
  FIFO engine je umí kdykoli přepočítat; nikdy je needituj ručně ani přes API.
- Převod mezi účty (`transfer_out` + `transfer_in`, spojené `transfer_group_id`) není prodej —
  loty se přenášejí včetně původního data nabytí (časový test pokračuje).
- Při zásahu do výpočtů P&L / daní ověř výsledek na syntetickém scénáři v testu
  (částečný prodej přes více lotů, reward, převod, split, poplatek v cizí měně, hranice 3 let).

### Izolace uživatelů
- **Každý dotaz na uživatelská data je scopovaný na `user_id`** přihlášeného uživatele
  (repository vrstva). Cizí záznam = 404, ne 403 (neprozrazuj existenci).
- Globální číselníky (`institutions`, `instruments`, `prices`, `fx_rates`) spravuje admin;
  uživatel je jen čte.
- RBAC: role `admin` a `user`; `/api/admin/*` je jen pro admina (`RoleMiddleware`).
- **Sdílení**: doménové akce čtou vlastníka dat přes `CurrentUser::owner()` (hlavička `X-Owner`,
  ověřená `ShareContextMiddleware` proti `shares`), osobní akce (nastavení, auth, sdílení, admin, číselníky) jen `CurrentUser::id()`.
  Nová doménová cesta dostupná partnerovi = vědomě do allowlistu `Service/Sharing/ShareAccess` (hlídá `ShareAccessTest`);
  sdílení portfolií filtruje `AccessContext` v repository (portfolia, pozice, účty, transakce, importy).

### Importy
- Každý import (CSV z burzy, API burzy) musí být **idempotentní** přes
  `(user_id, source, external_id)` — opakovaný import nesmí vytvořit duplicity.
- Importované transakce odkazují na `import_batches`, aby šla dávka vrátit.

### Migrace
- Nová migrace = nový číslovaný soubor v `db/migrations/` (`NNNN_snake_name.sql`), spouští se **výhradně** přes `php8.5 api/bin/migrate.php`.
- Každá migrace musí být **idempotentní**: nativní MariaDB `IF [NOT] EXISTS` (`ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`, …), ne PREPARE/EXECUTE triky.
- V SQL preferuj **window functions a CTE** před vnořenými subselecty; nepoužívej `SQL_CALC_FOUND_ROWS`.

### i18n
- Veškeré nové UI texty přes `t()` z vue-i18n — **nikdy** natvrdo česky/anglicky v šablonách
  (ani `locale === 'cs' ? … : …`). Vždy doplň **obě** locale (`web/src/i18n/cs.json` i `en.json`).
- Pole/seznamy překladů přes `tm()` + `rt()` — `t()` pole stringifikuje.
- Literální `{` `}` v textu zprávy escapuj jako `{'{token}'}` — jinak to vue-i18n bere jako interpolaci a render tiše spadne.

### API tokeny
- Bearer token smí jen endpointy (metoda + cesta) z `Api\ApiCatalog` — katalog je zároveň dokumentace
  (Profil → API) a OpenAPI. Nový endpoint pro token přidávej do katalogu vědomě, s parametry a popisem cs + en;
  `/api/admin/*`, správa tokenů a nastavení účtu tam nepatří (hlídá `ApiCatalogTest`).

### Runtime cesty a bezpečnost
- Cesty do `storage/` a `log/` vždy přes `RuntimePaths` (respektuje `MYPORTFOLIO_DATA_DIR`), nikdy `Bootstrap::rootDir()`. Statické assety zůstávají na root dir.
- Citlivé údaje (hesla, API klíče, connection stringy) nikdy do kódu, testů ani dokumentace —
  jen do `cfg.local.php` / `cfg.docker.php` / ENV (vše gitignorované).
- Webserver pravidla existují ve dvou kopiích: `.htaccess` (lokální Apache dev) a `docker/nginx.conf` — změny rewrite pravidel a hlaviček promítej do obou.

### Frontend
- Po každé změně ve `web/src` spusť `pnpm build` — aplikace běží z `web/dist/`, takže bez buildu
  změnu neuvidíš; samotný `vue-tsc` nestačí.
- Drž se existujícího design language (boxy `bg-surface border rounded-lg shadow-sm`, badge,
  mobile cards, tokeny v `web/src/styles/main.css`) — před vymýšlením nového vzoru se podívej na sousední stránky.

## Testy

- PHPUnit 13, testy v `api/tests/{Unit,Integration,Architecture}`. Nové chování pokrývej testem; PR nesmí rozbít existující testy.
- Testy běží proti vlastní DB `myportfolio_test` (`phpunit.xml` nastaví `MYPORTFOLIO_DB_NAME`), nikdy proti
  vývojové. Příprava: `MYPORTFOLIO_DB_NAME=myportfolio_test php8.5 api/bin/migrate.php` + `api/bin/ci-seed.php`
  (admin + user). HTTP část potřebuje běžící server (`MYPORTFOLIO_TEST_URL`).
- **Pouze syntetická testovací data** — žádné reálné transakce, zůstatky, adresy peněženek,
  čísla účtů ani API klíče. E-maily `@example.invalid`.
- Poskytovatelé cen/kurzů se testují proti uloženým fixture odpovědím, nikdy proti živému API.

## Manuál

- Zdroj v `manual/*.md` (česky, konvence v `manual/README.md`, přehled v `manual/INDEX.md`). Každá funkce viditelná
  uživatelem má kapitolu aktualizovanou **ve stejném commitu** — manuál je součást hotového milníku.
- Piš **jen aktuální stav** — žádné „od verze X.Y.Z", žádné odkazy na historii vývoje.
- Manuál je i ve webu (stránka Manuál, přibalený při `pnpm build`) — odkazy mezi kapitolami piš jako
  `NN_Kapitola.md#kotva-nadpisu` (kotvy jako na GitHubu); hlídá je `web/tests/manual-links.test.mjs`.

## Konvence

- Drž se stylu okolního kódu (pojmenování, idiomy, hustota komentářů). Nepřidávej komentáře, které kód jen opakují.
- `declare(strict_types=1)`, `final class`, závislosti přes konstruktor `private readonly`.
- Identifikátory: namespace `MyPortfolio\`, ENV `MYPORTFOLIO_*`, cookie `__Host-myportfolio_*`,
  localStorage `myportfolio-*`.
- Commit messages česky, conventional-commits styl: `feat(scope): …`, `fix(scope): …`, `release: X.Y.Z — …`.
- Změny v `CHANGELOG.md` a `VERSION` se dělají při release.
- Necommituj vygenerované artefakty (`web/dist/`, `manual/generated/`) ani lokální konfiguraci (`cfg.php`, `cfg.local.php`, `.env`).
