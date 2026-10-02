# API reference

Base: `/api/v1`. JSON only. Money fields are integers in pesewas. Dates are ISO 8601.

Auth header: `Authorization: Bearer <token>`.

## Conventions

- Lists: `{ data, meta, links }` with `?page=`, `?per_page=` (default 25, max 100), `?search=`, `?sort=`
- Errors: `{ message, code, errors, details? }` with HTTP 401 / 403 / 404 / 409 / 422 (see [Errors](#errors))
- Mutating money endpoints require `Idempotency-Key`

## Errors

Every error response uses one envelope:

```json
{
  "message": "Human-readable summary.",
  "code": "machine_readable_code",
  "errors": { "items.0.product_id": ["The selected items.0.product_id is invalid."] },
  "details": { }
}
```

- `message`: show to the user as-is.
- `code`: branch on this, never on `message` or the HTTP status alone.
- `errors`: field errors keyed by request path (e.g. `items.2.quantity`). Always an object; `{}` when there are none.
- `details`: present only for business-rule errors that carry extra data (below). Absent otherwise.

### Business-rule codes

| Code | HTTP | When | `details` |
|---|---|---|---|
| `validation_failed` | 422 | Form Request validation, or the same rule re-checked inside a service (unknown/inactive product or customer, missing override reason, quantity ≤ 0) | none; see `errors` |
| `sale_not_editable` | 409 | Updating (or, from 2B, confirming/cancelling) a sale that is not a draft | `{ sale_id, status }` |
| `insufficient_stock` | 422 | Stock would go negative and `allow_negative_stock` is off. Lists **every** short product | `{ items: [ { product_id, sku, title, requested, available } ] }` |
| `price_changed` | 409 | Confirming a draft whose prices no longer match. Nothing was written | `{ priced_order }` (same shape as `POST /pricing/preview` `data`) |
| `sale_state_conflict` | 409 | The sale changed (e.g. reassigned to another customer) between loading and locking. Nothing written; reload and retry | `{ sale_id, retry: true }` |
| `sale_delivered` | 409 | Voiding a sale that has been delivered | `{ sale_id, delivered_at }` |
| `sale_not_payable` | 422 | An allocation names a sale that cannot take money | `{ sale_id, reason: not_found\|other_customer\|not_confirmed\|fully_paid, status? }` |
| `allocation_exceeds_balance` | 422 | An allocation is more than that invoice's balance due | `{ sale_id, invoice_no, balance_due, requested }` |
| `allocation_exceeds_payment` | 422 | Allocations add up to more than the payment amount | `{ amount, allocated_total }` |
| `allocation_exceeds_credit` | 422 | Credit allocations add up to more than the customer's credit | `{ credit_balance, requested }` |
| `no_credit_available` | 409 | Applying credit for a customer with none | `{ customer_id, credit_balance }` |
| `payment_already_void` | 409 | Voiding a void payment, or asking for its receipt | `{ payment_id, receipt_no, action: void\|receipt }` |
| `credit_limit_exceeded` | 409 | Outstanding balance + this sale − credit applied (`apply_credit`) > customer credit limit. A warning: resend with the flag in `override_flag` set to `true` | `{ credit_limit, outstanding, sale_total, credit_applied, projected_balance, override_flag: "override_credit_limit" }` |

Example (`insufficient_stock`):

```json
{
  "message": "Insufficient stock for one or more products.",
  "code": "insufficient_stock",
  "errors": {},
  "details": {
    "items": [
      { "product_id": 7, "sku": "ENG-1", "title": "English 1", "requested": 5, "available": 2 }
    ]
  }
}
```

### Other codes

| Code | HTTP | When |
|---|---|---|
| `unauthenticated` | 401 | Missing or invalid Bearer token |
| `forbidden` | 403 | Authenticated but not allowed (e.g. non-owner on staff endpoints) |
| `not_found` | 404 | Unknown route or record |
| `idempotency_key_required` | 422 | Idempotent endpoint called without `Idempotency-Key` |
| `idempotency_key_mismatch` | 422 | Key reused with a different request body |
| `request_in_progress` | 409 | Key reused while the first request is still running; retry shortly with the same key |

## Auth

### `POST /auth/login`

Throttle: 5/min.

Request:

```json
{ "email": "owner@schoolbook.test", "password": "password", "device_name": "pixel" }
```

Response `200`:

```json
{
  "token": "...",
  "token_type": "Bearer",
  "user": { "id": 1, "name": "Owner", "email": "owner@schoolbook.test", "role": "owner", "is_active": true }
}
```

`403` if inactive or non-owner. `422` on bad credentials / validation.

### `POST /auth/logout`

Requires Bearer token. Revokes current token. `200` `{ "message": "Logged out." }`

### `GET /auth/me`

Requires Bearer token + owner. Returns `{ "data": { ...user } }`.

## Lookups

All lookup routes require Bearer token + owner. Lists support `?page=`, `?per_page=` (default 25, max 100), and `?search=`. Responses use `{ data, meta, links }`. `DELETE` soft-deletes records where applicable.

### `GET /level-groups`

Index only. Items: `{ id, name, slug, sort_order }`.

### `GET /levels`

Items include `level_group_id`, optional nested `level_group`, `name`, `slug`, `sort_order`.

### `POST /levels`

```json
{ "level_group_id": 1, "name": "Primary 1", "slug": "primary-1", "sort_order": 1 }
```

`slug` optional (derived from `name` when omitted).

### `PUT /levels/{id}`

Partial update of the same fields.

### `DELETE /levels/{id}`

Soft-deletes the level. Returns the resource payload.

### `GET /subjects` · `POST /subjects` · `GET /subjects/{id}` · `PUT /subjects/{id}` · `DELETE /subjects/{id}`

Subject fields: `{ id, name, slug, is_active }`. Create/update accept `name`, optional `slug`, optional `is_active`.

### `GET /languages` · `POST /languages` · `GET /languages/{id}` · `PUT /languages/{id}` · `DELETE /languages/{id}`

Language fields: `{ id, name, code, is_active }`.

### `GET /publishers` · `POST /publishers` · `GET /publishers/{id}` · `PUT /publishers/{id}` · `DELETE /publishers/{id}`

Publisher fields: `{ id, name, contact_person, phone, email, notes }`.

## Products

All product routes require Bearer token + owner. Money fields (`cost_price`, `selling_price`) are integers in pesewas. `stock_on_hand` is read-only via the API (always `0` on create; ignored on update). Lists support `?page=`, `?per_page=` (default 25, max 100).

### `GET /products`

Filters (all optional): `level_id`, `level_group_id`, `subject_id`, `language_id`, `publisher_id`, `low_stock=1` (where `stock_on_hand <= reorder_level`), `active` (boolean). Search: `?search=` matches `title`, `sku`, `isbn`, or `barcode`.

Item shape:

```json
{
  "id": 1,
  "sku": "MATH-P1-EN-001",
  "isbn": null,
  "barcode": null,
  "title": "Primary Mathematics",
  "level_id": 5,
  "subject_id": 1,
  "language_id": 1,
  "publisher_id": null,
  "edition": null,
  "cost_price": 4500,
  "selling_price": 6000,
  "reorder_level": 5,
  "stock_on_hand": 0,
  "is_active": true,
  "level": { "...": "..." },
  "subject": { "...": "..." },
  "language": { "...": "..." },
  "publisher": null
}
```

### `POST /products`

```json
{
  "sku": "MATH-P1-EN-001",
  "isbn": "9781234567890",
  "barcode": "1234567890123",
  "title": "Primary Mathematics",
  "level_id": 5,
  "subject_id": 1,
  "language_id": 1,
  "publisher_id": null,
  "edition": "2024",
  "cost_price": 4500,
  "selling_price": 6000,
  "reorder_level": 5,
  "is_active": true
}
```

`stock_on_hand` must not be sent (422 if present). Response `201` with the product resource.

### `GET /products/{id}` · `PUT /products/{id}` · `DELETE /products/{id}`

`GET` returns one product with nested lookups when loaded. `PUT` accepts the same fields as create (partial update). `DELETE` soft-deletes and returns the resource.

### `GET /products/by-code/{code}`

Resolves a product by exact `sku`, `isbn`, or `barcode`. `404` if none match.

### `GET /products/{id}/movements`

Paginated stock movement history for the product. Items:

```json
{
  "id": 1,
  "product_id": 1,
  "type": "receipt_in",
  "quantity": 25,
  "balance_after": 35,
  "unit_cost": 5500,
  "reference_type": "App\\Models\\GoodsReceipt",
  "reference_id": 1,
  "note": null,
  "user_id": 1,
  "occurred_at": "2026-03-15T10:00:00+00:00",
  "created_at": "2026-03-15T10:00:00+00:00"
}
```

## Stock

All stock routes require Bearer token + owner. Receipt and adjustment quantities use signed integers for adjustments; receipt line quantities are positive. Money fields (`unit_cost`) are pesewas.

### `POST /stock/receipts`

Requires `Idempotency-Key` header. Same key + same body replays the original `201` (header `Idempotency-Replayed: true`). Same key + different body → `422` `idempotency_key_mismatch`. Missing key → `422` `idempotency_key_required`.

Creates a goods receipt, `receipt_in` movements, updates `stock_on_hand`, and sets each product's `cost_price` to the line `unit_cost`.

```json
{
  "supplier_id": null,
  "supplier_reference": "PO-9921",
  "received_at": "2026-04-01T09:00:00Z",
  "notes": "Morning delivery",
  "items": [
    { "product_id": 1, "quantity": 12, "unit_cost": 4800 }
  ]
}
```

Response `201` with `GoodsReceipt` resource (`receipt_no` like `GRN-2026-000001`, nested `items` with optional `product`). Unknown `product_id` → `422`.

### `GET /stock/receipts`

Paginated list ordered by `received_at` descending. Optional `?search=` matches `receipt_no` or `supplier_reference`.

### `GET /stock/receipts/{id}`

Single receipt with `supplier`, `items.product`, and `created_by`.

### `POST /stock/adjustments`

Manual adjustment or damage write-off. `note` is required. `quantity` is signed (negative reduces stock). `type` is `adjustment` or `damage`.

```json
{
  "product_id": 1,
  "quantity": -5,
  "type": "adjustment",
  "note": "Found damaged box during shelf check"
}
```

Response `201` with a `StockMovement` resource. If resulting stock would be negative and `allow_negative_stock` is false, `422` with `code: insufficient_stock` and `details.items` (see [Errors](#errors)).

## Sales

All sales routes require Bearer token + owner. Money is pesewas. A sale moves `draft -> confirmed -> void` or `draft -> cancelled`; delivery is a timestamp, not a status. Any action on a sale in the wrong status returns `409 sale_not_editable` with `details.{sale_id, status, action}`.

### `POST /sales/{id}/confirm`

Requires `Idempotency-Key`. Same key + same body replays the original `200` (header `Idempotency-Replayed: true`) with the same `invoice_no`; no second stock movement.

```json
{ "due_date": "2026-11-30", "override_credit_limit": false, "apply_credit": true }
```

All fields optional. `apply_credit: true` applies the customer's credit (FIFO over their payments) to this invoice in the same transaction; with no credit it does nothing. Credit applied also lowers the credit-limit exposure. `due_date` defaults to the draft's `due_date`, else confirmation date + `default_payment_terms_days`.

In one transaction: reprices the draft at its `sale_date`, checks stock (summed per product), checks the credit limit, writes `sale_out` movements, refreshes each line's `unit_cost` from the product, then assigns `invoice_no` (`INV-YYYY-000001`, year of the **confirmation** date). Response `200` with the `Sale` resource (including `allocations`): `status: confirmed`, `balance_due = total − credit applied`, `payment_status` derived (`unpaid`, `partial`, or `paid`; a zero-total sale is `paid`).

Failures write nothing:

| Status | `code` | Client action |
|---|---|---|
| 409 | `price_changed` | Show `details.priced_order`. To accept, re-save the draft (`PUT /sales/{id}` with `{}` reprices it at current prices), then confirm again. The same `Idempotency-Key` may be reused. |
| 422 | `insufficient_stock` | Show every line in `details.items`. |
| 409 | `credit_limit_exceeded` | Warning. Ask the user, then resend with `override_credit_limit: true` (logged in the activity log). |
| 422 | `validation_failed` | A product or the customer was deactivated or deleted after drafting; `errors` names the line (`items.N.product_id`) or `customer_id`. |
| 409 | `sale_not_editable` | Already confirmed, cancelled or void. Reload the sale. |
| 409 | `sale_state_conflict` | The draft was moved to another customer while this request ran. Nothing was written; reload and retry (`details.retry: true`). |

### `POST /sales/{id}/cancel`

Drafts only. Optional `{ "reason": "..." }`. Response `200`, `status: cancelled`. No stock or money effect.

### `POST /sales/{id}/void`

Confirmed sales only. `{ "reason": "..." }` is required. Writes a `sale_void_in` movement per line at the line's snapshot `unit_cost`, restores `stock_on_hand`, sets `status: void`, `balance_due: 0`, `voided_at`, `voided_by`, `void_reason`. The `invoice_no` is kept. Money already applied is reversed through the ledger (one negative reversal row per allocation): it returns to each payment's `unallocated_amount` and the customer's `credit_balance`, ready for `POST /customers/{id}/apply-credit`. `amount_paid` and `balance_due` become 0.

Refusals (nothing written): `409 sale_delivered` if `delivered_at` is set (delivered goods come back through returns, Phase 5); `409 sale_state_conflict` as for confirm; `409 sale_not_editable` if not confirmed.

### `POST /sales/{id}/deliver`

Confirmed sales only. Sets `delivered_at`; calling it again keeps the original timestamp. Response `200`.

### `GET /sales/{id}/invoice`

`application/pdf` download named `INV-YYYY-NNNNNN.pdf`. Business name, address, phone and footer come from settings. Confirmed sales only; drafts, cancelled and void sales return `409 sale_not_editable` (`details.action: invoice`).

`GET /sales/{id}` includes `allocations`: the sale's ledger rows `{ id, payment_id, receipt_no, sale_id, amount, reversal_of_id, created_by, created_at }`. Negative rows are reversals.

## Payments

All payment routes require Bearer token + owner. Amounts are pesewas. A payment's money is either applied to invoices (allocations) or held as customer credit (`unallocated_amount`). Allocations form an immutable ledger: corrections add negative reversal rows, nothing is edited or deleted.

### `POST /payments`

Requires `Idempotency-Key` (replay returns the original `201`; same key with a different body is `422 idempotency_key_mismatch`).

```json
{
  "customer_id": 12,
  "amount": 350000,
  "method": "momo",
  "reference": "MP261002.1234.A1",
  "paid_at": "2026-10-02",
  "notes": "Term 1 books",
  "auto_allocate": true,
  "allocations": [ { "sale_id": 41, "amount": 200000 } ]
}
```

- `amount` integer > 0 and ≤ 100,000,000,000 (GHS 1 billion; same cap on allocation amounts). `method`: `cash`, `momo`, `bank_transfer`, `cheque`. `reference` is required for every method except `cash` (it traces the real transaction). `paid_at` defaults to now and cannot be in the future.
- **Allocation:** if `allocations` is given, exactly those invoices are paid (each must be this customer's confirmed sale; amount ≤ its `balance_due`; total ≤ `amount`; sale ids distinct). Otherwise, if `auto_allocate` (default `true`), invoices are paid **oldest due first** (`due_date`, then `sale_date`, then id). `auto_allocate: false` with no `allocations` puts the whole amount on credit.
- The remainder becomes `unallocated_amount` and is added to the customer's `credit_balance`.
- Receipt number `RCT-YYYY-NNNNNN`, year of the **recording** date (not `paid_at`).

Response `201` with the `Payment` resource including `allocations` (`invoice_no`, `amount`) and `customer` (with `credit_balance`, `outstanding_balance`).

Errors (nothing written): `422 validation_failed`, `422 sale_not_payable`, `422 allocation_exceeds_balance`, `422 allocation_exceeds_payment`.

### `GET /payments`

Paginated, newest `paid_at` first. Filters: `customer_id`, `method`, `status` (`valid`|`void`), `from`, `to` (dates on `paid_at`, inclusive).

### `GET /payments/{id}`

Payment with `customer`, `allocations` (with `invoice_no`; reversals are negative rows with `reversal_of_id`).

### `POST /payments/{id}/void`

`{ "reason": "Cheque bounced" }` (required). Adds a reversal row for every allocation still in effect, so each invoice owes that money again; removes the payment's remaining amount from the customer's credit; sets `status: void`, `unallocated_amount: 0`, `voided_at`, `voided_by`, `void_reason`. A void payment returns `409 payment_already_void`.

### `GET /payments/{id}/receipt`

`application/pdf` download named `RCT-YYYY-NNNNNN.pdf`: business details, receipt no., date, customer, method and reference, amount, per-invoice breakdown with each invoice's remaining balance, unapplied amount, customer credit and outstanding balance. Void payments return `409 payment_already_void` (`details.action: receipt`).

### `POST /customers/{id}/apply-credit`

Requires `Idempotency-Key` (a retry must not apply credit twice). Body optional:

```json
{ "allocations": [ { "sale_id": 41, "amount": 50000 } ] }
```

Without `allocations`, credit goes to the oldest-due invoices first. If the customer has credit but no open invoices, the response is `200` with `applied_total: 0`, no allocations, and the credit untouched. Credit is drawn from the customer's payments FIFO (`paid_at`, then id). Response `200`:

```json
{ "data": { "applied_total": 50000, "customer": { "credit_balance": 0, "outstanding_balance": 120000 }, "allocations": [ { "receipt_no": "RCT-2026-000004", "invoice_no": "INV-2026-000041", "amount": 50000 } ] } }
```

Errors: `409 no_credit_available`, `422 allocation_exceeds_credit`, `422 sale_not_payable`, `422 allocation_exceeds_balance`.

