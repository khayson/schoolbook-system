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
- PHP 8.4, Composer
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

Admin panel: `http://localhost:8000/admin`

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

## Docs

| File | Purpose |
|------|---------|
| `docs/build-spec.md` | Product & engineering source of truth |
| `docs/api.md` | REST API reference (filled as endpoints are built) |
| `docs/decisions.md` | ADR-style log of deviations from the spec |
