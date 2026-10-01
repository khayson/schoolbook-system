# Decisions log

ADR-style notes when implementation must deviate from `docs/build-spec.md`.

## 2026-10-01 — Flutter SDK version

**Context:** Spec originally said Flutter 3.44.x; machine runs 3.47.3.

**Decision:** Spec, `CLAUDE.md`, and `AGENT.md` updated to **Flutter 3.47.x** stable.

## 2026-10-01 — MySQL connected (Phase 0)

**Context:** Owner set DB credentials in `laravel/.env`. Initially connected as `root`.

**Decision:** Switched app DB user to limited `schoolbook_app` with privileges only on `schoolbook` and `schoolbook_test`. Production must never use root. Pest default stays SQLite in-memory; group `mysql` uses `schoolbook_test`.

## 2026-10-01 — Flutter NDK incomplete install (Phase 0)

**Context:** `flutter run` failed on incomplete NDK `28.2` stub; `sdkmanager` crash.

**Decision:** Stub removed; Gradle installed full NDK 28.2 + Platform 34. Debug APK / `flutter run` succeed.

**Also:** `composer run dev` needed `npm install` (Vite). Pail removed from `dev` script (no `pcntl` on Windows).

## 2026-10-01 — Filament session cookie host

**Context:** API (Sanctum Bearer) login worked on the emulator; Filament web login failed.

**Decision:** Set `SESSION_DOMAIN=null` and `APP_URL=http://127.0.0.1:8000` so session cookies work when browsing via `127.0.0.1` (a `SESSION_DOMAIN=localhost` cookie is not sent to `127.0.0.1`). Prefer opening admin at `http://127.0.0.1:8000/admin`.

## 2026-10-01 — number_sequences.year non-null (Phase 1)

**Context:** Spec §6.1 had nullable `year` with `unique(key, year)`. MySQL UNIQUE treats NULL as distinct, so multiple “no year” rows (e.g. `CUS`) could coexist and mint duplicate codes.

**Decision:** `year` is `unsignedSmallInteger` NOT NULL default `0` (`0` = no year). Keep `unique(key, year)`. Spec §6.1 updated. Production owners: `php artisan owner:create` (OwnerSeeder only runs in local/testing).

## 2026-10-01 — Morph map + same-cost merge only (Phase 1)

**Context:** Reviewer required short morph aliases before more movements land, and same-product lines at different unit costs must not collapse (breaks Phase 5 weighted-average history).

**Decision:** `Relation::enforceMorphMap()` with aliases `user`, `product`, `goods_receipt`, `sale`, `payment`, `stock_count`, `sale_return`, `purchase_order`. (`user`/`product` required because Sanctum tokens and future activity log use morphs.) `ReceiveStock` merges input lines only when `product_id` and `unit_cost` match; otherwise separate `goods_receipt_items` / movements. Each product is locked once per receipt (sorted ids). Spec §§5.13 and 6.3 updated.

## 2026-10-01 — Phase 2.0 carry-over hardening

**Context:** Phase 1 accepted with carry-overs before sales code: real idempotency, float-free money parsing, `stock_on_hand` not fillable, activity log on product prices, `(product_id, id)` ledger index, receipt `exists:products,id`.

**Decision:**
- Reusable `idempotent` middleware + `idempotency_keys` table; applied to `POST /stock/receipts` now (confirm/payments later).
- `Money::ghsToPesewas` / Flutter `parseGhsToPesewas` parse decimal strings only (no float multiply); >2 decimals rejected.
- Spec §§5.14–5.16: lock order products→customer→invoice sequence last; invoice year from confirmation date; never edit shipped migrations.

## 2026-10-01 — Idempotency claim lifecycle (Phase 2A start)

**Context:** Review of `EnsureIdempotency`: failed actions left `response_status=0` (permanent 409); non-2xx responses were stored and replayed; 409 code name; no prune.

**Decision:** Release claim on throw; delete claim on non-2xx (so corrected retries work); 409 code `request_in_progress`; `idempotency:prune` (72h) scheduled daily. Flutter reuses one key per save attempt until success or form change.

## 2026-10-01 — Phase 2A.1 review fixes

**Context:** 2A review: every `DomainException` rendered as `insufficient_stock`; bad input could 500; duplicate product lines not merged; client could set `source`; drafts locked the customer row; sales deletable; no audit log on sales.

**Decision:**
- Typed exceptions under `App\Exceptions`: `ApiDomainException(message, errorCode, status, details, errors)` with `SaleNotEditableException` (409 `sale_not_editable`), `InsufficientStockException` (422, per-item list), `PriceChangedException` (409, new priced order), `CreditLimitExceededException` (409, `override_flag: override_credit_limit`) and `InvalidInputException` (422 `validation_failed`, field-keyed `errors`; the service-level second line of defence). Extra payload goes under `details` so it can never collide with envelope keys. The blanket `DomainException` mapping is gone; `AdjustStock` now throws `InsufficientStockException`.
- Form Requests: customer/product `exists` rules exclude soft-deleted and inactive rows; `override_reason` is `required_with` the override price. `PricingService` re-checks (unknown/inactive product, quantity, override reason, negative price) and throws `InvalidInputException`.
- `PricingService` merges lines by (product, override price, trimmed reason), first-appearance order. A reason sent without an override price is dropped. `PricedOrder::quantitiesByProduct()` sums per product for the 2B stock check (one product can still sit on several lines with different override prices).
- `source` is not accepted from the staff API; `CreateDraftSale::execute(..., SaleSource $source = Staff)` is set by calling code (the portal will pass `Portal`).
- Drafts read the customer without `lockForUpdate`. **InnoDB limit:** inserting a `sales` row takes a *shared* lock on the parent `customers` row for the FK check. Concurrent drafts for one customer no longer serialize, and editing an existing draft never touches the customer row, but creating a *new* draft still waits until a payment or confirm holding that customer row commits. Accepted: those transactions are short. Covered by `tests/Mysql/DraftSaleNoCustomerLockTest.php`.
- Sales are never deleted: `SalePolicy::delete` is false and `Sale::deleting` throws. `amount_paid` and `balance_due` were removed from `Sale` fillable (the 2A architect note had been missed); actions set them explicitly.
- `Sale` uses `LogsActivity` (status, totals, payment fields, confirm/cancel/deliver fields, dirty only). Draft actions run under `CauserResolver::withCauser($user)` so the acting user is recorded even outside an HTTP request. Each new price override logs a `price_overridden` activity with reason, base price and unit price; an unchanged override is not logged again when the draft is re-saved.
- `config/app.php` timezone is `Africa/Accra` (UTC+0, no DST; the explicit name states the intent).
