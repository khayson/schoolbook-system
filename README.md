# Schoolbook Supply System

Ghana schoolbook wholesaler system: Laravel API + Filament admin, Flutter Android app.

## Structure

```
schoolbook-system/
├─ docs/           # build-spec (source of truth), api.md, decisions.md
├─ laravel/        # Laravel 13 API + Filament admin
└─ flutter/        # Flutter Android app
```

Source of truth: [`docs/build-spec.md`](docs/build-spec.md). Agent rules: [`CLAUDE.md`](CLAUDE.md).

## Requirements

- Windows + PowerShell
- PHP 8.4 with the **`intl`** extension (Filament requires it; table pagination calls `Number::format`), plus Composer
  - Check with the same shell you run artisan from: `where php`, `php -v`, `php -m | findstr intl`, and `composer check-platform-reqs` (all lines must say success).
  - Several PHP installs can be on `PATH` (e.g. Kora, Herd, Herd Lite). PowerShell resolves `php.cmd`/`php.bat` first; Git Bash only finds `php.exe` and may pick a different build (Herd Lite has no `intl`). Run PHP commands from PowerShell, or make sure every shell resolves to the same PHP 8.4 build with `intl`.
  - Server checklist: PHP 8.4, extensions `intl`, `pdo_mysql`, `mbstring`, `gd` (DomPDF), `fileinfo`; MySQL 8.0.16+ / MariaDB 10.2+ (CHECK constraints are enforced).
- Flutter 3.47.x stable
- MySQL 8 / MariaDB

## Setup

### Laravel

```powershell
cd laravel
copy .env.example .env
# Set DB_* to your MySQL credentials, then:
php artisan key:generate
# Create app + locking-test databases (once):
#   CREATE DATABASE schoolbook CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#   CREATE DATABASE schoolbook_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate
npm install
composer run dev
# Or API-only without Vite: php artisan serve
```

Admin panel: `http://127.0.0.1:8000/admin`

### Flutter

```powershell
cd flutter
flutter pub get
flutter run
```

Requires a working Android SDK/NDK (or use `flutter run -d chrome` / `-d windows`). Point the app at the API base URL (configured in later phases).

## Tests

Default Laravel tests use **SQLite in-memory** (fast). The Pest group `mysql` uses database `schoolbook_test` (same host/user/password as `DB_*`) for real row locking and number-sequence tests.

```powershell
# Fast suite (SQLite; excludes group mysql)
cd laravel; php artisan test

# MySQL locking / sequence suite
cd laravel; php artisan test --group=mysql

cd laravel; ./vendor/bin/pint --test
cd flutter; flutter test
cd flutter; flutter analyze
```

Set `DB_TEST_DATABASE=schoolbook_test` in `laravel/.env` (already in `.env.example`).

**Destructive commands are guarded.** `migrate:fresh`, `migrate:refresh`, `migrate:reset` and `db:wipe` refuse to run unless the target database name ends in `_test` or the environment is `testing` (Pest). Use the throwaway database explicitly:

```powershell
php artisan migrate:fresh --seed --database=mysql_testing
```

Against any other database the command stops with an explanation. To wipe the dev database on purpose, set the override for that one command: `$env:ALLOW_DESTRUCTIVE_DB='1'; php artisan migrate:fresh --seed; $env:ALLOW_DESTRUCTIVE_DB=$null`. Never put `ALLOW_DESTRUCTIVE_DB` in `.env`: if it is there (any value) the guard refuses these commands until the line is removed.

## Docs

| File | Purpose |
|------|---------|
| `docs/build-spec.md` | Product & engineering source of truth |
| `docs/api.md` | REST API reference (filled as endpoints are built) |
| `docs/decisions.md` | ADR-style log of deviations from the spec |
| `docs/operations.md` | Backups, restore drill, production checklist |
| `docs/reference-catalog.md` | NaCCA approved list: import, review, mapping rules |
