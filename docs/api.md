# API reference

Base: `/api/v1`. JSON only. Money fields are integers in pesewas. Dates are ISO 8601.

Auth header: `Authorization: Bearer <token>`.

## Conventions

- Lists: `{ data, meta, links }` with `?page=`, `?per_page=` (default 25, max 100), `?search=`, `?sort=`
- Errors: `{ message, code, errors }` with HTTP 401 / 403 / 404 / 409 / 422
- Mutating money endpoints require `Idempotency-Key`

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

Response `201` with a `StockMovement` resource. If resulting stock would be negative and `allow_negative_stock` is false, `422` with `code: insufficient_stock`.
