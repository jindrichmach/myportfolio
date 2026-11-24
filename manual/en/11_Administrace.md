# 11. Administration

The **System** section of the menu is visible only to the **admin** role.

## 11.1 Initial setup

On the first start (empty database) the app shows a five-step wizard. Nothing is saved
until you click **Finish** in the last step.

| Step | Field | Meaning |
|---|---|---|
| Admin | Installation code | The code from the installation output or from the `storage/setup-token` file ([2. Installation](02_Instalace.md)); the link from the installation fills it in |
| | Name, e-mail, password | The first user with the **admin** role; password at least 12 characters (a longer sentence is recommended) |
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
releases, including `-dev` development versions). When a newer version is available, the **What the update changes**
section lists the description of every version between the installed and the latest one — so when you skip several
versions you see all the changes. The check result is kept for
6 hours; the **Check again** button asks right away. The check works only for a public repository;
a different repository (e.g. a fork) is set in `cfg.php` with the `update.github_repo` key or the ENV
`MYPORTFOLIO_UPDATE_GITHUB_REPO`.

Below that is the update procedure for **Docker** and **Native install** — the page preselects the type of this
installation and fills in the path to the app and to PHP; the commands can be copied. You run the update on the server
yourself, or, for a native install, with the button in the browser (below). The procedure is the same as
in [2.4 Updates](02_Instalace.md#24-updates).

When one of the versions needs an extra step (e.g. a one-off recalculation of data), the page adds the item
**3. After updating, also run** with the highlighted commands from that version's description. Run them only after
the update has finished.

### Update from the browser

On a **native install**, the **Update from the browser** section shows the **Update to X** button when a newer version
is available. The update runs without SSH. Before it starts, a confirmation dialog opens: database backup, short
downtime, and on an error the files are restored from the backup (the database is never rolled back). In the background
the application runs these steps:

1. **preflight checks** — the same checks as the reasons in the table below; if a condition is not met, the run stops before anything changes,
2. **database backup** — the `cron-backup` job (ZIP into `storage/backup/`); if it fails, the run stops before maintenance mode is turned on,
3. **turning maintenance on** — the web temporarily shows a maintenance message and the API answers 503,
4. **downloading the release bundle** — `myportfolio-X.Y.Z.tar.gz` from the GitHub Release (max. 600 MB),
5. **verifying the SHA-256 checksum** — on a mismatch the bundle is deleted and the run ends,
6. **extracting the bundle** — into `storage/update/`; it checks that the bundle reports the target version and contains all required files,
7. **deploying the files** — replaced and deleted files are moved to the backup `storage/update/backup`, new files come from the bundle; files with identical content are not rewritten,
8. **database migrations** — `php api/bin/migrate.php`,
9. **finishing (writing the version)** — writes the `VERSION` file and cleans up the downloaded bundle. Maintenance mode is always turned off, even after an error.

Rules during deployment:

- The files `cfg.php`, `cfg.local.php`, `cfg.docker.php`, `.env`, `storage/`, `log/`, `private/` and `.git` are never overwritten or deleted.
- Files in `web/dist` that the new release does not have are moved to the backup.
- The file backup always holds only the last version; the next update replaces it.

The progress is shown on the page, the output of the commands is written to `log/update.log`, and the start is recorded
in the activity log ([11.5](11_Administrace.md#115-activity-log)).

> ⚠️ Warning: the update from the browser gives the web process the right to change the application code. Anyone who
> took over the administrator account could change the code permanently. That is why the feature is off by default and
> is turned on deliberately in `cfg.php`.

### Command-line update

The same run can be started on the server as the user who owns the application files. The command does not require
`update.web_update` or MFA — access to the server is the check:

```bash
php8.5 api/bin/native-update.php --target=X.Y.Z --preflight   # only checks the prerequisites, changes nothing
php8.5 api/bin/native-update.php --target=X.Y.Z               # the whole update
```

Exit code: `0` deployed, `1` failed (progress in `log/update.log`), `2` wrong usage or an update is already running.

### Prerequisites

The feature is turned on in `cfg.php` with the key `update.web_update = true` (default `false`). It works only if:

- It is a **native install**, not Docker. An archive installation and a git clone both work. The bundle contains the built code, `vendor`
  and the compiled frontend, so `git`, `composer` and `pnpm` are not needed on the server. A git clone only shows a warning (below).
- A **PHP CLI binary** of the same version as PHP-FPM is available. It is looked up next to the running PHP and in common paths
  (e.g. `/usr/bin/php8.5`); the backup and the migrations are started as a subprocess.
- The PHP extensions **zlib** (extracting the bundle) and **curl**, or `allow_url_fopen` enabled (downloading).
- **The owner of the whole installation**: the web user (the one PHP-FPM runs as) must be able to write to the entire application directory,
  e.g. `sudo chown -R <web-user> <app-directory>`. The installer sets the owner only for `storage/` and `log/`, which is not enough.
  The prerequisites check covers only the root, `api`, `web`, `db`, `manual` and `storage/update`, but files are also moved into subdirectories
  (`api/src`, `web/src`, `db/migrations`…). A missing permission there shows up only at deployment, after maintenance mode is on; the run then
  ends with an error and the files are restored from the backup.
- **Disk space**: at least 512 MB free; for the download at least three times the size of the bundle (the larger of the two values applies).
  The bundle is at most 600 MB.
- **MFA**: before starting from the browser, confirm with a code from the authenticator app (TOTP), or with a passkey.
  A recovery code is not enough. An administrator without a registered TOTP or passkey does not need to confirm.

### Why the button is inactive

If a condition is not met, the button is inactive and a list of reasons is shown below it. The texts are the same as on the page:

| Reason on the page | What it means and what to do |
|---|---|
| Updating from the browser is turned off (update.web_update in cfg.php). | Set `update.web_update = true` in `cfg.php`. |
| The installation runs in Docker — it is updated with a new image. | Set the new version in `docker-compose.production.yml` and run `pull` and `up -d` ([2.4](02_Instalace.md#24-updates)). |
| The zlib extension is missing on the server — the bundle cannot be extracted. | Enable the `zlib` extension for PHP. |
| Neither cURL nor allow_url_fopen is available on the server — the bundle cannot be downloaded. | Enable the `curl` extension, or `allow_url_fopen` in php.ini. |
| The PHP CLI binary was not found — the backup and migrations cannot run. | Install the PHP CLI in the same version as PHP-FPM (e.g. `php8.5`). |
| The web user cannot write to one of the application directories (root, api, web, db, manual, storage/update). | The application directory must be owned by the web user (see Prerequisites). |
| There is little free disk space for the download and the backup. | Free up space (see Prerequisites). |
| The latest release has no bundle for updating from the browser. | The release does not have the `myportfolio-X.Y.Z.tar.gz` and `.sha256` bundle attached; wait for a release that has one. |
| An update is already running. | Wait until it finishes. |

Warnings that do not block the button may be shown below it:

| Warning on the page | What it means |
|---|---|
| The application is a git clone. Updating from the browser changes files outside git, so git status will show local changes afterwards. | Nothing is blocked. After the update, `git status` shows the changed files. |
| OPcache does not check files for changes (opcache.validate_timestamps = 0). After the update, old code may still run until PHP-FPM is restarted. | Nothing is blocked. Restart PHP-FPM after the update. |

### When the update fails

The page shows the step where the update stopped and the end of the log. Maintenance mode is turned off by itself.
What is restored depends on the step:

| Failure in step | What is restored |
|---|---|
| preflight checks, database backup, turning maintenance on, downloading, verifying the SHA-256 checksum, extracting | nothing; the files of the installation were not changed yet |
| deploying the files | the files are restored from the backup `storage/update/backup` (replaced and deleted files come back, newly added ones are removed) |
| database migrations, finishing (writing the version) | nothing — the code is already the new version and the schema may be partly migrated |

The database is **never** restored. If a file cannot be restored during the rollback, the page and the log say so,
and the installation has to be repaired manually from the backup.

If the process ended in the middle (e.g. a server restart), the page marks the run as interrupted. The maintenance
mark expires 30 minutes after the last step, so maintenance mode is turned off no later than that.

What to do:

1. Read `log/update.log`. The error is at the end, the output of the individual steps is above it.
2. Fix the cause on the server (e.g. a missing PHP CLI, too little disk space, a directory the web user cannot write to, a network failure during the download).
3. Run the update again, from the button or with the command in [Command-line update](#command-line-update).
   The migrations are idempotent, so the run can be repeated. To continue by hand, follow [2.4](02_Instalace.md#24-updates) from the step that failed.
4. Check that the page shows the new version and that the site works.

The backup from `cron-backup` was made before the first change to the files. If the run failed after the migrations,
the database schema is already the new one, and the backup from [11.8](11_Administrace.md#118-backups-and-restore) is available for a restore.

## 11.8 Backups and restore

Every night the `cron-backup` job saves to `storage/backup/` (in Docker `/data/storage/backup/`) a ZIP
with a database dump and the catalog icons. 30 daily and 12 monthly backups are kept. If
`cron.backup.password` is set in `cfg.php`, the ZIP is encrypted (AES-256) — you cannot open it without the password,
so store the password outside the server as well.

The administrator manages backups on the page **System → Backups**. The restore can also be run on the server
from the command line, see [Restore from the command line](#restore-from-the-command-line).

### Backup list

The page shows the backups from the backup folder, newest first: the file, the creation date, the size and the status
**Encrypted** or **Not encrypted**. Only the ZIP backups of the whole instance are listed; PDF backups and SQL files are not.

Above the list are these buttons:

- **Back up now** runs the `cron-backup` job (the same as **Run now** in [11.6](11_Administrace.md#116-scheduled-jobs)).
  The progress is shown on the page and the list is refreshed when it finishes. If the backup fails, the details are
  in **System → Scheduled jobs**.
- **Download** on each backup downloads the ZIP of the whole instance. The ZIP contains the data of all users,
  store it securely.
- **Restore…** opens the dialog for restoring the instance from this backup ([below](#restore-from-the-web)).

Downloading and restoring are protected by MFA: confirm with a code from the authenticator app (TOTP), or with a passkey.
A recovery code is not enough. An administrator without a registered TOTP or passkey does not need to confirm.

An encrypted backup cannot be restored while `cron.backup.password` is missing in `cfg.php`; the **Restore…** button
is then inactive for it, and the download still works.

Downloads and restores are recorded in the [Activity log](11_Administrace.md#115-activity-log).

### Restore from the web

The restore overwrites the whole instance. Before the start, a dialog with a warning opens, which says that:

- it overwrites the data of **all** users, not just yours,
- everything created after the backup was made is lost permanently,
- all users including you will be signed out; you sign in with the credentials valid in the backup (the user may not
  exist, or may have a different password),
- old passwords, deleted or revoked API tokens and trusted MFA devices come back to life (they return in the state
  from the backup),
- a safety backup of the current state is created automatically first.

Then type the exact name of the backup into the field **Type the backup name to confirm**. The **Restore the instance**
button is active only when the name matches. Confirm the MFA step-up and the restore starts.

The restore runs in the background and takes minutes. During that time the application is in maintenance mode and the page
shows the current step: the safety backup, unpacking and verifying the backup, dropping tables, database import,
restoring icons, migrations and invalidating sign-ins. When it finishes successfully, the application signs you out
and redirects you to the sign-in page with a message about the restore.

### Why restore is inactive

If any of these conditions applies, the **Restore…** buttons are inactive and the page lists the reasons:

| Reason on the page | What it means and what to do |
|---|---|
| The PHP zip extension is missing. | Install and enable the `zip` extension for PHP. |
| Neither the mariadb nor the mysql client was found on the server. | Install a database client that the web process can use. |
| PHP CLI was not found on the server. | Install the PHP CLI in the same version as PHP-FPM. |
| The web user cannot write to the storage directory. | The web user must own `storage/`. |
| A restore is already running. | Wait until it finishes. |
| An application update is in progress. | Wait until it finishes ([11.7](11_Administrace.md#117-updates)). |

### During the restore

The application is in maintenance mode: the web shows the maintenance message and the API returns 503.
**Scheduled jobs (cron) do not run** — each of their scripts exits immediately and writes nothing to the unfinished
database or to the job history ([11.6](11_Administrace.md#116-scheduled-jobs)). When the restore ends, the jobs run
again on their schedule.

### When the restore fails

The restore can stop at any step. Maintenance mode is then turned off by itself, but **the restore does not roll back
anything** — the database may stay half-imported. The page shows the step where the restore failed, the end of the log
and the name of the safety backup. The same view appears when the restore process stopped running (e.g. the server was
restarted in the middle) — the page then reports the restore as interrupted. If the application stops responding,
the page shows the last known step.

If the restore failed in the step **starting the restore process**, **safety backup** or **unpacking and verifying the backup**, the database was not changed.
Do not roll anything back: just fix the cause (step 3) and try again. In that case the safety backup either was not created
(the page then does not show its name) or is not needed — do not use the command below, because it would overwrite
untouched data with an older backup. For the other cases, proceed as follows:

1. Read `log/restore.log` (in Docker `/data/log/restore.log`); the error is at its end.
2. If the restore failed later, roll back from the safety backup with the command below. Its name is on the page next to
   "Safety backup of the state before the restore". The safety backup was made just before the restore, so you return to
   the state from before the attempt.
3. If the cause was the environment (disk space, an unavailable database), fix it before trying again.
4. Check the sign-in and the data.

```bash
php8.5 api/bin/restore-backup.php storage/backup/<safety-backup>.zip --yes
# Docker:
docker compose exec app php api/bin/restore-backup.php /data/storage/backup/<safety-backup>.zip --yes
```

### Restore from the command line

The restore can also be run on the server without the dialog and MFA; this is useful when the web cannot be used.
The command without `--yes` only lists the backup contents and changes nothing:

```bash
php8.5 api/bin/restore-backup.php storage/backup/<file>.zip          # only lists the backup contents
php8.5 api/bin/restore-backup.php storage/backup/<file>.zip --yes    # restores the database and icons
# Docker:
docker compose exec app php api/bin/restore-backup.php /data/storage/backup/<file>.zip --yes
```

The restore first makes a **safety backup** of the current state by itself (you will find it in the same folder as
the newest file), then deletes the tables and views in the database, imports the backup, restores the icons and runs
migrations and invalidates all sign-ins — so the backup can even be from an older version of the app. Everything created
after the backup was made is lost by the restore.

The command line restores the live database in the same way as the web: it turns on maintenance mode, holds the lock
`storage/restore.lock` (a second restore does not start until the first one finishes) and writes the progress to
`log/restore.log`. Afterwards it writes an entry to the activity log (action `admin.backup.restore`, without a signed-in user).

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
| `php8.5 api/bin/rotate-secret-key.php [--dry-run]` | Re-encrypting stored secrets (exchange keys, IMAP and SMTP passwords, TOTP) with the current encryption key — see below |

**Changing the encryption key** (`app.secret_encryption_key`, e.g. when a leak is suspected): move the current key to
`app.secret_encryption_previous_keys` (a list; in Docker the ENV `MYPORTFOLIO_SECRET_PREVIOUS_KEYS`, comma-separated), set the output of `openssl rand -base64 32` as the new key, run
`rotate-secret-key.php` and, once it succeeds, remove the old key from the list. Without the old key in the list the stored
secrets cannot be read — users would have to enter passwords and keys again and set up TOTP. In production the key must
be set; without it the app does not store secrets (a warning in System → Settings).
