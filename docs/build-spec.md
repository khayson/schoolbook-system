# Schoolbook Supply System: Build Specification v1.0

Prepared by Claude (architect/reviewer) for Khay Studios. Implementation is done by Claude Code, Cursor or Antigravity. This document is the single source of truth. If code and this spec disagree, stop and flag it rather than silently deviating.

---

## 0. How the implementing agent must work

1. Work **one phase at a time** (section 17). Do not start a phase until the previous one passes its acceptance criteria.
2. Work **one task at a time** inside a phase. After each task: run tests, run the linter, summarize what changed and what is next.
3. **Never invent requirements.** If something is ambiguous, list the question and propose a default, then continue with the default.
4. **All business logic lives in Action classes** (section 5). Controllers, Filament pages and Flutter never calculate money or stock.
5. Every migration, model, action, endpoint and screen listed in the current phase must exist, be tested, and match the names in this spec.
6. Dev environment: Windows, PowerShell, PHP 8.4, Flutter 3.47.x stable, MySQL/MariaDB. Give commands in PowerShell syntax.

---

## 1. Product summary

A system for a Ghana-only schoolbook wholesaler that replaces paper records. It sells in bulk to schools (Creche, KG, Primary, JHS) across all subjects and languages. It is **POS-like in speed of entry, but not a retail POS**: the customer is a school, orders are bulk, payment is often by instalments or on credit, and the owner must always know **what is in stock, what was sold, who owes what, and what profit was made**.

**Users now:** the owner only. **Later:** schools get their own login to request orders (seams are built now, feature built in Phase 6).

**Clients:**
- **Flutter Android app**: owner on the move. Quick order entry, scanning, stock lookup, record a payment, check a school's balance, share invoices and receipts.
- **Filament web admin**: owner at a desk. Catalog management, bulk entry, stock-take, reports, pricing rules, settings.

Both clients are thin. They call the same domain Actions (Filament directly, Flutter through the REST API).

---

## 2. Locked decisions

| Topic | Decision |
|---|---|
| Backend | Laravel 13, PHP 8.4, MySQL/MariaDB |
| Web admin | Filament (v5 / Livewire v4; verify compatibility with Laravel 13 on install) inside the Laravel app |
| Mobile | Flutter, Android first; iOS later |
| Repo | Monorepo: `laravel/` and `flutter/` folders, each self-contained |
| Auth | Laravel Sanctum token auth for mobile; session auth for Filament; roles via a `role` column and policies |
| Money | Integer **pesewas** (1 GHS = 100). Never floats |
| Currency | GHS only |
| Stock | Immutable ledger (`stock_movements`) with a cached `products.stock_on_hand` |
| Credit | Instalments, credit and full payments are all first class; payments belong to the customer and are allocated to invoices |
| Pricing | One `selling_price` per product as the base, plus a **pricing rules engine** (Phase 4). The `PricingService` seam exists from Phase 2 |
| Tax | Not now. Schema is tax-ready (section 15). Prices are stored **tax-exclusive** |
| Branches | One branch. No `branch_id` anywhere |
| Deletes | Soft deletes for master data; financial and stock records are never deleted, only voided/reversed |
| PDFs | `barryvdh/laravel-dompdf` for invoices, receipts and statements |

**Assumptions (flag if wrong):** each product belongs to a single level (class); negative stock is blocked by default (setting `allow_negative_stock`, default false); one currency; Ghana's 16 regions are used for customer region.

---

## 3. Monorepo structure

```
schoolbook-system/
├─ CLAUDE.md                  # agent instructions (see Appendix A)
├─ README.md
├─ .gitignore
├─ .editorconfig
├─ docs/
│  ├─ build-spec.md           # this file
│  ├─ api.md                  # generated/maintained API reference
│  └─ decisions.md            # ADR-style log of any deviation
├─ laravel/                   # Laravel 13 app (API + Filament admin)
│  ├─ app/
│  │  ├─ Actions/             # ALL business logic, grouped by module
│  │  │  ├─ Catalog/  Inventory/  Customers/  Sales/  Payments/  Pricing/  Reports/
│  │  ├─ DTOs/
│  │  ├─ Enums/
│  │  ├─ Filament/            # Resources, Pages, Widgets (call Actions only)
│  │  ├─ Http/
│  │  │  ├─ Controllers/Api/V1/
│  │  │  ├─ Requests/         # Form Requests
│  │  │  └─ Resources/        # API Resources
│  │  ├─ Models/
│  │  ├─ Policies/
│  │  ├─ Services/            # PricingService, NumberSequence, Money, PdfService
│  │  └─ Support/
│  ├─ database/{migrations,factories,seeders}/
│  ├─ routes/api.php
│  └─ tests/{Feature,Unit}/
└─ flutter/                   # Flutter app (Android first)
   ├─ lib/
   │  ├─ core/                # api client, auth, theme, money, errors, router
   │  ├─ features/
   │  │  ├─ auth/ dashboard/ products/ stock/ customers/ sales/ payments/ reports/ settings/
   │  │  │   └─ (each: data/ domain/ presentation/)
   │  └─ shared/              # widgets, formatters
   ├─ test/
   └─ pubspec.yaml
```

Each of `laravel/` and `flutter/` has its own `.env`/config, dependency files, tests and README section. Nothing is shared between them except the API contract in `docs/api.md`.

---

## 4. Tech stack

**Laravel:** Sanctum, Filament, `barryvdh/laravel-dompdf`, `spatie/laravel-activitylog`, Pest (feature + unit tests), Laravel Pint (style), Scribe or `dedoc/scramble` (API docs), `maatwebsite/excel` (Phase 5 exports). Queue driver: `database`. Scheduler: nightly DB backup.

**Flutter:** `provider` (state management), `go_router`, `dio` (with interceptors for auth and error mapping), `flutter_secure_storage`, `mobile_scanner` (barcode/ISBN), `intl`, `share_plus`, `path_provider`, `open_filex`. Material 3, light/dark theme. No local database in v1 (offline queue is Phase 6).

---

## 5. Engineering rules (non-negotiable)

1. **Action classes.** One class per use case (`ConfirmSale`, `RecordPayment`, `ReceiveStock`). Each has a single public `execute()` method, takes a DTO or typed args, and returns a model or DTO. Both API controllers and Filament call the same Action.
2. **Transactions.** Every Action that writes more than one row runs inside `DB::transaction()`.
3. **Locking.** Stock and balance changes use `lockForUpdate()` on the affected product/customer rows.
4. **Money.** Stored and transmitted as integers in pesewas. A `Money` helper formats for display only. Percent calculations round **half up to the nearest pesewa per unit**, then multiply by quantity.
5. **Immutability.** `stock_movements`, `payment_allocations` and issued invoice numbers are never updated or deleted. Corrections are new rows (reversals).
6. **Snapshots.** `sale_items` store product title, base price, unit price, unit cost and applied rules at the time of sale. Later changes never rewrite history.
7. **Idempotency.** Mutating endpoints that create financial or stock side-effects require an `Idempotency-Key` header. Stored in `idempotency_keys` (unique on `key` + `user_id`). Same key + same payload replays the original response; same key + different payload returns `422`. Applied to stock receipts now; confirm-sale and payments reuse the same middleware.
8. **Number sequences.** Invoice, receipt, goods-receipt and customer codes come from a locked `number_sequences` row, never from `max(id)`.
9. **Validation** only in Form Requests; **authorization** only in Policies; **formatting** only in API Resources.
10. **Auditing.** Financial and stock models log changes via activity log with the acting user.
11. **No N+1.** Eager load; list endpoints paginate (default 25, max 100).
12. **Tests are part of the task.** Money and stock logic need unit + feature tests before the task is done.
13. **Morph map.** `Relation::enforceMorphMap()` in `AppServiceProvider` — morph columns store short aliases only, never FQCNs. Document aliases: `user`, `product`, `customer`, `goods_receipt`, `sale`, `payment`, `stock_count`, `sale_return`, `purchase_order`. Add new aliases before writing new reference types.
14. **Lock order (sales).** When confirming a sale, acquire locks in this order: **products (sorted by id) → customer → invoice number sequence (last, held briefly)**.
15. **Invoice year.** The invoice number's year comes from the **confirmation date**, not the sale date.
16. **Migrations are append-only.** Every schema change is a **new migration**. Never edit a migration that has already been shipped/run outside a fresh local DB.

---

## 6. Database schema

Conventions: `id` bigint PK, `created_at`/`updated_at` on all tables, `deleted_at` where marked (SD). Money columns are `unsignedBigInteger` (pesewas) unless noted. Add foreign keys and indexes as listed.

### 6.1 Identity and settings
- **users**: name, email (unique), password, `role` enum(`owner`,`school`), `customer_id` nullable FK (for later school logins), is_active.
- **settings**: `key` (unique), `value` json. Keys: `business_name`, `business_address`, `business_phone`, `invoice_footer`, `allow_negative_stock` (false), `tax_enabled` (false), `default_payment_terms_days` (30).
- **number_sequences**: `key`, `year` unsignedSmallInteger non-null default `0` (`0` = no year), `last_number`; unique(`key`,`year`).

### 6.2 Catalog
- **level_groups**: name, slug, sort_order. Seed: Creche, KG, Primary, JHS.
- **levels** (SD): `level_group_id`, name, slug, sort_order. Seed: Creche; KG 1, KG 2; Primary 1 to 6; JHS 1 to 3.
- **subjects** (SD): name, slug, is_active. Seed common set (Mathematics, English Language, Science, Our World Our People, Creative Arts, RME, Computing/ICT, Ghanaian Language, French, History, Social Studies, Integrated Science, Career Technology, etc.); owner edits freely.
- **languages** (SD): name, code, is_active. Seed: English, Twi (Akuapem), Twi (Asante), Fante, Ga, Ewe, Dagbani, Dagaare, Gonja, Nzema, Kasem, Ga-Dangme, French.
- **publishers** (SD): name, contact_person, phone, email, notes.
- **products** (SD): `sku` unique, `isbn` nullable unique, `barcode` nullable unique, title, `level_id`, `subject_id`, `language_id`, `publisher_id` nullable, edition nullable, `cost_price`, `selling_price`, `reorder_level` default 0, `stock_on_hand` int default 0 (cached), is_active. Index (`level_id`,`subject_id`,`language_id`) and fulltext/like index on title.

### 6.3 Inventory
- **stock_movements** (immutable): `product_id`, `type` enum(`receipt_in`,`sale_out`,`sale_void_in`,`return_in`,`return_out`,`adjustment`,`damage`,`count_adjustment`), `quantity` signed int, `balance_after` int, `unit_cost` nullable, `reference_type`/`reference_id` (morph; **short aliases only** via `Relation::enforceMorphMap` — see §5.13), note nullable, `user_id`, `occurred_at`. Indexes: (`product_id`,`occurred_at`), (`product_id`,`id`) — running balances and reconcile sort by `id`.
- **idempotency_keys**: `key`, `user_id`, `route`, `request_hash`, `response_status`, `response_body`; unique(`key`,`user_id`).
- **suppliers** (SD): name, contact_person, phone, email, notes.
- **goods_receipts**: `receipt_no` (GRN-YYYY-000001), `supplier_id` nullable, `supplier_reference` nullable, `received_at`, notes, `created_by`. 
- **goods_receipt_items**: `goods_receipt_id`, `product_id`, quantity, `unit_cost`. Receiving updates `products.cost_price` to the latest unit cost (setting-driven later: latest vs weighted average; start with latest). Duplicate input lines merge only when `product_id` and `unit_cost` are identical; different costs stay as separate item rows (and separate movements) so cost history is preserved.
- **stock_counts**: `reference`, `status` enum(`open`,`applied`,`cancelled`), `counted_by`, `applied_at`.
- **stock_count_items**: `stock_count_id`, `product_id`, `system_qty`, `counted_qty`, `variance`. Applying creates `count_adjustment` movements.

### 6.4 Customers
- **customers** (SD): `code` (CUS-0001), name, `type` enum(`school`,`reseller`,`individual`), `region` (Ghana region), district, address, contact_person, phone, email, `credit_limit` nullable, `credit_balance` default 0 (unallocated advance money, cached), notes, is_active.

### 6.5 Sales
- **sales**: `invoice_no` unique nullable until confirmed (INV-YYYY-000001), `customer_id`, `status` enum(`draft`,`requested`,`confirmed`,`cancelled`,`void`), `payment_status` enum(`unpaid`,`partial`,`paid`), `source` enum(`staff`,`portal`) default staff, `sale_date`, `due_date` nullable, `subtotal`, `discount_total`, `tax_total` default 0, `total`, `amount_paid`, `balance_due`, `delivered_at` nullable, notes, `created_by`, `confirmed_by`/`confirmed_at`, `cancelled_at`, `cancel_reason`, `idempotency_key` nullable unique.
- **sale_items**: `sale_id`, `product_id`, `product_title` (snapshot), `quantity`, `base_price`, `unit_price`, `discount_amount` (line total discount), `tax_amount` default 0, `line_total`, `unit_cost` (snapshot), `applied_rules` json nullable, `is_price_overridden` bool, `override_reason` nullable.

### 6.6 Payments
- **payments**: `receipt_no` unique (RCT-YYYY-000001), `customer_id`, `amount`, `method` enum(`cash`,`momo`,`bank_transfer`,`cheque`), `reference` nullable, `paid_at`, `unallocated_amount` default 0, `status` enum(`valid`,`void`), `void_reason` nullable, notes, `received_by`, `idempotency_key` unique nullable.
- **payment_allocations** (immutable): `payment_id`, `sale_id`, `amount`. A void creates reversal handling (see 9.3), not deletion.

### 6.7 Pricing rules
- **price_rules**: see section 7.

### 6.8 Later phases
- **sale_returns**, **sale_return_items** (Phase 5), **purchase_orders**, **purchase_order_items** (Phase 5), **tax_rates** (Phase 5).

---

## 7. Pricing rules engine

### 7.1 Seam from Phase 2
Create `App\Services\PricingService` with:

```php
priceLines(?Customer $customer, array $lines, CarbonInterface $date): PricedOrder
```
Phase 2 implementation returns the product `selling_price` with no rules. **All sale code (API, Filament, Flutter preview) must go through this service from day one.** Phase 4 fills in the rules without touching callers.

### 7.2 Table: `price_rules`
| Column | Notes |
|---|---|
| name | human label |
| `type` | `percent_off`, `amount_off` (per unit), `fixed_price` (per unit), `order_percent_off`, `order_amount_off`, `bonus_units` (stretch) |
| `value` | integer (basis points for percent: 1000 = 10.00%; pesewas for amounts) |
| `scope_type` | `all`, `product`, `publisher`, `subject`, `language`, `level`, `level_group` |
| `scope_id` | nullable FK-like id for the scope |
| `customer_scope` | `all`, `customer`, `customer_type`, `region` |
| `customer_scope_value` | customer id, type, or region (nullable) |
| `min_quantity` / `max_quantity` | per-line quantity window, nullable |
| `min_order_total` | for order-level rules, nullable |
| `starts_at` / `ends_at` | nullable validity window |
| `priority` | integer, higher wins |
| `stackable` | bool |
| `floor_policy` | `block_below_cost`, `warn`, `allow` (default `warn`) |
| `is_active` | bool |

### 7.3 Resolution algorithm (line rules)
For each line (product, quantity):
1. `price = product.selling_price`; `base = price`.
2. Candidates: active rules whose date window contains the sale date, whose scope matches the product, whose customer scope matches the customer, and whose quantity window contains the line quantity.
3. Sort by `priority` desc, then **specificity** desc (product > publisher > subject = language > level > level_group > all, and customer > customer_type > region > all), then `id` asc.
4. Apply the first rule. If it is **non-stackable**, stop. If **stackable**, continue applying the next rules **only if they are also stackable**, until a non-stackable rule is reached (that one is not applied) or candidates run out.
5. Effects: `fixed_price` sets the unit price; `percent_off` reduces the current unit price; `amount_off` subtracts from the current unit price; never below 0. Round per unit half-up.
6. Floor check against `unit_cost` using the strictest `floor_policy` among applied rules: `block_below_cost` raises a validation error, `warn` adds a warning to the result, `allow` does nothing.
7. Output per line: `base_price`, `unit_price`, `discount_per_unit`, `line_total`, `applied_rules` (id, name, effect), `warnings`.

### 7.4 Order-level rules
After line pricing, apply `order_percent_off` / `order_amount_off` rules whose `min_order_total` is met by the post-line subtotal, using the same priority and stacking logic. The result is stored in `sales.discount_total` (not spread over lines). Profit reports subtract it.

### 7.5 Manual override
The owner can override a line `unit_price` with a required reason. Flag `is_price_overridden`, store the reason, and log it in the activity log.

### 7.6 Behavior rules
- `POST /pricing/preview` returns the full priced order for the client to display before saving.
- Drafts store the prices at draft time. On `confirm`, pricing re-runs. If any total differs from the draft, respond **409** with the new priced order; the client shows the change and re-confirms with the new totals.
- Confirmed sales are never repriced.
- Provide a Filament "Test rule" page (pick customer + products + quantity, see the result and the rules applied).

---

## 8. Inventory behavior

- `ReceiveStock`: creates a goods receipt, a `receipt_in` movement per item, updates `stock_on_hand` and `cost_price`.
- `AdjustStock`: manual adjustment or damage, with a mandatory note.
- `ApplyStockCount`: for each item with a variance, creates a `count_adjustment` movement; marks the count `applied`.
- Every movement writes `balance_after`. A nightly check command `stock:reconcile` verifies `stock_on_hand` equals the sum of movements and reports mismatches.
- **Low stock:** `stock_on_hand <= reorder_level`.
- **Confirming a sale** checks availability per item. If insufficient and `allow_negative_stock` is false, the whole confirm fails with a clear per-item error.

---

## 9. Sales and payments behavior

### 9.1 Sale state machine
```
draft ──confirm──> confirmed ──void──> void
  │                    │
  └──cancel──> cancelled   (delivered_at is set separately, not a status)
requested (portal, later) ──approve──> confirmed
```
- **draft**: editable, no invoice number, no stock effect.
- **confirm** (`ConfirmSale`): reprice (7.6), check stock, assign `invoice_no`, create `sale_out` movements, set `balance_due = total`, optionally apply customer credit (`apply_credit: true`), set `due_date` from payment terms if absent. All in one transaction with an idempotency key.
- **cancel**: drafts only. **void**: confirmed sales only, with a mandatory reason. Void creates `sale_void_in` movements and reverses allocations (the payment amounts return to the customer as `unallocated_amount`/`credit_balance`).
- Customer credit limit: if `outstanding + this sale total > credit_limit`, return a **warning** (not a block). The client confirms with `override_credit_limit: true`.

### 9.2 Payment status
Derived from allocations: `unpaid` (paid = 0), `partial` (0 < paid < total), `paid` (paid >= total). Recalculated inside the same transaction whenever allocations change.

### 9.3 Recording a payment (`RecordPayment`)
- Input: customer, amount, method, reference, date, and optionally explicit `allocations: [{sale_id, amount}]`.
- If no allocations are given, **auto-allocate oldest invoice first** (by `due_date`, then `sale_date`, then id) across the customer's confirmed sales with `balance_due > 0`.
- Any remainder is stored in `payments.unallocated_amount` and added to `customers.credit_balance`.
- Assign a receipt number; generate the receipt PDF on demand.
- **Void payment**: mandatory reason; reverses its allocations, recalculates the affected sales and customer credit.
- `AllocateCredit`: applies a customer's credit balance to selected unpaid sales.

### 9.4 Customer money views
- **Outstanding balance** = sum of `balance_due` over the customer's confirmed sales.
- **Statement** (JSON and PDF): opening balance, invoices, payments and a running balance for a date range.
- **Aging** buckets by days past `due_date`: current, 1 to 30, 31 to 60, 61 to 90, 90+.

---

## 10. REST API contract (v1)

Base: `/api/v1`. JSON only. Auth header: `Authorization: Bearer <token>`.

**Conventions**
- Money fields are integers in pesewas. Dates are ISO 8601.
- Lists: `?page=`, `?per_page=`, `?search=`, `?sort=`, plus filters. Response: `{ data: [...], meta: { current_page, last_page, per_page, total }, links }`.
- Errors: `{ "message": "...", "code": "validation_failed|insufficient_stock|price_changed|credit_limit_exceeded|not_found|forbidden|...", "errors": { "field": ["..."] } }` with correct HTTP status (401, 403, 404, 409, 422).
  - Business-rule errors may add a `details` object (decision 2026-10-01, Phase 2A.1): `insufficient_stock` (422) → `details.items[]` of `{product_id, sku, title, requested, available}`; `price_changed` (409) → `details.priced_order`; `credit_limit_exceeded` (409) → `details.{credit_limit, outstanding, sale_total, projected_balance, override_flag}`; `sale_not_editable` (409) → `details.{sale_id, status}`. `errors` is always an object (`{}` when empty).
- Mutating money endpoints (`/sales/{id}/confirm`, `/payments`) require `Idempotency-Key`.

| Area | Endpoints |
|---|---|
| Auth | `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` |
| Lookups | `GET/POST/PUT/DELETE /levels`, `/subjects`, `/languages`, `/publishers`; `GET /level-groups` |
| Products | `GET /products` (filters `level_id`, `level_group_id`, `subject_id`, `language_id`, `publisher_id`, `low_stock`, `active`), `POST /products`, `GET/PUT/DELETE /products/{id}`, `GET /products/by-code/{code}` (sku/isbn/barcode), `GET /products/{id}/movements` |
| Stock | `POST /stock/receipts`, `GET /stock/receipts`, `GET /stock/receipts/{id}`, `POST /stock/adjustments`, `POST /stock/counts`, `GET /stock/counts/{id}`, `PUT /stock/counts/{id}/items`, `POST /stock/counts/{id}/apply` |
| Customers | `GET/POST /customers`, `GET/PUT/DELETE /customers/{id}`, `GET /customers/{id}/sales`, `GET /customers/{id}/payments`, `GET /customers/{id}/statement` (`?from=&to=&format=json|pdf`) |
| Pricing | `POST /pricing/preview` (body: `customer_id`, `items[{product_id, quantity}]`); Phase 4: `GET/POST/PUT/DELETE /price-rules` |
| Sales | `GET /sales`, `POST /sales` (creates draft), `GET /sales/{id}`, `PUT /sales/{id}` (drafts only), `POST /sales/{id}/confirm`, `POST /sales/{id}/cancel`, `POST /sales/{id}/void`, `POST /sales/{id}/deliver`, `GET /sales/{id}/invoice` (PDF) |
| Payments | `GET /payments`, `POST /payments`, `GET /payments/{id}`, `POST /payments/{id}/void`, `GET /payments/{id}/receipt` (PDF), `POST /customers/{id}/apply-credit` |
| Reports | `GET /reports/dashboard`, `/reports/sales-summary`, `/reports/profit`, `/reports/best-sellers`, `/reports/stock-valuation`, `/reports/low-stock`, `/reports/receivables-aging` |
| Settings | `GET /settings`, `PUT /settings` |

Document every endpoint (request/response examples) in `docs/api.md` as it is built.

---

## 11. Filament web admin

Panel at `/admin`, accessible only to `role = owner`. Resources call Actions, never write models directly for business operations.

| Resource / page | Notes |
|---|---|
| ProductResource | Table filters: level group, level, subject, language, publisher, low stock, active. Quick stock badge. CSV import (title, level, subject, language, cost, price, opening stock) in Phase 1. |
| LookupResources | Levels, subjects, languages, publishers (simple managed lists) |
| GoodsReceiptResource | Repeater of products with qty and unit cost; creates movements via `ReceiveStock` |
| StockCountResource | Generate count sheet by level/subject, enter counted quantities, review variances, apply |
| StockMovementResource | Read-only ledger with filters |
| CustomerResource | Relation managers: sales, payments. Header actions: Record payment, Download statement. Shows outstanding and credit balance |
| SaleResource | Create/edit draft with repeater; live pricing preview via `PricingService`; Confirm / Cancel / Void / Mark delivered actions; invoice PDF download |
| PaymentResource | Record payment with auto or manual allocation; void; receipt PDF |
| PriceRuleResource + Test page | Phase 4 |
| Reports pages + Widgets | Dashboard widgets: today's sales, month sales, total owed, low-stock count, top sellers. Pages for each report in section 14 |
| SettingsPage | Business details, invoice footer, `allow_negative_stock`, payment terms, tax toggle (disabled until Phase 5) |
| ActivityLogResource | Read-only |

---

## 12. Flutter app

### 12.1 Architecture
Feature-first, three layers per feature: `data` (API client, DTOs, repository impl), `domain` (models, repository interface), `presentation` (screens, widgets, `ChangeNotifier` providers). `core/` holds Dio client with interceptors (attach token, map errors to `ApiException`, handle 401 by logging out), secure token storage, `Money` (int pesewas to `GHS 1,234.50`), theme, `go_router` config with auth redirect.

### 12.2 Screens (by phase)
- **Auth:** Login.
- **Dashboard:** today's sales, total owed, low-stock count, quick actions (New sale, Record payment, Receive stock, Scan).
- **Products:** list with search + level/subject/language filter chips; detail with stock and movement history; add/edit; barcode scan to find or create.
- **Stock:** receive stock (scan or search items, qty, unit cost); adjustment.
- **Customers:** list/search; detail (outstanding, credit, recent invoices and payments); add/edit; **statement PDF** share.
- **New sale:** pick customer, add items (search, filter by level/subject/language, scan), edit quantities fast (numeric keypad), live totals from `/pricing/preview`, save draft or confirm; handle `insufficient_stock`, `price_changed` and credit warning responses with clear dialogs.
- **Sales:** list with status/payment filters; detail with items, payments, actions (confirm, void, deliver); **share invoice PDF**.
- **Payments:** record payment (method, amount, reference, auto-allocation preview); list; **share receipt PDF**.
- **Reports (light):** today, this month, who owes most.

UX: large tap targets, works one-handed on a phone and well on a tablet, clear loading/error/empty states, pull-to-refresh, Retry on network failure, all money shown with currency.

### 12.3 Quality
Widget tests for the sale-entry and payment screens; unit tests for `Money` and provider logic; no business calculation duplicated from the server (totals always come from the API).

---

## 13. Auth and security

- Login returns a Sanctum token (name = device). Logout revokes it. Throttle login (5/min).
- `role = owner` required for all current endpoints. A `school` role exists in the enum but has no access yet; policies must already check ownership via `customer_id` so Phase 6 is safe.
- HTTPS only in production, hashed tokens, `APP_DEBUG=false`, CORS locked to the web admin origin, mass-assignment protection via explicit `$fillable`.
- Rate limit API (60/min per token), log failed logins.
- Nightly database backup to off-server storage; monthly restore test documented in README.

---

## 14. Reports (all server-side, via Report Actions)

1. **Dashboard**: today and month sales (confirmed), collections, total owed, low-stock count, top 5 sellers.
2. **Sales summary**: by day/week/month; filter by customer, level, subject, language.
3. **Profit**: revenue minus snapshot cost (minus order-level discounts), by period/product/level/subject.
4. **Best sellers**: by quantity and revenue, by level/subject/language.
5. **Stock valuation**: on-hand quantity at cost and at selling price.
6. **Low / dead stock**: below reorder level; no movement in N days.
7. **Receivables aging**: per customer by bucket, with totals.
8. **Customer statement**: section 9.4.

Exports (CSV/Excel/PDF) arrive in Phase 5; pages and JSON endpoints in Phase 3.

---

## 15. Seams for later features

**Tax (Phase 5):** `tax_amount` columns exist (default 0) on `sales` and `sale_items`; `settings.tax_enabled` is false; prices stay tax-exclusive. Phase 5 adds `tax_rates` (VAT, NHIL, GETFund), a `TaxService`, and invoice breakdown lines. Do not implement tax logic before then, but never remove the columns.

**School portal (Phase 6):** `users.customer_id`, `sales.source`, status `requested` and policy-based scoping are already in the schema. A school's order arrives as `requested`; the owner approves it into `confirmed` via the normal `ConfirmSale` Action.

**Offline (Phase 6, only if justified):** local queue in Flutter with idempotency keys; conflict rules for stock decided at that time.

---

## 16. Testing requirements

Use Pest. The following **must** be covered before their phase is accepted:

- Stock: receipt increases stock and writes balanced movements; confirm deducts; void restores; negative stock blocked/allowed per setting; count apply writes variance movements; reconcile command detects tampering.
- Sales: draft edit rules; confirm is idempotent; confirm re-prices and returns 409 on change; cancel/void rules; invoice numbers are sequential and unique under concurrent confirmation.
- Payments: auto-allocation is oldest-first; partial and over-payment; credit balance; void reverses everything correctly; payment status derived correctly after every change.
- Pricing: every rule type, specificity and priority ordering, stacking, quantity windows, date windows, floor policies, rounding.
- Authorization: unauthenticated and non-owner access is rejected on every endpoint group.
- Reports: figures reconcile with seeded ledger data.

---

## 17. Phased delivery with acceptance criteria

### Phase 0: Monorepo and tooling
Tasks: create repo and folder structure (section 3); `laravel new` (Laravel 13) in `laravel/`, install Sanctum, Filament, Pest, Pint, activity log, DomPDF; `flutter create` in `flutter/` with packages (section 4); root `CLAUDE.md`, `README.md`, `.editorconfig`, `docs/`; MySQL connection; Pint and Pest run clean; Flutter app boots to a blank themed screen.
**Accept:** both apps run locally; `php artisan test` and `flutter test` pass; docs exist.

### Phase 1: Foundation (catalog and stock-in)
Tasks: users/roles/settings/number_sequences migrations; owner seeder; Sanctum auth endpoints; lookup tables with seeders; products with CRUD, filters and code lookup; goods receipts and stock movements; `ReceiveStock`, `AdjustStock`; Filament: Product, lookups, GoodsReceipt, StockMovement, Settings; CSV product import; Flutter: login, product list/search/filter/detail/add, scan lookup, receive stock.
**Accept:** owner can load the full catalog (including by CSV), receive stock, see an accurate ledger and current stock on both web and phone; all section 16 stock tests for this phase pass.

### Phase 2: Sell and collect
Tasks: customers; `PricingService` (base price implementation behind the final interface); sales and sale_items; `CreateDraftSale`, `UpdateDraftSale`, `ConfirmSale`, `CancelSale`, `VoidSale`, `MarkDelivered`; invoice PDF; payments and allocations; `RecordPayment`, `VoidPayment`, `AllocateCredit`; receipt PDF; Filament: Customer, Sale, Payment resources; Flutter: customers, new sale, sales list/detail, record payment, share invoice/receipt.
**Accept:** end-to-end scenario passes on both clients: create school, draft a bulk order, confirm (stock drops, invoice issued), record two instalments (one auto-allocated across two invoices, one overpayment becoming credit), void an invoice and see balances reverse correctly.

### Phase 3: Insight
Tasks: customer statements (JSON/PDF), aging, dashboard, sales summary, profit, best sellers, stock valuation, low stock; stock-take flow; `stock:reconcile` command; Filament dashboard widgets and report pages; Flutter dashboard and light reports.
**Accept:** every report matches hand-checked figures on a seeded dataset; the owner can run a stock-take and apply variances; statement PDF matches ledger exactly.

### Phase 4: Pricing rules engine
Tasks: `price_rules` migration and model; full `PricingService` implementation (section 7); `/pricing/preview` using rules; rule CRUD API; Filament PriceRuleResource and Test page; Flutter shows applied discounts and warnings in new sale; price-changed (409) flow; manual override with reason.
**Accept:** all pricing tests pass; a rule created in Filament changes the preview in the app immediately; confirmed historical sales are unchanged after rule edits.

### Phase 5: Completeness
Tasks: sales returns (with restock good/damaged); suppliers and purchase orders feeding goods receipts; tax (section 15); Excel/PDF exports for reports; backup automation and restore doc; hardening and performance pass.
**Accept:** returns reverse stock and balances correctly; tax-enabled invoices show correct breakdowns while tax-disabled behavior is unchanged.

### Phase 6: Growth
School portal with order requests and approval; iOS build; offline queue if field use justifies it.

---

## 18. Definition of done (every task)

- Code follows sections 5 and 6 exactly; names match the spec.
- Pint passes; Pest and Flutter tests pass; new logic has tests.
- API changes are documented in `docs/api.md`.
- Any deviation from this spec is recorded in `docs/decisions.md` with the reason.
- The agent's summary lists: what was built, files touched, tests added, anything deferred, and the proposed next task.

---

## Appendix A: `CLAUDE.md` (place at repo root)

```md
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
```

## Appendix B: Prompt to start Phase 0 in Claude Code

```
Read docs/build-spec.md fully, then CLAUDE.md. Execute Phase 0 only.
Before writing code, restate the Phase 0 tasks and acceptance criteria and list any
assumptions. Then implement task by task. After each task run the relevant tests and
summarize. Stop at the end of Phase 0 and wait for my review.
```