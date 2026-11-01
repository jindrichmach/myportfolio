# 11. Administration

The **System** section of the menu is visible only to the **admin** role.

## 11.1 Initial setup

On the first start (empty database) the app shows a five-step wizard. Nothing is saved
until you click **Finish** in the last step.

| Step | Field | Meaning |
|---|---|---|
| Admin | Name, e-mail, password | The first user with the **admin** role; password at least 12 characters (a longer sentence is recommended) |
| | Language, display currency | The language of the app and e-mails and the currency you see values in; both can be changed in Profile |
| Security | Require multi-factor authentication | All users must set up a passkey or TOTP after signing in |
| E-mail | Set up sending e-mails now | SMTP server, port, encryption, login and sender. **Send a test e-mail** verifies the settings before finishing. It can also be set up later ([11.4 Settings](#114-settings)) |
| Data | Generate demo data | About 5 years of demo history in the admin's account ([2.3 Demo data](02_Instalace.md#23-demo-data)) |
| Summary | | Overview of the choices before finishing |

After finishing you are signed in right away (with MFA required, you first set up the second factor).
Further users are created by the admin in [Administration](#112-users).

## 11.2 Users

**System → Users.** Creating, editing and deactivating accounts. The list can be searched by e-mail or name
and filtered by role and status (active, inactive) — see [1.4 Lists with filters](01_Uvod.md#lists-with-filters).

| Role | Allowed to |
|---|---|
| admin | everything: user management, catalog edits, manual prices, scheduled jobs, log |
| user | own accounts, portfolios, positions and transactions; creating institutions and instruments |

Users' data is separated — the admin cannot see other users' positions or transactions.
The last active admin cannot be deactivated or have the role removed.

When a user forgets their password and the e-mail with the link does not arrive, set their password on the server with
`php8.5 api/bin/set-password.php <email>`. For someone who has lost their passkey, authenticator and backup codes,
`php8.5 api/bin/reset-mfa.php <email>` turns off multi-factor authentication (and signs out all devices) — see
[11.9 Commands on the server](#119-commands-on-the-server).

## 11.3 Prices and rates

**System → Prices & rates.** An overview to check that prices are coming in:

| Column | Meaning |
|---|---|
| Sources | Order of sources for the instrument (e.g. XTB → Yahoo Finance) |
| Last price | Price, date, source; "stale" badge |
| History | Since when and how many days of prices are stored |

Instruments can be searched by symbol or name and filtered by **Price status** (current, stale,
missing) — see [1.4 Lists with filters](01_Uvod.md#lists-with-filters).

Below the table is the latest CNB exchange rate sheet and the latest month of the price index for inflation (the `cron-cpi` job). The **Fetch CNB rates**
and **Update prices** buttons run the jobs immediately in the background.

## 11.4 Settings

The **System → Settings** page holds the settings of the whole instance on tabs. Each tab has its own
**Save** button and is saved separately. The selected tab is in the page address (`?tab=`), so you can
link to it and it stays open after reloading the page.

### E-mail (SMTP)

The **E-mail (SMTP)** tab — the server the app sends e-mails through (password recovery,
verification codes, weekly reports).

| Field | Meaning |
|---|---|
| SMTP server, encryption, port | STARTTLS usually port 587, SSL/TLS 465, no encryption 25 (local server only) |
| Authenticate with username and password | SMTP username and password; the password is stored encrypted and not shown. An empty password when editing = unchanged |
| Sender e-mail and name | The "From" address — the server must allow sending from it |
| Reply-to e-mail | Optional address for replies (Reply-To) |
| Verify server certificate | Turn off only for a server with its own (self-signed) certificate |
| Use these settings | When on, these settings take precedence over `cfg.php`; when off, `cfg.php` applies |

With STARTTLS the app requires encryption — if the server does not offer it, the e-mail is not sent
(otherwise the password would travel over the network unencrypted).

**Test e-mail** sends an e-mail with the values from the form (even unsaved ones) to the given address
and shows the server's response or the error. This way you verify the settings work before saving.

> 💡 Tip: Gmail, Seznam and other services with two-step verification require an
> **app password** instead of the account password.

### Appearance

The **Appearance** tab — the default colour scheme of the instance. It applies to the sign-in page, to e-mails
(colours and logo) and to all users who have not chosen their own scheme in their profile
(see [3.3 Profile](03_Prihlaseni_a_zabezpeceni.md#33-profile)).

There are ten schemes to choose from, ordered by colour: Raspberry, Terracotta, Bronze, Olive, **Forest** (default, green),
Ocean, Indigo, Amethyst, Orchid and Graphite. Each has a light and dark mode and its own logo and favicon colour.
The colours for gain, loss and warnings are the same in all schemes.

The icon of the installed app (PWA) stays in the default green.

## 11.5 Activity log

**System → Activity log** — an audit log of sign-ins, security changes and user management
with the IP address. Actions are described in words; the technical action code (e.g. `auth.login`) is shown in a tooltip.

Filters: **Search** (action code, user's e-mail or name), **Action**, **Entity** and **Period**
(from–to, including the whole last day). For list controls see [1.4 Lists with filters](01_Uvod.md#lists-with-filters).

## 11.6 Scheduled jobs

**System → Scheduled jobs** — the last run, status and recommended frequency of the jobs
(`cron-prices`, `cron-fx`, `cron-snapshot`, `cron-bank-notices`, `cron-weekly-report`, `cron-period-report`, `cron-exchange-sync`, `cron-cpi`, `cron-alerts`, `cron-backup`, `cron-cleanup`). A job that has not
run for a long time or is failing is marked. Use **Run now** to start it manually.

## 11.7 Updates

**System → Updates** shows the installed version and the latest released version from GitHub (repository
releases, including `-dev` development versions) and, for a new version, its description. The check result is kept for
6 hours; the **Check again** button asks right away. The check works only for a public repository;
a different repository (e.g. a fork) is set in `cfg.php` with the `update.github_repo` key or the ENV
`MYPORTFOLIO_UPDATE_GITHUB_REPO`.

Below that is the update procedure for **Docker** and **Native install** — the page preselects the type of this
installation and fills in the path to the app and to PHP; the commands can be copied. You run the update on the server
yourself; the app has no rights to change its own files or the Docker image. The procedure is the same as
in [2.4 Updates](02_Instalace.md#24-updates).

## 11.8 Backups and restore

Every night the `cron-backup` job saves to `storage/backup/` (in Docker `/data/storage/backup/`) a ZIP
with a database dump and the catalog icons. 30 daily and 12 monthly backups are kept. If
`cron.backup.password` is set in `cfg.php`, the ZIP is encrypted (AES-256) — you cannot open it without the password,
so store the password outside the server as well.

Restoring from a backup:

```bash
php8.5 api/bin/restore-backup.php storage/backup/<file>.zip          # only lists the backup contents
php8.5 api/bin/restore-backup.php storage/backup/<file>.zip --yes    # restores the database and icons
# Docker:
docker compose exec app php api/bin/restore-backup.php /data/storage/backup/<file>.zip --yes
```

The restore first makes a **safety backup** of the current state by itself (you will find it in the same folder as
the newest file), then deletes the database, imports the backup, restores the icons and runs migrations — so the backup
can even be from an older version of the app. Everything created after the backup was made is lost by the restore.

> 💡 Tip: You can safely try out a backup by restoring it into a different database:
> `… restore-backup.php <file>.zip --yes --db=myportfolio_zkouska` (icons are not overwritten in that case).
> Then delete the test database.

## 11.9 Commands on the server

| Command | Purpose |
|---|---|
| `php8.5 api/bin/migrate.php [--status]` | Database migrations |
| `php8.5 api/bin/set-password.php <email>` | Setting a password |
| `php8.5 api/bin/reset-mfa.php <email>` | Emergency turning off of an account's MFA |
| `php8.5 api/bin/cron-fx.php [--from=YYYY-MM-DD]` | CNB exchange rates (also backwards) |
| `php8.5 api/bin/backfill-prices.php --all [--from=…]` | Backfilling price history |
| `php8.5 api/bin/backfill-market.php --from=YYYY-MM-DD` | CNB exchange rates, inflation and price history from a date |
| `php8.5 api/bin/cron-cpi.php` | Czech price index for inflation (the first run fetches the whole series from 1996) |
| `php8.5 api/bin/rebuild-lots.php [--user=<id>]` | Recalculating FIFO lots from transactions (after an import or data fix; normally runs by itself) |
| `php8.5 api/bin/backfill-snapshots.php [--user=<id>] [--from=YYYY-MM-DD]` | Recalculating the daily value history (after an import or backfilling prices; normally runs by itself) |
| `php8.5 api/bin/seed-demo.php --user=<email> [--backfill] [--reset] [--no-partner]` | Demo data for a user without accounts, including a demo partner with sharing ([2.3](02_Instalace.md#23-demo-data)); `--reset` only for `app.env` = development |
| `php8.5 api/bin/restore-backup.php <zip> [--yes] [--db=…]` | Restoring from a backup ([11.8](#118-backups-and-restore)) |
