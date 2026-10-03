# Phase 2 acceptance: sell and collect

Spec §17 Phase 2 acceptance: *create school, draft a bulk order, confirm (stock drops, invoice issued), record two instalments (one auto-allocated across two invoices, one overpayment becoming credit), void an invoice and see balances reverse correctly*, on both clients, and `customers:reconcile` reports clean.

The scenario is automated twice, with the same books and amounts:

- **A. Phone:** `flutter/integration_test/phase2_acceptance_test.dart` drives the real app UI on an Android emulator against a live API (`php artisan serve`) on MySQL. Every figure is also checked through the API in pesewas.
- **B. Admin:** `laravel/tests/Feature/Acceptance/Phase2FilamentAcceptanceTest.php` drives the same steps through the real Filament pages and actions (Livewire), then runs `customers:reconcile`.

## Data

`Database\Seeders\AcceptanceSeeder` (never part of the default seed) adds three Primary 4 books and receives opening stock through `ReceiveStock`:

| SKU | Price | Opening stock |
|---|---|---|
| ACC-ENG-P4 English Reader | GHS 25.00 | 200 |
| ACC-MTH-P4 Mathematics | GHS 40.00 | 100 |
| ACC-SCI-P4 Science | GHS 15.00 | 100 |

## Steps and expected results

| # | Step | Expected |
|---|---|---|
| 1 | Sign in as the owner | Dashboard |
| 2 | Create school "Acceptance Academy", Greater Accra | Code `CUS-nnnn` |
| 3 | New sale: 40 English + 20 Mathematics, live total | Preview total **GHS 1,800.00** (server-priced) |
| 4 | Save & confirm | Invoice `INV-YYYY-nnnnnn` issued; stock English 200 → 160, Mathematics 100 → 80 |
| 4b | Second invoice: 30 Science | **GHS 450.00** |
| 5 | Instalment 1: GHS 2,000 cash, "oldest invoices first" | Applied GHS 2,000.00, credit GHS 0.00; invoice 1 **paid**, invoice 2 **part paid**, GHS 250.00 left |
| 6 | Instalment 2: GHS 300 Mobile Money (reference) | Applied GHS 250.00, **kept as credit GHS 50.00**; invoice 2 paid; customer credit GHS 50.00, owes GHS 0.00 |
| 7 | Third invoice: 4 English (GHS 100.00), confirm without credit; then **Apply credit** | GHS 50.00 applied; invoice 3 has GHS 50.00 left; credit GHS 0.00; owes GHS 50.00 |
| 8 | **Void** invoice 3 with a reason | Invoice void, amount paid 0; the GHS 50.00 goes back to credit; owes GHS 0.00; 4 English books back in stock (160) |
| 9 | `php artisan customers:reconcile` | "All money invariants hold." |

## How to run

**A. Phone (emulator):**

```powershell
# Throwaway database only (schoolbook_test is wiped by the mysql test group anyway).
cd laravel
$env:DB_DATABASE='schoolbook_test'
php artisan migrate:fresh --seed --force
php artisan db:seed --class=AcceptanceSeeder --force
php artisan serve --host=127.0.0.1 --port=8001      # leave running

# second terminal
cd flutter
flutter test integration_test/phase2_acceptance_test.dart -d emulator-5554 --dart-define=API_BASE_URL=http://10.0.2.2:8001/api/v1

# afterwards, still with DB_DATABASE=schoolbook_test
cd laravel; php artisan customers:reconcile
```

**B. Admin (Filament):** `cd laravel; php artisan test --filter=Phase2FilamentAcceptanceTest`

**Manual visual pass (owner):** run the app with `flutter run --dart-define=API_BASE_URL=...` and open `http://127.0.0.1:8000/admin`, then walk the table above by hand. The automated runs prove behaviour and figures; they do not judge layout, wording or how it feels on a real phone.

## Results

Run on 2026-10-03 (Phase 2D.3).

| Part | Where | Result |
|---|---|---|
| B. Admin (Filament) | SQLite (default suite) | **PASS**: 1 test, 71 assertions; every step's figures as in the table; `customers:reconcile` "All money invariants hold." |
| B. Admin (Filament) | **MySQL** `schoolbook_test` (`DB_CONNECTION=mysql`) | **PASS**: same test, 71 assertions, 8.9 s |
| A. Phone (emulator) | Pixel 9a emulator `emulator-5554`, API on `:8001` | **NOT RUN to completion** (see below) |

**Why A did not complete:** the only emulator already had the owner's own `flutter run` debug session attached (running since 2026-10-01). An integration test reinstalls the app on the device, which would have killed that session, so it was not forced. Two attempts produced no output: the first waited behind a hung `flutter devices` call holding Flutter's startup lock; the second sat idle with no Gradle/adb activity and was stopped after several minutes. The API server on `:8001` and the seeded `schoolbook_test` data worked (owner login via the API returned a token).

**What already covers the phone flow without a device:** widget and controller tests drive the same screens and dialogs with a fake API: new sale (server-priced totals, debounced, stale responses ignored; draft idempotency across a lost connection), sale detail (confirm; price changed with the old -> new diff, accept = re-save then confirm with a new key; insufficient stock listing every book; credit warning with override; apply-credit toggle; void with reason; share invoice), record payment (exact GHS parsing, reference rules, keep-as-credit, retry with the same key, restart restore), customers form.

**To finish A** (about 10 minutes once the emulator is free): stop the existing `flutter run`, then follow "How to run, A" above. The test prints `ACCEPTANCE RESULT: PASS` and the step log; then run `customers:reconcile` against `schoolbook_test`. Record the output here and tag `phase-2-complete`.
