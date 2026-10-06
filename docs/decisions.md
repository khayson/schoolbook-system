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

## 2026-10-02 — Phase 2C.2: payments, credit, receipts

**Context:** 2C.2 prompt plus reviewer extras (single-snapshot invariant check, visible reconcile failures, drop `sales.idempotency_key`, non-zero allocation CHECK, real action instead of the test fixture, invariants after every money test).

**Decision:**
- **One writer for the ledger:** `App\Services\AllocationLedger`. `allocate()` moves money from a payment's unallocated pool onto a sale; `reverse()` adds the negating row and moves it back; `applyCredit()` draws one sale's amount FIFO from given payments. Each call updates every cache it touches, so the invariants hold after every call, not just at the end of an action. Model used throughout: a valid payment's `unallocated_amount` **is** customer credit. `RecordPayment` therefore books the whole amount as credit first and then allocates, `VoidPayment` reverses everything back into the pool and then removes the pool from credit, and `VoidSale` reverses into the pools (credit). The callers must already hold the rows in the global order; `LogicException` guards catch misuse.
- **Locks:** `RecordPayment` customer → the sales being paid → receipt sequence last. `VoidPayment` customer → affected sales → the payment. `AllocateCredit` customer → target sales → credit payments. `VoidSale` customer → sale → payments with money on it → items → products. `ConfirmSale(apply_credit)` customer → sale → credit payments → items → products → invoice sequence. Sales are only ever locked through the customer that is held: a foreign `sale_id` in a request is never locked (a plain read after the locks then reports `other_customer` vs `not_found`).
- **Effective allocations** = originals with no reversal row; read with a shared (locking) read so it can precede the first plain read; stable because every ledger writer holds the customer lock.
- **Codes:** `sale_not_payable` (422, `reason`), `allocation_exceeds_balance` (422), `allocation_exceeds_payment` (422), `allocation_exceeds_credit` (422, added: credit requests are not a payment amount), `no_credit_available` (409), `payment_already_void` (409, `action: void|receipt`). `sale_has_payments` is removed: void now reverses money instead of refusing.
- **Inactive customers can still pay** (they may owe money); soft-deleted ones cannot. `apply_credit` with no credit is a no-op, not an error. `POST /customers/{id}/apply-credit` also takes the idempotency middleware (a retried request must not apply credit twice).
- **Credit limit with `apply_credit`:** projected = outstanding + total − credit applied.
- **Bug found and fixed in `NumberSequenceService` (Phase 1 code):** `INSERT IGNORE` of the counter row inside the business transaction takes a shared lock on the existing row (duplicate-key check). Two transactions holding it that then ask `FOR UPDATE` deadlock (1213). It never showed before because every concurrent test also shared a product lock, which serialized them first; six concurrent payments for different customers deadlocked 4 of 6. Fix: the row is created (if missing) on a separate autocommit connection on MySQL, so that shared lock lasts one statement, and the transaction takes a single exclusive lock. Rollbacks still never burn a number (the increment stays in the transaction). Proven both ways: the new receipt and disjoint-product invoice tests fail with 1213 against the old code and pass (three runs) with the fix.
- **Extras:** `MoneyInvariants::check()` runs in one transaction (one MySQL snapshot). The scheduled `customers:reconcile` has `->onFailure()` → `Log::critical` with the violation count, per-invariant counts and customer ids. New migrations drop `sales.idempotency_key` (no references) and add MySQL `CHECK (payment_allocations.amount <> 0)`. Tests use the real `RecordPayment` (fixture removed) and every payment, void-payment and void-sale test file asserts the invariants in `afterEach`.
- **Process:** mutation checks now use a temporary WIP commit, restore the file from it, then `git reset --soft`; real work is never at risk. Mutations run this time: old sequence code (deadlocks reproduced) and `reverse()` without the credit update (invariants fail with `customer_credit_balance`).

## 2026-10-02 — Phase 2D.0: carry-over

- **Amount cap:** `Money::MAX_PESEWAS` = 100,000,000,000 (GHS 1 billion) on payment amounts and explicit/credit allocation amounts (Form Requests), and re-checked in `RecordPayment`. Spec rule 19.
- **ReceiveStock and "sequence last":** checked the code; it already locks every product before taking the GRN number (unchanged since Phase 1), so no behaviour change; added a comment naming the rule. Reported back to the reviewer.
- **`sequences:prepare`** (`--year`, default next year): creates `inv`, `rct`, `grn` rows through `NumberSequenceService::prepare()` (the same side-connection path), idempotent, never resets a counter. Scheduled `yearlyOn(12, 15, '01:00')`. Spec rule 18 records that sequence rows are never created inside a business transaction.
- **apply-credit with no open invoices:** `200` with `applied_total: 0` (documented in spec 9.3 and api.md; tested).

## 2026-10-02 — Phase 2D.1: Filament customers, sales, payments

**Context:** Owner admin for Phase 2. Rule: every button calls an existing Action class; Filament never writes money or stock itself.

**Decision:**
- **Shared helpers (`app/Filament/Support`):** `GhsInput` (text, never `numeric()`, regex `^\d{1,10}(\.\d{1,2})?$`, parsed by `Money::ghsToPesewas`, cap `Money::MAX_PESEWAS`; so 19.99 / 0.29 / 1.10 are exact). `DomainErrorNotifier::attempt()` runs an Action; a typed `ApiDomainException` becomes an escaped notification and the modal/form stays open (`halt`), nothing written. Mapped titles: `price_changed` (changed lines old -> new and the total; tells the user to "Re-price draft"), `insufficient_stock` (one line per book), `credit_limit_exceeded` (warning with the arithmetic; tick "Override credit limit"), `validation_failed`, the allocation codes; anything else falls back to a headline of the code plus the message. `InteractsWithCurrentUser` supplies `getUser()`.
- **Sales:** draft create/edit with a repeater (level/subject/language chips narrow the book list, which shows stock; quantity; override price + reason). Line and order prices are read-only previews from `PricingService`. Create -> `CreateDraftSale`, edit (drafts only, `canEdit`) -> `UpdateDraftSale`. View page actions: Edit draft, Re-price draft (`UpdateDraftSale` with `{}`), Confirm (due date, apply credit toggle when the customer has credit, override credit limit toggle when a limit exists), Cancel, Mark delivered, Void (reason), Record payment (link to the payment form for this customer), Download invoice. Infolist shows items with override notes, totals, the allocation ledger, cancel/void details. List filters: status, payment status, customer, sale date range, overdue (confirmed, balance > 0, due date past).
- **Payments:** create only through `RecordPayment` (GHS input, method, reference required for non-cash, paid_at not in the future, notes, allocation mode oldest / choose invoices / keep as credit); `?customer_id=` preselects. View: allocation ledger, Void (reason) and Download receipt, both hidden once void. No edit page, `canEdit`/`canDelete` false. Filters: status, method, customer, paid_at range.
- **Customers:** credit limit via `GhsInput` (blank = no limit), balances shown on the edit page and as list columns (owes, credit, limit), filters type/region/active/owes/has credit. Header actions Record payment (same form as Payments), Apply credit (oldest or chosen invoices), Deactivate/Reactivate. Never deleted (`canDelete`/`canDeleteAny` false, no delete action). Read-only relation managers for sales and payments.
- **Bugs found while testing:**
  - `CreateSale` (2A), `CreateGoodsReceipt` and the product CSV import (Phase 1) called `$this->getUser()`, which Filament pages do not have. They would have thrown on submit; no test had submitted those pages. Fixed with `InteractsWithCurrentUser`.
  - Filament injects closure arguments **by parameter name**: a filter written `->query(fn (Builder $q) => ...)` receives a fresh builder and filters nothing. Use `$query`. Caught by the customer list test.
- **Environment risk, not fixed in code:** Filament declares `ext-intl` as a requirement and its table pagination calls `Number::format`, which throws without `intl`. This machine's PHP (Herd Lite static build) has no `intl` extension (`NumberFormatter` exists only through a polyfill). Twice, on the first test run after editing files, list-page tests failed with "The intl PHP extension is required"; the same tests then passed on every rerun and in full-suite runs, so I could not reproduce the trigger. Recommendation: run on a PHP build with `intl` (full Herd or a standard PHP 8.4 build) before production, and add `ext-intl` to the server checklist.

## 2026-10-02 — Phase 2D.2: cleanups, duplicate payment references

**Cleanups:**
- Catalog resources (Product, Level, Subject, Language, Publisher): no force delete and no bulk delete anywhere. `RefusesDeletingInUse` policies: delete is allowed only when nothing refers to the record (products: any stock movement, sale item or goods-receipt item; lookups: any product, including soft-deleted ones); otherwise `403` with "This record is in use. Deactivate it instead of deleting it." and the Filament delete button is hidden. `forceDelete`, `forceDeleteAny`, `deleteAny` are false. Retire with `is_active`.
- Deleted the unregistered `EditStockMovement` **and** `CreateStockMovement` pages (a create page for an immutable ledger would bypass `AdjustStock`).
- `GhsInput` accepts correctly grouped thousands (`1,250`, `1,250,000.50`); commas are stripped before the exact decimal parse; misplaced commas (`1,00`, `12,50.00`, `1.000,50`) are rejected.
- **`intl` root cause:** three PHP builds are on `PATH` here: Kora (`php.cmd`, PHP 8.4.26, has `intl`), Herd (`php.bat`, 8.4.26, has `intl`), Herd Lite (`php.exe`, **8.5.0, no `intl`**). PowerShell resolves Kora; Git Bash only resolves `php.exe`, i.e. Herd Lite. Both "flaky" failures were runs started from Git Bash. All PHP commands now run from PowerShell; `composer check-platform-reqs` passes there. README prerequisites and a server checklist now name `intl`.

**Duplicate payment references:**
- Generated column `payments.reference_key` (MySQL `STORED`, SQLite `VIRTUAL`): `method:UPPER(reference without spaces)` when `status = valid`, `method <> cash` and a reference is present, else NULL; unique index. The database computes it; the app never writes it. `Payment::referenceKey()` mirrors it in PHP (tested against the stored value).
- `RecordPayment`: a plain-read pre-check after the customer/sale locks gives the friendly error; the guarantee is the index. A `UniqueConstraintViolationException` on `reference_key` while saving (a concurrent request committed first) is mapped to the same `409 duplicate_reference`, reading the winner with a locking read. Details name the existing receipt, customer, amount and date.
- Scope is per method (a MoMo id and a cheque number may coincide) and global across customers (the same MoMo transaction cannot pay two schools). Cash references are free text and never checked. Voiding sets status `void`, so the key becomes NULL and the reference can be recorded again.
- Tests: case/space normalisation, per-method scope, cash exemption, void frees it, the DB refuses a duplicate even with the pre-check bypassed, API and Filament mapping; MySQL: two simultaneous identical references (exactly one wins), and a deterministic in-flight test where the competing row is uncommitted so only the unique index can catch it. Mutation check: with the violation mapping disabled that test fails (raw database error, exit 1).

## 2026-10-02 — Phase 2D.2: Flutter customers and payments

**Decision:**
- **Errors:** `ApiException` now carries `code`, `message`, field `errors` and `details`, and `isNetworkError` for requests that got no response (offline, timeout, reset): their outcome is unknown, so the key must be kept. Error bodies of byte (PDF) requests are decoded as JSON. `describeApiError()` is the one place that turns codes into user text (`duplicate_reference` names the existing receipt and amount; allocation/credit errors show the amounts; network errors say retrying is safe).
- **Money:** `Money.parseGhsToPesewas` previously stripped *every* comma, so a mistyped `1,00` became GHS 100.00. It now matches the server/admin rule: plain digits or correctly grouped thousands, at most 2 decimals, cap `Money.maxPesewas` (GHS 1bn), integer arithmetic only.
- **Idempotency key per intent, persisted:** `PendingSubmissionStore` (secure storage) keeps `{key, payload hash, payload, started_at}` per intent (`record_payment.customer.{id}`, `apply_credit.customer.{id}`). The key is written *before* the request; the same payload (canonical JSON, keys sorted, 64-bit FNV-1a) reuses it; a changed payload gets a new key; it is deleted only after a 2xx. Business errors keep it too (resending the same payload is always safe; the server releases non-2xx claims). After an app restart the record-payment screen shows an "Unfinished payment" banner with the amount, method and reference, and "Restore" refills the exact payload (including `paid_at`), so tapping Record replays instead of duplicating. "Discard" forgets it after the user has checked.
- **Record payment screen:** customer balances, exact GHS amount, method, reference required for non-cash (`Validators.paymentReference`), date picker capped at today, notes, "oldest invoices first" / "keep as credit", and the customer's 5 most recent payments under "check before recording again". Success dialog shows applied vs credit, with Share receipt; then the payment detail opens.
- **Other screens:** customers list (debounced search, balances), detail (balances, recent invoices and payments, Record payment, Apply credit oldest-first with its own persisted key), create/edit form (16 regions, GHS credit limit, blank = none); payments list (All/Valid/Void), detail (allocation ledger, Void with required reason, Share receipt via `share_plus` from a temp file).
- **Structure:** follows the project's existing `features/*/{data,domain,presentation}` layout rather than the generic Flutter skill layout; API calls live in repositories and small `ChangeNotifier` controllers, not widgets; validators in `core/validators.dart`; the receipt sharer is an interface so tests fake it.
- `flutter analyze` with the current lints flagged `prefer_initializing_formals` on older files too (Dart 3.13 supports private named initializing formals: `required this._repo` is passed as `repo:`); fixed everywhere so analyze is clean.

## 2026-10-03 — Phase 2D.3: Flutter sales, review fixes, acceptance

**Review fixes:**
- Reference wording (Filament helper text and Flutter label/helper): "MoMo transaction ID, bank reference, or bank + cheque number (for example GCB 000123)". A cheque number is only unique per bank; matching already ignores spaces.
- Pending idempotency keys are kept only when the outcome is unknown (`ApiException.isOutcomeUnknown`: no response, 5xx, `request_in_progress`). Any other error is definitive, so the key is forgotten and no "unfinished" banner appears. Applies to record payment, apply credit, create draft and confirm.
- `paid_at` tolerates device clocks up to 10 minutes fast (`RecordPayment::CLOCK_SKEW_MINUTES`, also in `StorePaymentRequest`); such a value is stored as the server's current time, so no payment is ever dated in the future.

**Idempotency for drafts and confirm:**
- `POST /sales` now goes through the `idempotent` middleware (a retried "save draft" cannot create two drafts). Existing API tests send a key.
- Flutter persists one key per intent: `create_draft_sale` (payload = request body) and `confirm_sale.{id}` (payload = body + sale id + draft total + draft `updated_at`). Re-pricing a draft (`PUT {}`) changes `updated_at`, and ticking "override credit limit" changes the body, so each gets a new key; a plain retry reuses it.

**Flutter sales:** new sale (customer picker with balances; book search with level/subject/language chips; scanning via the scanner's new pick mode; +/- and typed quantities; totals only from `/pricing/preview`, debounced, latest request wins; Save draft / Save & confirm), sales list (status and payment-status chips; `GET /sales` gained a `payment_status` filter), sale detail (items, payments applied, confirm sheet with due date and apply-credit toggle, price-changed diff dialog with accept, insufficient-stock list, credit warning with "Confirm anyway", cancel, void with reason, mark delivered, share invoice, record payment). A client-side "would exceed the credit limit" hint was written and then removed: it computed money on the phone; the server's confirm warning covers it.

**Acceptance:** `docs/acceptance-phase2.md`. The Filament run passed on SQLite and on MySQL. The emulator run was prepared (`AcceptanceSeeder`, `integration_test/phase2_acceptance_test.dart`, `API_BASE_URL` dart-define) but not completed: the only emulator had the owner's own `flutter run` session attached, and the test would have replaced the app under it. `phase-2-complete` is therefore not tagged yet.

## 2026-10-03 — Phase 2 close-out

- `AcceptanceSeeder` throws outside local/testing (it is only run on purpose, so it refuses loudly rather than skipping).
- Flutter sale detail: a 409 state conflict from cancel/void/deliver (these are not key-protected) refetches the sale and shows what happened ("This invoice was already voided.") instead of an error.
- **New-sale screen bottom bar:** the phone acceptance run showed "Save & confirm" below the fold once an order is longer than the screen. The total (server-priced), Save draft and Save & confirm are now pinned in a bottom bar; the list scrolls above it.
- Integration test: detail-screen buttons are reached by closing the keyboard, `scrollUntilVisible`, then tapping at once (a real keyboard shrinks the list and lazily built items can be dropped).
- The emulator used for development had frozen (adb listed it, every shell command hung), which explained the earlier silent runs. Cold-booted with the owner's permission.
- Phone acceptance passed on retry 1 of 2 with every specified figure unchanged; `customers:reconcile` clean. Phase 2 tagged `phase-2-complete`.

## 2026-10-03 — Phase 3.0: backups (safety net)

- **spatie/laravel-backup 10.3**, database only (`backup:run --only-db` at 01:30, `backup:clean` 01:50, `backup:monitor` 02:00). Code is in git; there are no uploaded files yet.
- **Disks from `.env`:** `BACKUP_DISKS` (comma-separated), default `backups` = local `storage/app/backups`. An `offsite` S3-compatible disk is defined (`BACKUP_OFFSITE_*`) but needs `league/flysystem-aws-s3-v3`. It is not installed until the provider is chosen, so dev and CI do not carry AWS SDK dependencies for a disk they never use.
- **Backup folder name `BACKUP_NAME=schoolbook`**, not `APP_NAME`: the folder must stay stable if the display name changes, or monitoring and cleanup lose track of older backups.
- **No gzip step:** spatie's `GzipCompressor` shells out to a `gzip` binary, which Windows lacks (the dump failed with "'gzip' is not recognized"). The zip itself is deflate-compressed (the test asserts `CM_DEFLATE` on the dump entry), so the size result is the same on both platforms.
- **Alerts:** package notifications are switched off (no mail yet). `App\Listeners\LogBackupProblems` logs `BackupHasFailed`, `CleanupHasFailed` and `UnhealthyBackupWasFound` as `Log::critical`. The scheduled commands also have `onFailure` → `Log::critical`, for a command that dies before it can raise an event. Switching to email later is a config change (`docs/operations.md`).
- **`mysqldump` path:** `DB_DUMP_BINARY_PATH` on each MySQL connection's `dump` config, with `--single-transaction` (InnoDB, no table locks during opening hours). In `.env` on Windows use forward slashes: backslashes inside double quotes are escapes to phpdotenv.
- **Test gotcha:** the package resolves its `Config` object when Artisan boots (in the MySQL group, during `migrate:fresh`), so `config(['backup…'])` in a test is silently ignored and it dumped the default connection (`:memory:` under phpunit). The test now uses the real backup config unchanged and points the `mysql` connection at `schoolbook_test`. The failure test asserts that the logged error comes from the dump step, so it cannot pass for an unrelated reason.
- **Restore drill** run and logged in `docs/operations.md`: seeded data, backup, wipe, restore, identical `CHECKSUM TABLE` on 10 tables, `customers:reconcile` clean. It used the throwaway test database because the app's database user cannot create databases. The production drill uses an admin-created scratch database.

## 2026-10-03 — Phase 3.0 follow-up (review)

- `monitor_backups`: the package's "name of the second app" sample (disks `local`, `s3`) was inside a `/* … */` block comment, so the effective config already had one entry (checked with `config('backup.monitor_backups')`). Removed anyway so nobody uncomments it. A test pins exactly one monitored backup; `backup:monitor` fails with no backup (Feature) and passes after a real backup (MySQL group).
- `verify_backup` on; `tries` 3 with `retry_delay` 60. The package sleeps through `Sleep::for`, so the test fakes it and asserts two 60-second waits, then a single critical log.
- Heartbeat: `pingOnSuccessIf` on `backup:run`, URL from `BACKUP_HEARTBEAT_URL` (`services.heartbeat.backup_url`). Tested with a mocked Guzzle client: a ping on success, none on failure, none without a URL.
- `App\Support\BackupGuard` in `AppServiceProvider::boot`: in production, `offsite` in `BACKUP_DISKS` with an empty `BACKUP_ARCHIVE_PASSWORD` throws. This also stops `config:cache` and the scheduler on that server, which is intended: no unencrypted off-site uploads.
- Off-site provider: **Cloudflare R2** (owner's choice). Storage caps for cleanup and the monitor were cut from 5000 to 2000 MB to stay inside the free allowance. The S3 package is not installed and no bucket is configured yet; that is the next ops step, before real data.

## 2026-10-03 — Phase 3.A.1: approved list (reference catalog) import

Phase 3.A (approved catalog) was inserted before 3.1 by the owner. Rules and mappings are in `docs/reference-catalog.md`.

- **Parser on positioned text, not plain text:** `smalot/pdfparser` (LGPL-3.0, used as a library) gives every text run's x/y. In the plain text, about a quarter of the rows had no separator between title, level and publisher. Column edges come from the data (most common start x between centred header labels, per page and table). The real list parses to exactly NaCCA's own statistics, except the two sections where NaCCA numbered and counted a blank row.
- **Fixtures:** synthetic rows (14) laid out with the real geometry (`tests/Support/ReferenceListFixture.php`), plus the same fixture rendered to a real PDF with DomPDF for an end-to-end `reference:import` test. The NaCCA PDF and anything derived from it stay in `storage/app/reference/` (git-ignored).
- **Schema additions beyond the prompt:** `author` (supplementary rows print "Authors /Publisher"; the publisher is split off for merging and search); `confidence` high/low (the prompt's "mark low confidence" for keyword guesses; a flag, not an issue, so 800+ guessed rows do not drown the real review items); `excluded` and `printed_publisher` on staging rows; `stated_counts` and `discarded` status on editions; `level_label` nullable (readers, guidance and e-learning have no level).
- **Review semantics:** nothing is auto-accepted. Bulk "accept all clean new/changed/unchanged/removed" is the owner's explicit decision; rows with errors (`missing_publisher`, `unknown_level`, `duplicate`) cannot be accepted until fixed or excluded. Publish applies only accepted rows; undecided rows are left out (not lost: they stay visible in that edition's history until the next import).
- **Removals need a decision too:** a parse failure could look like a removal, so withdrawals are not automatic. "Keep on list" (exclude) leaves the title approved.
- **Natural key with publisher id:** once a publisher exists, keys use its id, so renaming a publisher or merging spellings does not turn titles into remove+new pairs on the next import.
- **Edition list:** Publish activates a draft and supersedes the previous edition; there is no manual re-activation of an old edition (the live titles reflect the last publish, and re-activating would not undo it). Import is CLI-only; the admin shows the command.
- **New subject "Physical Education and Health"** is created on publish (the seeded subjects lack it); staging never writes live tables.
- **Ops incident (dev database):** during the smoke test a `migrate:fresh --seed` ran without the test-database override and wiped the local dev database `schoolbook` (no business data in it; owner row, app tokens and settings were re-seeded). The restore from the 07:47 backup was blocked by the agent's permission check and is left to the owner. Destructive commands now name the throwaway database explicitly; the smoke script also refuses to run unless the connection points at `schoolbook_test`.

## 2026-10-05 — Phase 3.A.2: approved list API and shop workflows

- **Destructive-database guard** (`App\Console\DestructiveDatabaseGuard`, listener on `CommandStarting`): `migrate:fresh`, `migrate:refresh`, `migrate:reset` and `db:wipe` are refused unless the environment is `testing`, the target database name ends in `_test`, or `ALLOW_DESTRUCTIVE_DB=1` is set for that command. This replaces the agent-notes rule after the 2026-10-03 dev wipe with something enforced. Laravel only routes Symfony's command events outside unit tests; the test turns that routing on so a real Artisan run is shown to stop. Guard tests use SQLite probe files named like the real databases, never MySQL.
- **`POST /products` through `CreateProduct`** (the controller wrote the model directly): generated SKU, optional opening stock via `ReceiveStock`, approved-title prefill in the Form Request (`PrefillFromReferenceBook`, before validation, explicit values win). The admin create page and the CSV importer use the same pieces. The CSV importer's random `IMP-xxxxxxxx` SKUs became the `BK-000001` sequence.
- **Idempotency on `POST /products` is optional** (`idempotent:optional`): the Phase 1 Flutter product form sends no key, so requiring one would break it before 3.A.3. With a key, retries return the first response.
- **Variant in the title:** a prefilled title gets the variant in brackets so two products of one title are distinguishable in sales and invoices.
- **attach-code:** ISBN-13 (valid check digit) → `isbn`, else `barcode`; numeric codes lose spaces and hyphens, others (SKUs) are kept as typed. Codes held by soft-deleted products are refused too (they still hold the unique index). An occupied slot is never overwritten (`code_slot_taken`); the owner changes it on the product deliberately.
- **Snapshot ETag = hash of the exact body**, not a fingerprint of row counts and timestamps: the first version counted every publisher, so creating an unrelated product (which created a publisher) forced a re-download. Stock is not in the snapshot; the phone takes stock from the products API.
- **Coverage:** "in my products" counts a title once it has any linked, non-deleted product, whatever its stock; "not on the list" includes products whose title was withdrawn.
- **Key collision on publish** (review item): detected after publisher resolution inside the publish transaction, rolled back, then recorded on the rows in a separate transaction so the review page shows them as "Fix". Not reachable through the review screens (every fix re-matches the key), so the test builds the stale state directly.
- **Indexes:** `reference_books.isbn` (plain), FULLTEXT `(search_title, publisher_label, author)` on MySQL/MariaDB only.

## 2026-10-05 — Phase 3.A.3: phone (offline list, quick-create, scan-attach) and review follow-ups

- **Search operators:** words are normalized before the FULLTEXT query, so `+ - * " ( ) @ ~ < >` typed by a user are dropped; a MySQL-group test sends `+maths -sunrise* (basic) "2"` and a query of operators only (returns the unfiltered list, never an SQL error).
- **One goods receipt per bulk operation:** already the case. `ImportProductsFromCsv` collects the opening stock of every row into a single `ReceiveStock` call (also for the "Create products" CSV template, which goes through the same import). A test imports 25 rows and finds 1 goods receipt with 25 items. Only the one-at-a-time "Add to my products" and the API quick-create make their own receipt, by design.
- **Guard hardening:** `DestructiveDatabaseGuard` refuses the four commands whenever `.env` contains an `ALLOW_DESTRUCTIVE_DB=` line (any value, including `0` or `export ...`), even against a `_test` database, with an instruction to remove the line. The override is honoured only from the shell environment. A test also checks the project's real `.env` does not contain it.
- **Scan resolution order:** products first (`by-code`), the approved list's ISBN only as a hint for a new product (the user picks the variant). No new endpoint: the phone checks its offline copy for the ISBN.
- **Snapshot ETag cost:** left as is (the payload is built per check; about 1,600 rows). If it ever matters: cache keyed by active edition id plus the latest `updated_at` of the books and name tables.
- **Hand-check finding:** a non-textbook title in a Ghanaian language without a keyword defaulted to English with high confidence. Defaulted English on non-textbook rows is now low confidence. The staged dev edition (#1) was not re-imported, so the owner's review is not disturbed; that row is fixed by hand in review.
- **Phone structure:** `features/reference/{data,domain,presentation}`; the catalog (`ReferenceCatalog`, ChangeNotifier) is created in `app.dart` and synced whenever a session starts. The offline copy is a plain JSON file in the app-support directory (public data; secure storage is for keys). The search runs on the phone; no money or stock is computed there (stock comes from the server, shown "as of" the last sync; the local count after a quick-create is a display hint until the next sync).
- **Acceptance** (`docs/acceptance-phase3a.md`): steps 1 to 3 (real import, counts against NaCCA's statistics, 25-row hand check) done; the owner's review and the phone run wait for the owner. Rows are cited by page and serial, not title, because the file is committed.

## 2026-10-05 — Approved list: finishing rows left out of a publish

- The owner's first publish accepted the 1,487 clean rows and left the 79 warning rows undecided (the bulk accept skips rows with issues, and the confirmation's "79 undecided ... left out" was easy to miss). A published edition is read-only, so there was no way left to decide them: a gap in the 3.A.1 design.
- Fix: `reference:import --again` stages the live file again on purpose (the same-file refusal otherwise stays, and its message now names the option). Live titles come back unchanged, left-out rows as new. The publish confirmation now warns, in capitals, when rows marked Fix or Check are still undecided.
- Not chosen: reopening a published edition for more decisions. It would mix decisions across two publishes of one edition and complicate the diff; a second, ordinary draft keeps every publish a full, reviewable diff.

## 2026-10-05 — Phase 3.1: reports core

- **Hand figures first:** `docs/acceptance-phase3.md` (dataset and every expected figure, with the arithmetic) was written before the report code; `ReportsFixtureTest` checks the dataset against it, `ReportFiguresTest` (SQLite) and `tests/Mysql/ReportFiguresTest` assert its literals. Mutation checks: counting void sales, valuing negative stock, ignoring the dead-stock cut-off and moving an aging boundary by a day each fail the tests (the boundary one only after boundary as-of dates were added: the first dataset had no invoice on a boundary).
- **Order-level discount in the fixture:** today's `PricingService` never produces one (Phase 4). The fixture binds `Tests\Support\Phase4StandInPricing` (5% off an order of 15 books or more), so the real `CreateDraftSale`/`ConfirmSale` store a real `discount_total`; nothing bypasses the actions.
- **Catalog filters and order discounts:** split by product, level, subject or language, figures are line revenue; order discounts are a separate line (profit) or null (sales summary with a catalog filter), never prorated.
- **SQL portability:** periods are queried half-open (`>= from`, `< to + 1 day`) so DATE columns and datetimes compare the same on MySQL and SQLite and indexes stay usable; days are grouped in SQL (`DATE()`), weeks and months rolled up from days in PHP; aging buckets are `CASE` on `due_date` against computed boundary dates.
- **Aging uses today's balances;** the as-of date only moves invoices between buckets. Historical balances would need the allocation ledger replayed to that date; not asked for.
- **Dead stock** counts a voided sale's `sale_out` as a sale (the movement happened); the definition is "no sale_out", taken literally.
- **Indexes:** `sales (status, sale_date)` already existed; the migration adds `sale_items (product_id)`, `stock_movements (type, occurred_at)`, `payments (paid_at)`. Not applied to the owner's dev database (new rule: tests and fixtures never touch it); `php artisan migrate` applies it.
- **Spec:** section 14 gained the definitions; section 6.3 the stock-count fields and rules for 3.3.

## 2026-10-06 — Phase 3.2: statements, stock:reconcile, report definition changes

- **Hand figures first:** the statement figures (`docs/acceptance-phase3.md` 3.9: June, January–June, 06-21..06-30, timezone and midnight boundaries, same-timestamp ordering), the dead-stock voided-sale case and the low-stock status were written before the code; `StatementTest`, `ReportFiguresTest` and `tests/Mysql/StatementReconcileTest` assert them. Reviewer's closing balances (Alpha 12,000, Beta 37,000, Gamma −2,000) matched mine.
- **Dead stock ignores voided sales** (reviewer, reversing my 3.1 literal reading): the last-sale subquery joins `sale_out` movements to sales still `confirmed`.
- **Low-stock status:** `out_of_stock` when stock ≤ 0, else `low`; the 0/0 product stays listed (counts and order unchanged).
- **Statement events** come from `sales` (confirmed or void, with `confirmed_at`) and `payments` (valid or void) in one `UNION ALL`; money columns are unsigned on MySQL, so amounts are `CAST(... AS SIGNED)` before negating. Credit applications are not events: they move money between a payment and an invoice and change neither side, so closing = `outstanding_balance − credit_balance` holds.
- **Same timestamp:** invoices, payments, invoice voids, payment voids, then id. Invoice voids before payment voids is my choice (the instruction says only "then voids").
- **Datetime bounds:** the statement compares against `Y-m-d 00:00:00`, not a bare date: on SQLite (text comparison) a bare date sorts before midnight of that day, so `<` and `<=` could not be told apart and a mutation of the opening-balance boundary survived. With full datetimes an event at exactly 00:00 is tested and belongs to its day on both databases. The reports' half-open day ranges were already correct on both.
- **`from`/`to` required** for statements (no default period); no maximum range.
- **stock:reconcile:** `LAG(balance_after, 1, 0) OVER (PARTITION BY product_id ORDER BY id)` for the chain, a grouped sum for stock; includes soft-deleted products; `--product=*` filter; `--fix` repairs `stock_on_hand` only, logs each change as a warning, and still exits non-zero when a broken chain remains. Scheduled 02:45 (after `customers:reconcile` at 02:30) with `onFailure` → `Log::critical` carrying counts and product ids.

