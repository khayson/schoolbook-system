# Schoolbook Supply System

Monorepo: `laravel/` (API + Filament admin) and `flutter/` (Android app).
Source of truth: `docs/build-spec.md`. Read it before any work.

## Rules
- Work one phase and one task at a time. Do not start a phase until the previous passes acceptance.
- All business logic lives in `laravel/app/Actions/**`. Controllers and Filament call Actions; Flutter never computes money or stock.
- Money is integer pesewas everywhere. Never floats.
- Stock changes only through `stock_movements`. Never edit `stock_on_hand` directly.
- Financial and stock records are immutable; corrections are reversals.
- Sales go through `PricingService` always.
- Wrap multi-row writes in DB transactions; lock rows for stock/balance changes.
- Validation in Form Requests, authorization in Policies, output through API Resources.
- Write tests with every task (Pest for Laravel, flutter_test for Flutter).
- If the spec is ambiguous or you must deviate, stop and ask, or log it in `docs/decisions.md`.

## Environment
Windows + PowerShell. PHP 8.4. Flutter 3.47.x stable. MySQL/MariaDB.

## Commands
- Laravel: `cd laravel; php artisan serve; php artisan test; ./vendor/bin/pint`
- Flutter: `cd flutter; flutter run; flutter test; flutter analyze`