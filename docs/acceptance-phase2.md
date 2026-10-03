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

### A. Phone (emulator): PASS, 2026-10-03

Pixel_9a emulator (`emulator-5554`, cold-booted), app debug build, API `php artisan serve --port=8001` on MySQL `schoolbook_test`, reset with `migrate:fresh --seed` then `AcceptanceSeeder` (stock 200/100/100, no customers, no sales).

```
ACCEPTANCE: 1 logged in as owner@schoolbook.test
ACCEPTANCE: 2 created CUS-0001 Acceptance Academy 056438
ACCEPTANCE: 3-4 bulk order confirmed as INV-2026-000001 GHS 1,800.00; stock dropped 40 + 20
ACCEPTANCE: 4b second invoice INV-2026-000002 GHS 450.00
ACCEPTANCE: 5 instalment 1 GHS 2,000.00: INV-2026-000001 paid, INV-2026-000002 part paid (GHS 250.00 left)
ACCEPTANCE: 6 instalment 2 GHS 300.00 MoMo: INV-2026-000002 paid, GHS 50.00 credit
ACCEPTANCE: 7 credit GHS 50.00 applied to INV-2026-000003; GHS 50.00 left to pay
ACCEPTANCE: 8 voided INV-2026-000003: credit back to GHS 50.00, owes GHS 0.00, 4 books back in stock
ACCEPTANCE RESULT: PASS
02:05 +1: All tests passed!
```

Every step also asserts the figures through the API in pesewas. Final database state after the run:

```
php artisan customers:reconcile   (DB_DATABASE=schoolbook_test)
All money invariants hold.

customer CUS-0001: owes 0, credit 5000
stock: ACC-ENG-P4 160, ACC-MTH-P4 80, ACC-SCI-P4 70
INV-2026-000001 confirmed paid    total 180000 paid 180000 due 0
INV-2026-000002 confirmed paid    total  45000 paid  45000 due 0
INV-2026-000003 void      unpaid  total  10000 paid      0 due 0
RCT-2026-000001 cash 200000, unallocated 0
RCT-2026-000002 momo  30000, unallocated 5000
```

All match the expected column above; no expected figure was changed.

**Earlier attempts (all test-environment or test-script problems, not app behaviour):**

1. Two runs never started: the emulator (up since 2026-10-01) had frozen; `adb devices` listed it but every `adb shell` hung, so Flutter's device discovery waited forever. Fixed by a cold boot with the owner's permission.
2. Failed at step 3: on a phone-sized screen "Save & confirm" was below the fold and not built yet. Treated as a UX bug too: the new-sale screen now has a **pinned bottom bar** (server-priced total, Save draft, Save & confirm) above the scrolling list, with widget tests on a phone-sized surface.
3. Failed at step 5: after typing the amount, the on-screen keyboard shrank the list and the lazily built "Record payment" button was dropped again before the tap. The test now closes the keyboard, scrolls with `scrollUntilVisible`, and taps immediately (detail screens: Record payment, Apply credit, Void). Retry 1 of the 2 allowed then passed.

### B. Admin (Filament): PASS

| Where | Result |
|---|---|
| SQLite (default suite) | 1 test, 71 assertions, `customers:reconcile` "All money invariants hold." |
| MySQL `schoolbook_test` (`DB_CONNECTION=mysql`) | same test, 71 assertions |
