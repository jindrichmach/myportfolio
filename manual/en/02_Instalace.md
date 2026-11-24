# 2. Installation

Installation is handled by a wizard in the terminal, and you finish the setup in the browser.

```bash
cmd/install.sh            # asks for the deployment method (Docker / native) and all settings
cmd/install.sh --docker   # Docker straight away
cmd/install.sh --native   # native installation straight away
cmd/install.sh --yes      # no questions, default values
```

The script prepares the environment (configuration, database, dependencies, build, migrations, cron) and at the end
prints the address of the **web setup wizard** (`/setup`, [11.1 Initial setup](11_Administrace.md#111-initial-setup))
together with the **installation code** — without it the wizard does not create the administrator, so nobody else can take
over a fresh instance. The code is also in `storage/setup-token` (in Docker `/data/storage/setup-token` and in the output
of `docker compose logs app`); it disappears once the administrator is created.
There you create the administrator, set up email and can tick **demo data**.
The script can be run again — it does not overwrite existing configuration without asking. When overwriting, it saves a backup of `cfg.php` to
`storage/backup/config/` (out of web reach) and keeps the existing pepper and encryption key — new ones would invalidate users'
passwords and stored secrets (SMTP, IMAP, exchange keys).

## 2.1 Docker (recommended)

The wizard asks for the port, public address (URL) and environment (default `production`; `development` only for development — it relaxes the request
origin check and shows error details)
and runs `cmd/docker-install.sh`: it generates `.env` with random database passwords and
`cfg.docker.php` (pepper, encryption key, app address), builds the image and starts the app
with the database.

| File | Meaning |
|---|---|
| `.env` | Ports and database passwords for Docker Compose |
| `cfg.docker.php` | App configuration |

Data lives in the Docker volumes `db-data` (database) and `app-data` (logs, backups,
catalog icons, settings from the initial setup wizard). Database migrations run automatically on
every container start. The built-in cron downloads exchange rates and prices, backs up the database and sends
reports and alerts.

> ⚠️ `docker compose down -v` also deletes the volumes — that is, all data.

## 2.2 Native installation (Apache / nginx + PHP-FPM)

Requirements: PHP 8.5 (extensions pdo_mysql, mbstring, openssl, zip, gd, simplexml, curl;
bcmath recommended), MariaDB 11, Composer, Node.js 22.12+ and pnpm. Redis is optional — without it, request rate limiting and the failed sign-in limit are counted in the database, which puts more load on it. The admin sees this as a warning in System → Settings.

The wizard goes through these steps:

| Step | What it does |
|---|---|
| 1. Requirements check | PHP and extensions, Composer, Node.js, pnpm (offers to enable it via corepack), MariaDB client |
| 2. Configuration | Asks for the app address, environment, time zone, database and Redis connection; generates `cfg.php` from `cfg.sample.php` with a random pepper and encryption key. For an `http://` address it sets cookies without the Secure flag |
| 3. Database | Optionally creates the database and user (needs an account with the CREATE USER privilege, e.g. root) |
| 4. Dependencies | `composer install` (without development packages in production) and the frontend build; output goes to `log/install.log` |
| 5. Migrations | Database schema; sets the owner of `storage/` and `log/` to the web server user |
| 6. Cron | Prints the scheduled jobs with the installation path and adds them to the crontab on request |
| 7. Web server | Prints how to configure Apache (`.htaccess`) or nginx (`docker/nginx.conf`) |

The virtual host points to the repository root. Scheduled jobs (`cmd/crontab`):

| Job | When | What it does |
|---|---|---|
| `cron-prices` | every hour | current prices + missing history |
| `cron-fx` | working days 14:45 | CNB exchange rates |
| `cron-snapshot` | daily 00:30 | daily value history (charts) |
| `cron-weekly-report` | Monday 08:00 | weekly email report ([8.1](08_Reporty_a_upozorneni.md#81-weekly-report-by-e-mail)) |
| `cron-period-report` | 1st day of the month 08:05 | monthly and annual summary by email ([8.1](08_Reporty_a_upozorneni.md#81-weekly-report-by-e-mail)) |
| `cron-exchange-sync` | every hour | transaction synchronization via exchange APIs ([5.6](05_Transakce.md#56-connecting-an-exchange-via-api)) |
| `cron-cpi` | daily 6:40 | Czech inflation for real return ([7.5](07_Prehled.md#benchmark-and-inflation)) |
| `cron-alerts` | daily 07:30 | email alerts ([8.2](08_Reporty_a_upozorneni.md#82-alerts-by-e-mail)) |
| `cron-bank-notices` | every 15 min | bank email notices ([10. Bank notices](10_Bankovni_aviza.md)) |
| `cron-backup` | daily 02:00 | backup of the database and catalog icons |
| `cron-cleanup` | daily 03:00 | cleanup of sessions and logs |

## 2.3 Demo data

The easiest way is to tick **Generate demo data** in the web setup wizard. The data is created in the
administrator's account and covers about 5 years of history: crypto (buys, sells before and after the holding-period test,
staking, transfer to a hardware wallet), monthly ETF buys, stocks with dividends and withholding tax
(US and Czech), Portu with manual valuation, a savings account with interest, cash at a broker, pension savings
(own contributions and valuation with contributions) and DIP (long-term investment product), two price thresholds, a target allocation, Bitcoin as benchmark
and net worth (a flat with a mortgage after refixation and an extra repayment, a car with a paid-off loan) — that is, data
for all pages including summaries, dividends, allocation and the tax report. The instruments are real; CNB exchange rates
and prices are downloaded in the background (a few minutes, requires internet). Trade amounts are illustrative.

From the command line (only into a user without accounts):

```bash
php8.5 api/bin/seed-demo.php --user=<email> --backfill   # creates the data and downloads exchange rates and prices
php8.5 api/bin/seed-demo.php --user=<email> --reset      # development only: deletes domain data and creates it again
php8.5 api/bin/seed-demo.php --user=<email> --no-partner # without the demo partner
php8.5 api/bin/backfill-market.php --from=YYYY-MM-DD            # CNB exchange rates, inflation and prices from the date
```

The command line also creates a **demo partner** `partner.<name>@example.invalid` with their own savings
account and ETF and a random password (it prints it) — the partner shares their portfolio with you for writing and you share everything with them
for reading, including net worth. This demonstrates the shared-data switcher and the Household page
([13. Sharing for the household](13_Sdileni.md)). The web wizard does not create the partner. `--reset` also deletes
the demo partner.

> ⚠️ The demo data is in the administrator's account. For production use, delete it (or create a separate
> user and leave the demo data to the administrator).

## 2.4 Updates

The current version is shown at the bottom of the left menu ("MyPortfolio v…") and in the `VERSION` file; changes are described in `CHANGELOG.md`.
Whether a new version has been released is shown to the administrator on the **System → Updates** page, together with the commands for this installation
(see [11.7 Updates](11_Administrace.md#117-updates)).
Before updating, download a backup (`cron-backup`, see [11.8 Backups and restore](11_Administrace.md#118-backups-and-restore)).

**Docker** (image from GHCR, `docker-compose.production.yml`):

```bash
docker compose -f docker-compose.production.yml pull
docker compose -f docker-compose.production.yml up -d   # migrations run when the container starts
```

Stable versions (e.g. `1.4.1`) also get the `latest` tag. Versions with a suffix (e.g. `1.5.0-dev`) are development versions and
do not get it — for them, specify the exact image version in compose.

**Local Docker build:** `git pull`, then `cmd/docker-build.sh` and `docker compose up -d`.

**Native installation** (run as the user who owns the application files; take the version number from the System → Updates page).
The application is deployed from the release bundle `myportfolio-X.Y.Z.tar.gz` (a GitHub Release asset), not from git. Run the commands in the application directory (where the `VERSION` file is):

```bash
curl -fLO https://github.com/jindrichmach/myportfolio/releases/download/vX.Y.Z/myportfolio-X.Y.Z.tar.gz
tar -xzf myportfolio-X.Y.Z.tar.gz --strip-components=1   # overwrites the application files; the bundle contains no cfg.php or storage/
rm myportfolio-X.Y.Z.tar.gz
php8.5 api/bin/migrate.php
```

The bundle contains the built `vendor` and frontend, so `composer` and `pnpm` are not needed. Manual extraction does not turn on
maintenance mode, does not make a backup or roll back, and does not delete files the new release no longer has. Make a backup first.
The browser update ([11.7](11_Administrace.md#117-updates)) handles the backup, maintenance mode and restoring files on an error
by itself, and removes obsolete files in `web/dist`.

**Development installation from a git clone** (not for production use):

```bash
git fetch --tags
git checkout vX.Y.Z                                                  # replace X.Y.Z with the target version
cd api && composer install --no-dev --optimize-autoloader && cd ..   # in development without --no-dev
php8.5 api/bin/migrate.php
cd web && pnpm install --frozen-lockfile && pnpm build && cd ..
```

Updates from git follow the release tag, not a branch. `git checkout vX.Y.Z` detaches HEAD from the branch, so `git pull` does not
work on the tag — for the next update run `git fetch --tags` and `git checkout vX.Y.Z` again.

New scheduled jobs are added by re-running `cmd/install.sh` (Cron step), or add them yourself according to `cmd/crontab`.
