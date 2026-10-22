# cmd/ — pomocné skripty

Všechny skripty jsou bash (Linux / Docker). PHP se volá jako `php8.5`, pokud existuje,
jinak `php` (přebij přes `PHP_BIN=/cesta/k/php`).

## Instalace

| Skript | Co dělá |
|---|---|
| `install.sh [--docker\|--native] [--yes]` | Instalační průvodce: Docker (volá `docker-install.sh`) nebo nativně — kontrola požadavků, `cfg.php` z `cfg.sample.php` (`api/bin/install-config.php`), volitelně založení DB a uživatele, `composer install`, build frontendu, migrace, oprávnění `storage/` a `log/`, crontab. Na konci odkáže na webový průvodce `/setup` |

## Vývoj

| Skript | Co dělá |
|---|---|
| `test.sh [args]` | PHPUnit (`api/`) + `pnpm build` (`web/`); argumenty jdou do PHPUnit, `SKIP_WEB=1` přeskočí frontend |

## Docker

| Skript | Co dělá |
|---|---|
| `docker-install.sh` | První instalace bez dotazů: vygeneruje `.env` (náhodná DB hesla) a `cfg.docker.php`, postaví image, spustí `db` + `app`, počká na `/api/health`; volitelně ENV `APP_PORT`, `APP_URL`, `APP_ENV` |
| `docker-build.sh [--no-cache] [--pull]` | Přestaví image `myportfolio:latest` |

Běžná obsluha:

```bash
docker compose up -d                 # start
docker compose logs -f app           # logy aplikace (vč. migrací při startu)
docker compose exec app php api/bin/migrate.php --status
docker compose down                  # stop (data zůstávají ve volumes)
docker compose --profile redis up -d # s Redisem (rate limit, brute-force)
```

Produkce s image z GHCR: `docker-compose.production.yml` (návod v hlavičce souboru).

`.env` (gitignorovaný) nese `APP_PORT`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_ROOT_PASSWORD`,
`DB_PASSWORD`; volitelně `MYPORTFOLIO_AUTH_REQUIRE_MFA`, `MYPORTFOLIO_AUTH_MFA_METHODS`,
`MYPORTFOLIO_SESSION_LOCK_AFTER_MINUTES`, `MYPORTFOLIO_SMTP_*`, `MYPORTFOLIO_COINGECKO_API_KEY`.

## Cron

Katalog úloh a frekvencí je v `api/src/Service/Cron/CronCatalog.php` (zobrazuje ho i
Systém → Plánované úlohy). Docker image má vestavěný cron generovaný z katalogu
(`MYPORTFOLIO_ENABLE_CRON=0` ho vypne).

| Skript | Frekvence | Co dělá |
|---|---|---|
| `cron-cleanup.sh` | denně 03:00 | úklid login_attempts, sessions, OTP, reset tokenů, trusted devices, logů, `cron_runs` |
| `cron-backup.sh` | denně 02:00 | `mariadb-dump` do `storage/backup` (volitelně AES ZIP, retence denní/měsíční) |
| `cron-fx.sh` | pracovní dny 14:45 | kurzy ČNB od posledního uloženého dne + doplnění `fx_rate_czk` u transakcí |
| `cron-prices.sh` | každou hodinu (:05) | aktuální ceny instrumentů v pozicích + doplnění chybějící historie |
| `cron-snapshot.sh` | denně 00:30 | denní snapshoty pozic — fronta změn + přepočet posledních 7 dní |
| `cron-weekly-report.sh` | pondělí 08:00 | týdenní e-mailový report uživatelům se zapnutým reportem (ruční spuštění pošle hned) |
| `cron-period-report.sh` | 1. den v měsíci 08:05 | měsíční souhrn za minulý měsíc, v lednu i roční za minulý rok (`--period=YYYY-MM\|YYYY` pro ruční odeslání) |
| `cron-exchange-sync.sh` | každou hodinu (:20) | synchronizace transakcí přes API burz (Coinbase, Binance, Kraken, Coinmate) |
| `cron-cpi.sh` | denně 6:40 | index spotřebitelských cen ČR (HICP, Eurostat) pro reálný výnos |
| `cron-alerts.sh` | denně 07:30 | denní souhrn e-mailových upozornění (cenové hranice, denní pohyb, časový test, problémy s daty) |
| `cron-bank-notices.sh` | každých 15 min | bankovní e-mailová avíza z IMAP schránek uživatelů → vklady/výběry/úroky + ocenění ručních pozic |

Jednorázové doplnění: `php8.5 api/bin/cron-fx.php --from=2021-01-01`, `php8.5 api/bin/backfill-prices.php --all [--from=…]`,
`php8.5 api/bin/backfill-snapshots.php [--user=<id>] [--from=…]`.

Nativní instalace: uprav cesty v `cmd/crontab` a vlož přes `crontab -e`. Logy jsou
v `log/cron/<úloha>-YYYY-MM-DD.log` (resp. `${MYPORTFOLIO_DATA_DIR}/log/cron`).
