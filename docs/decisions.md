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
- Spec §§5.14–5.16: lock order products→customer→invoice sequence last (**superseded by Phase 2B.1**: customer → sale → items → products → sequence); invoice year from confirmation date; never edit shipped migrations.

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

## 2026-10-01 — Phase 2B: confirm, cancel, void, deliver, invoice

**Context:** 2B prompt plus reviewer additions (item immutability, deactivated products at confirm, one transition guard).

**Decision:**
- **Lock order** (**superseded by Phase 2B.1**, see below) for every sale state change, in `Actions/Sales/Concerns/LocksSaleRows`: sale row → its items → products (ascending id, one at a time) → customer → invoice sequence (last). All of these are locking reads and run before any plain read in the transaction, because MySQL REPEATABLE READ fixes the snapshot at the first non-locking read; `PricingService` (plain reads) therefore sees product rows no older than the ones we hold. The credit check's outstanding sum uses `sharedLock()` for the same reason.
- **Price change:** compared per line (product, quantity, unit price, line total, same order and count) plus order totals. A base-price change under an unchanged override is not a change; the verified base price is still written to the snapshot. On 409 nothing is written. To accept new prices the client re-saves the draft (`PUT /sales/{id}` with `{}` reprices), then confirms again; no new "accept" flag was added to confirm.
- **Due date:** request → draft's `due_date` → confirmation date + `default_payment_terms_days`.
- **Inactive/deleted product or inactive customer at confirm:** rejected by the repricing step as `422 validation_failed` keyed to `items.N.product_id` / `customer_id`.
- **Transitions:** `SaleStatus::canTransitionTo()` holds the state machine; actions call `Sale::assertCanTransitionTo()`. Every refusal is `409 sale_not_editable` with `details.action` (`update|confirm|cancel|void|deliver|invoice`) rather than a second code, so clients handle "the sale changed under you" one way. `requested → confirmed|cancelled` is allowed for the later portal flow.
- **Item immutability:** `SaleItem` creating/updating/deleting throw unless the parent sale's status in the database is `draft`. `ConfirmSale` rewrites the verified snapshot while the locked row is still draft, then flips the status.
- **Void schema:** spec 6.5 had no void columns; added `voided_at`, `voided_by`, `void_reason` in a new migration rather than reusing `cancel_*` (cancel and void are different transitions). Void reverses at each line's snapshot `unit_cost`, keeps `invoice_no`, and in 2B is refused with `409 sale_has_payments` when `amount_paid > 0`; 2C replaces that with allocation reversal. It already takes the customer lock so 2C needs no lock-order change.
- **Invoice PDF:** `RenderInvoicePdf` action (shared with Filament in 2D), confirmed sales only; `Money::formatGhsGrouped()` for display.
- **401 envelope:** unauthenticated API requests now return `{message, code: "unauthenticated", errors: {}}` (previously Laravel's default body without `code`).
- **Note for 2C** (**resolved in Phase 2B.1**): `RecordPayment` will lock customer → sales. `VoidSale` locks sale → … → customer. For the same sale these orders are opposite, so a void racing a payment on that invoice can deadlock; InnoDB aborts one and it can be retried. 2C should either lock the customer before the sale in `VoidSale` (read the sale's customer_id first) or retry on deadlock. To be settled in 2C.
- `sales.idempotency_key` stays unused (the middleware table covers confirm). Drop it in a 2C migration unless 2C finds a use.

## 2026-10-02 — Phase 2B.1: global lock order, delivered sales

**Context:** 2B review. The 2B order (sale → items → products → customer) and the coming payment order (customer → sales) are opposite for the same sale, so a void racing a payment could deadlock. "InnoDB aborts one, client retries" is not acceptable for money code.

**Decision:**
- **One global lock order (spec 5.14):** customer → sale(s) → sale items → products (ascending id) → invoice sequence (last). Every money action locks the customer first, which serializes them per customer, so the order of sale locks among them cannot cycle. Stock-only actions lock products only. `CancelSale` and `MarkDelivered` lock only the sale row (nothing after it), which is consistent with the order.
- `LocksSaleRows::lockCustomerAndSale()` takes `customer_id` from the sale loaded outside the transaction (route binding), locks the customer, locks the sale, and if the locked sale now has a different customer (a draft was reassigned meanwhile) throws `409 sale_state_conflict` (`details.retry: true`) before anything is written. No plain read happens before these locks, so the MySQL snapshot rule from 2B still holds. `ConfirmSale` reuses the locked customer for pricing instead of reading it again.
- **Proof:** `tests/Mysql/SaleLockOrderTest.php` holds the customer lock on one connection, starts a void worker, waits until the worker is blocked on the customer (via `information_schema.PROCESSLIST`; the test user has no PROCESS privilege for `INNODB_TRX`), then locks the sale from a second connection with a 1 s timeout. With the old sale-first order this fails with 1205 (verified by temporarily swapping the order).
- **Void refuses delivered sales:** `409 sale_delivered`. Voiding would put books back in stock that have physically left; delivered goods come back through returns (Phase 5). Spec 9.1 amended.
- **Locks held shorter:** `ConfirmSale` and `VoidSale` reload the sale for the response after commit, not inside the transaction.
- Unchanged for now, to be replaced in 2C: the credit check's `sharedLock()` sum over confirmed sales (2C adds a cached `customers.outstanding_balance` kept under the customer lock, plus a reconcile command). `apply_credit` on confirm is 2C.

## 2026-10-02 — Phase 2C.1: payment schema, cached balances, money invariants

**Context:** Reviewer design for 2C: allocations as a signed ledger with reversal rows, explicit money invariants, a cached `customers.outstanding_balance` read by the credit check, and payments in the global lock order.

**Decision:**
- **Schema (new migrations only):** `payments` (no soft delete; MySQL CHECKs `amount > 0` and `unallocated_amount <= amount`; SQLite cannot add CHECKs after creation, so tests on SQLite rely on validation + invariants), `payment_allocations` (signed `amount`, `reversal_of_id` nullable **unique** self-FK so an allocation can be reversed only once, `created_at` only), `customers.outstanding_balance` plus a separate, idempotent data-only backfill migration (separate so it can be re-run and tested on its own), receivables indexes on `sales`.
- **Models:** `Payment` (activity-logged, delete throws, `unallocated_amount` not fillable), `PaymentAllocation` (update and delete throw). `PaymentPolicy::delete` is false. Morph aliases `payment` (now a real class) and `payment_allocation`. New enums `PaymentMethod` (`requiresReference()` for non-cash) and `PaymentRecordStatus` (valid/void), kept apart from the sale's `PaymentStatus`, which gained `derive(total, amountPaid)`.
- **Cached outstanding balance:** `ConfirmSale` adds the total and `VoidSale` subtracts the prior `balance_due`, both on the customer row locked first. The credit check now reads that locked row; the shared-lock sum is gone. `ConfirmSale` sets `payment_status` via `derive()`, so a zero-total sale confirms as `paid`.
- **Invariants:** one class, `App\Services\MoneyInvariants`. `check(?customerIds)` is read-only set-based SQL that works on SQLite and MySQL; `balance_due` is compared as `balance_due + amount_paid = total` so MySQL never subtracts unsigned columns. It also checks ledger shape (positive originals; a reversal negates one original on the same payment and sale). `repair(customerId)` rebuilds every cache from the ledger under customer → sales → payments locks; ledger rows are never repaired, only reported.
- **`customers:reconcile`:** report-only by default, exits non-zero on any violation; `--fix` repairs affected customers then re-checks and still fails if ledger problems remain; `--customer=` limits scope. Scheduled daily at 02:30.
- **Tests:** the confirm, lifecycle and invoice suites assert the invariants after every test (`afterEach`). Verified the hook bites by temporarily removing the `outstanding_balance` update from `VoidSale`: the lifecycle suite failed with `customer_outstanding`. Until `RecordPayment` exists, tests use an `applyLedgerPayment()` fixture that writes a consistent payment + allocation + caches; 2C.2 replaces it with the real action.
- **Lock order (spec 5.14):** customer → sales (ascending id) → payments (ascending id) → sale items → products (ascending id) → number sequence last.
- Still open from 2B: `sales.idempotency_key` is unused; proposing to drop it in 2C.2 (and the spec 6.6 `payments.idempotency_key` was not created, for the same reason).

