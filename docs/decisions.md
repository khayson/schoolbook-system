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
