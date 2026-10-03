# Operations

Running the Schoolbook Supply System in production: backups, the restore drill, and the server checklist.

## Nightly jobs

All jobs run through Laravel's scheduler (`routes/console.php`). Times are Africa/Accra (`config/app.php`).

| Time  | Command                     | What happens when it fails |
|-------|-----------------------------|----------------------------|
| daily 00:00 | `idempotency:prune`   | — |
| 01:30 | `backup:run --only-db`      | Retried up to 3 times, 60 s apart. After the last try: `Log::critical('Backup failed', …)` from the package event, plus `Scheduled backup:run exited with failure`. On success, pings the heartbeat URL if one is set |
| 01:50 | `backup:clean` (retention)  | `Log::critical('Backup cleanup failed', …)` |
| 02:00 | `backup:monitor` (health)   | `Log::critical('Unhealthy backup found', …)` when the newest backup is older than 1 day or the backups use more than 2000 MB |
| 02:30 | `customers:reconcile`       | `Log::critical` (money balances drifted) |
| 15 Dec 01:00 | `sequences:prepare`  | — |

Check `storage/logs/laravel.log` for `CRITICAL` entries every morning until email alerts are set up (see [Email alerts](#email-alerts-later)).

A log entry cannot report that the scheduler itself stopped (cron removed, server off, disk full before PHP starts). The [heartbeat](#heartbeat-dead-mans-switch) covers that case.

## Backups

**Reliability:** each run is tried up to 3 times, 60 seconds apart (`tries`, `retry_delay`), so one busy moment does not cost the night's backup. `verify_backup` is on: after writing the zip, the package reopens it and fails the run if it is unreadable or empty.

**What is backed up:** the database only (`mysqldump --single-transaction`, so no table locks while the shop is open). The code is in git; the database is the only thing that cannot be recreated. Uploaded files: none yet. If uploads are added later, add their folder to `backup.source.files.include`.

**Format:** one zip per night, `<disk>/schoolbook/YYYY-MM-DD-HH-mm-ss.zip`, containing `db-dumps/mysql-<database>.sql`. The zip is deflate-compressed. There is no separate gzip step because the gzip compressor shells out to a `gzip` binary that Windows does not have. Set `BACKUP_ARCHIVE_PASSWORD` to encrypt the zip (AES-256). In production the app **refuses to boot** when `offsite` is in `BACKUP_DISKS` and the password is empty (`App\Support\BackupGuard`), so the bucket never holds plain customer data. Keep a copy of the password in the password manager (see [Secrets](#secrets)); an encrypted backup cannot be opened without it.

**Where:** the disks listed in `BACKUP_DISKS` (comma-separated):

| Disk | Location | Configure with |
|------|----------|----------------|
| `backups` (default) | `storage/app/backups` on the same server (`BACKUP_LOCAL_ROOT` to move it, for example to a second drive) | — |
| `offsite` | S3-compatible bucket (AWS S3, Backblaze B2, Wasabi, DigitalOcean Spaces) | `BACKUP_OFFSITE_*` |

A backup kept only on the same server does not protect against losing that server. **Set up the off-site disk before the client enters real data.** The chosen provider is **Cloudflare R2** (S3-compatible; its free allowance is 10 GB-month of storage, 1 million Class A and 10 million Class B operations a month, and a nightly dump is a few MB):

1. `composer require league/flysystem-aws-s3-v3` (needed by the `s3` driver).
2. In Cloudflare, create a **private** R2 bucket (never public access, no custom public domain). Check whether enabling R2 asks for a payment method.
3. Create an R2 API token limited to **that one bucket** with **Object Read & Write**. Copy the access key ID, secret and the account's S3 endpoint `https://<account_id>.r2.cloudflarestorage.com`.
4. In `.env`: `BACKUP_OFFSITE_KEY`, `BACKUP_OFFSITE_SECRET`, `BACKUP_OFFSITE_BUCKET`, `BACKUP_OFFSITE_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com`, `BACKUP_OFFSITE_REGION=auto`, `BACKUP_OFFSITE_PATH_STYLE=true` (confirm both last settings against Cloudflare's current R2 S3-compatibility docs), and `BACKUP_ARCHIVE_PASSWORD` (long and random, also saved in the password manager).
5. `BACKUP_DISKS=backups,offsite`, then `php artisan config:cache`.
6. Run `php artisan backup:run --only-db` once by hand, check that the file appears in the bucket (`php artisan backup:list` shows every disk), then repeat the [restore drill](#restore-drill) **from the bucket copy**.

The storage caps (cleanup deletes the oldest above 2000 MB; the monitor flags more than 2000 MB) keep the bucket well inside the free allowance even if something misbehaves. Other S3-compatible providers (Backblaze B2, Wasabi, AWS S3) work with the same settings and their own endpoint and region.

If one disk fails, the whole run is reported as failed (`continue_on_failure` is false), so a broken off-site upload is never silent.

**Retention** (`backup:clean`, default strategy): keep every backup for 7 days, then one per day for 16 days, one per week for 8 weeks, one per month for 4 months, one per year for 2 years. Above 2000 MB the oldest are deleted. The newest backup is never deleted.

**mysqldump:** must be installed on the server (`mysql-client` / `mariadb-client`). If it is not on `PATH`, set `DB_DUMP_BINARY_PATH` to its folder. On Windows use forward slashes, for example `DB_DUMP_BINARY_PATH="C:/Program Files/MySQL/MySQL Server 8.0/bin"`; backslashes inside double quotes are escape sequences to the `.env` parser.

**Manual commands:**

```
php artisan backup:run --only-db     # take a backup now (before an upgrade, for example)
php artisan backup:list              # backups per disk, newest date, health
php artisan backup:monitor           # health check
```

### Heartbeat (dead man's switch)

`onFailure` and the critical log only work while the scheduler runs. If cron stops or the server is off, nothing runs, so nothing is logged and nobody finds out. A heartbeat service inverts this: the backup pings a URL after every **successful** run, and the service emails or texts you when the ping does **not** arrive.

1. Create a check at a heartbeat service (for example healthchecks.io or Better Stack): period 1 day, grace about 2 hours, alert to the owner's email and phone.
2. Set `BACKUP_HEARTBEAT_URL=<the check's ping URL>` and run `php artisan config:cache`.
3. Only the scheduled run pings (a manual `backup:run` does not), so test it with `php artisan schedule:test --name="backup:run --only-db"` and confirm the check shows a ping.

Without the variable nothing is pinged. A backup that still fails after its 3 tries does not ping either, so the same alert also catches failing backups, the first morning the ping is missing.

### Email alerts (later)

Failures are logged now. To also email them: configure `MAIL_*`, set `BACKUP_NOTIFY_EMAIL`, and add `'mail'` to the three failure notifications in `config/backup.php` (`BackupHasFailedNotification`, `UnhealthyBackupWasFoundNotification`, `CleanupHasFailedNotification`).

## Restore drill

Run this once before go-live and then every quarter. A backup that has never been restored has not been tested. The drill restores into a **scratch database**. It never touches the live database.

The app's database user cannot create databases (by design), so step 2 uses an admin account.

1. **Pick a backup** and copy it somewhere temporary:
   ```
   php artisan backup:list
   # Linux:   cp storage/app/backups/schoolbook/2026-10-03-01-30-00.zip /tmp/drill.zip && cd /tmp && unzip drill.zip
   # Windows: Expand-Archive storage\app\backups\schoolbook\2026-10-03-01-30-00.zip $env:TEMP\drill
   ```
   For an off-site copy, download it from the bucket. If the archive is encrypted, unzip it with `BACKUP_ARCHIVE_PASSWORD` (7-Zip or `unzip -P`).

2. **Create the scratch database** (admin account):
   ```sql
   CREATE DATABASE schoolbook_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON schoolbook_restore.* TO 'schoolbook_app'@'localhost';
   ```

3. **Load the dump:**
   ```
   # Linux
   mysql -u schoolbook_app -p schoolbook_restore < /tmp/db-dumps/mysql-schoolbook.sql
   # Windows (PowerShell): use mysql's own "source", not "<" piping
   & "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u schoolbook_app -p schoolbook_restore -e "source C:/Users/<you>/AppData/Local/Temp/drill/db-dumps/mysql-schoolbook.sql"
   ```

4. **Check it**, pointing the app at the scratch database for these commands only:
   ```
   # Linux
   DB_DATABASE=schoolbook_restore php artisan migrate:status        # every migration "Ran", none pending
   DB_DATABASE=schoolbook_restore php artisan customers:reconcile   # "All money invariants hold.", exit code 0
   # Windows (PowerShell)
   $env:DB_DATABASE='schoolbook_restore'; php artisan migrate:status; php artisan customers:reconcile; $env:DB_DATABASE=$null
   ```
   If `config:cache` has been run on that machine, run `php artisan config:clear` first, or the override is ignored. Also spot-check the newest records: the last invoice number and the last payment should match what the shop recorded that day.

5. **Drop the scratch database** (`DROP DATABASE schoolbook_restore;`) and delete the unzipped dump. It contains customer data.

6. **Record the drill** in the log below: date, backup file, row checks, reconcile result, and who ran it.

**If the live database is lost:** take the newest good backup (off-site if the server is gone), create the database, load the dump as in step 3 into `schoolbook`, run `php artisan migrate --force` (applies any migration newer than the backup), then `customers:reconcile`. Sales and payments made after that backup must be re-entered from paper or phone records.

### Drill log

| Date | Backup | Result | By |
|------|--------|--------|----|
| 2026-10-03 | `schoolbook/2026-10-03-07-50-12.zip` (6.9 KB) of the throwaway `schoolbook_test` database: base seed + `AcceptanceSeeder` + one confirmed sale (GHS 250.00), a GHS 100.00 payment and a voided GHS 5.00 payment, all through the real actions | Reconcile clean before backup. Database wiped, dump restored with `mysql … -e "source …"` (exit 0). `CHECKSUM TABLE` identical before/after for customers, sales, sale_items, payments, payment_allocations, products, stock_movements, number_sequences, activity_log, users. `customers:reconcile`: "All money invariants hold." (exit 0). `migrate:status`: 0 pending. | Claude Code (Phase 3.0) |

This first drill restored into the throwaway test database, not a separate scratch database, because the dev database user cannot create databases. Repeat it on the production server with the steps above before go-live.

## Secrets

Keep a copy of these production secrets in a password manager (for example Bitwarden or 1Password), shared with whoever takes over if the owner is unavailable. Never keep them **only** on the server: if the server dies, they die with it.

| Secret | Why it matters |
|--------|----------------|
| `BACKUP_ARCHIVE_PASSWORD` | Without it the encrypted off-site backups cannot be opened. |
| `DB_PASSWORD` (and the MySQL admin password) | Needed to restore onto a new server. |
| `APP_KEY` | Encrypts sessions and any encrypted columns; restoring data under a new key breaks them. |
| R2 access key and secret, heartbeat URL | To reconnect backups and monitoring on a new server. |

Update the password manager whenever one of these changes.

## Production checklist

Before go-live, and again after any server rebuild:

- [ ] **PHP 8.4** with extensions `intl` (Filament), `pdo_mysql`, `mbstring`, `gd` (DomPDF), `fileinfo`, `zip` (backups). Check with `php -m` and `composer check-platform-reqs` **as the same user and PHP binary that runs the site and cron**. A second PHP install without `intl` is the most likely cause of failures here.
- [ ] **MySQL 8.0.16+ / MariaDB 10.2+** (CHECK constraints are enforced), plus the **mysqldump** client.
- [ ] **`.env` production values:** `APP_ENV=production`, `APP_DEBUG=false` (debug pages leak secrets and stack traces), `APP_URL=https://…`, a fresh `APP_KEY` (`php artisan key:generate` once, never changed afterwards: it encrypts sessions), `LOG_LEVEL=warning` or `error`.
- [ ] **HTTPS only:** TLS certificate (for example Let's Encrypt), HTTP redirected to HTTPS at the web server, `APP_URL` with `https://`. The phone app's `API_BASE_URL` must use `https://` too.
- [ ] **`SESSION_SECURE_COOKIE=true`**, so the admin session cookie is only sent over HTTPS.
- [ ] **`.env` permissions:** owned by the deploy user, readable by the web server group only (`chmod 640 .env`), never inside the web root (the web root is `public/`), never committed.
- [ ] **Owner account:** `php artisan owner:create` (prompts for the password; do not pass `--password` on a shared server, because it lands in shell history).
- [ ] **Scheduler cron** (runs every job in [Nightly jobs](#nightly-jobs)):
  ```
  * * * * * cd /var/www/schoolbook/laravel && php artisan schedule:run >> /dev/null 2>&1
  ```
  Check it with `php artisan schedule:list`. After the first night, `php artisan backup:list` must show a backup.
- [ ] **Queue worker** (`QUEUE_CONNECTION=database`): kept running by supervisor or systemd, restarted after each deploy:
  ```
  php artisan queue:work --tries=3 --max-time=3600
  php artisan queue:restart   # in the deploy script
  ```
- [ ] **Sequences:** `php artisan sequences:prepare` once at go-live (the scheduler repeats it every 15 December).
- [ ] **Caches after each deploy:** `php artisan config:cache`, `route:cache`, `view:cache`, `filament:optimize`. Re-run `config:cache` after every `.env` change.
- [ ] **Storage writable** by the web user: `storage/` and `bootstrap/cache/`.
- [ ] **Backups:** R2 off-site disk enabled and `BACKUP_ARCHIVE_PASSWORD` set ([Backups](#backups)), first manual backup taken, restore drill done from the bucket copy and logged.
- [ ] **Heartbeat:** `BACKUP_HEARTBEAT_URL` set and the first ping seen ([Heartbeat](#heartbeat-dead-mans-switch)).
- [ ] **Secrets** copied to the password manager ([Secrets](#secrets)).
- [ ] **Logs watched:** someone checks `CRITICAL` lines in `storage/logs/laravel.log` daily until email alerts are on.
