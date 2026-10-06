# Phase 3 acceptance: reports (3.1), statements (3.2), stock-take (3.3), clients (3.4)

The dataset below is built by `tests/Support/ReportsFixture.php` with the real actions (`ReceiveStock`, `CreateDraftSale`, `ConfirmSale`, `RecordPayment`, `VoidSale`, `VoidPayment`) on the **test databases only** (SQLite in the default suite, `schoolbook_test` in the mysql group). Every figure in this file was **calculated by hand from the dataset before the report code was written**. The tests assert these exact literals. If a test and this file disagree, the code is wrong, not the file.

Money is in **pesewas** (GHS 1 = 100). Dates are Africa/Accra (UTC+0 all year, no daylight saving). Periods are inclusive.

## 1. Dataset

### Catalog

Two levels (Primary 4, JHS 1), two subjects (Mathematics, Science), one language (English).

| Product | Level | Subject | Cost | Price | Reorder level |
|---|---|---|---:|---:|---:|
| A Maths P4 | Primary 4 | Mathematics | 3,000 | 5,000 | 90 |
| B Science P4 | Primary 4 | Science | 2,500 | 4,000 | 10 |
| C Maths JHS1 | JHS 1 | Mathematics | 3,600 | 6,000 | 40 |
| D Science JHS1 | JHS 1 | Science | 1,800 | 3,000 | 5 |
| E Maths P4 Workbook | Primary 4 | Mathematics | 500 | 1,000 | 0 |
| F Science JHS1 Workbook | JHS 1 | Science | 1,000 | 2,000 | 0 |

**Receipt** (one `ReceiveStock`, 2026-01-05 09:00): A 100 @ 3,000; B 50 @ 2,500; C 40 @ 3,600; D 20 @ 1,800; E 2 @ 500; F 10 @ 1,000. Costs never change after this, so every sale's snapshot `unit_cost` is the cost above.

### Customers

- **Alpha School** (S1): June buyer; pays one invoice in full; one payment recorded by mistake and voided.
- **Beta School** (S2): one unpaid invoice in every aging bucket.
- **Gamma Academy** (S3): overpays, so it holds **credit**.

### Order-level discount

Order-level discounts arrive with the Phase 4 rules engine; today's `PricingService` never produces one. The fixture binds a stand-in pricing rule shaped like Phase 4 (**5% off an order of 15 books or more**, applied by the real `CreateDraftSale`/`ConfirmSale` as `discount_total`). Only sale 6 has 15 books: 5% × 80,000 = **4,000**. See `docs/decisions.md`.

### Sales

Each draft is created and confirmed at the moment shown (`sale_date` = that date). Due dates are given at confirmation.

| # | Customer | Created & confirmed | Lines | Subtotal | Order discount | Total | Due |
|---|---|---|---|---:|---:|---:|---|
| 1 | Beta | 2026-02-02 10:00 | A×2 @5,000 = 10,000; F×1 @2,000 = 2,000 | 12,000 | 0 | 12,000 | 2026-03-01 |
| 2 | Beta | 2026-03-20 10:00 | B×3 @4,000 | 12,000 | 0 | 12,000 | 2026-04-20 |
| 3 | Beta | 2026-04-20 10:00 | C×1 @6,000 | 6,000 | 0 | 6,000 | 2026-05-20 |
| 4 | Beta | 2026-05-20 10:00 | B×1 @4,000 | 4,000 | 0 | 4,000 | 2026-06-20 |
| 5 | Beta | 2026-06-15 10:00 | A×1 @5,000 | 5,000 | 0 | 5,000 | 2026-07-15 |
| 6 | Alpha | 2026-06-10 11:00 | A×10 @5,000 = 50,000; C×5 @6,000 = 30,000 (15 books) | 80,000 | 4,000 | 76,000 | 2026-07-10 |
| 7 | Alpha | **2026-06-14 23:30** (Sunday) | B×2 at an overridden 3,500 ("promotion") = 7,000 | 7,000 | 0 | 7,000 | 2026-07-14 |
| 8 | Alpha | **2026-06-15 00:30** (Monday) | A×1 @5,000 | 5,000 | 0 | 5,000 | 2026-07-15 |
| 9 | Alpha | 2026-06-16 09:00, **voided** 2026-06-16 15:00 | C×2 @6,000 | 12,000 | 0 | 12,000 | 2026-07-16 |
| 10 | Gamma | 2026-06-20 10:00, `allow_negative_stock` on | E×3 @1,000 | 3,000 | 0 | 3,000 | 2026-07-20 |

Sale 7's override is a line-level price change: `line_total` is already net of it (7,000), so it is part of revenue, not an order-level discount.

### Payments

| # | Customer | Paid at | Amount | Allocation | Status |
|---|---|---|---:|---|---|
| P1 | Beta | 2026-05-10 12:00 | 2,000 | explicit: sale 2 | valid |
| P2 | Alpha | 2026-06-12 12:00 | 76,000 | explicit: sale 6 | valid |
| P3 | Gamma | 2026-06-21 12:00 | 5,000 | auto: sale 10 3,000; 2,000 stays as **credit** | valid |
| P4 | Alpha | 2026-06-25 12:00 | 1,000 | auto: sale 7 (open invoices 7 and 8; 7 is due first) | **voided** 2026-06-25 13:00 |

The fixture records the payments after all the sales; each payment's own date (`paid_at`) is what the reports use.

### Resulting balances (end of dataset)

| Sale | Total | Paid | Balance due |
|---|---:|---:|---:|
| 1 | 12,000 | 0 | 12,000 |
| 2 | 12,000 | 2,000 | 10,000 |
| 3 | 6,000 | 0 | 6,000 |
| 4 | 4,000 | 0 | 4,000 |
| 5 | 5,000 | 0 | 5,000 |
| 6 | 76,000 | 76,000 | 0 |
| 7 | 7,000 | 0 (P4 reversed) | 7,000 |
| 8 | 5,000 | 0 | 5,000 |
| 9 | void | | (not owed) |
| 10 | 3,000 | 3,000 | 0 |

Customers: Alpha owes 7,000 + 5,000 = **12,000**; Beta owes 12,000 + 10,000 + 6,000 + 4,000 + 5,000 = **37,000**; Gamma owes **0** and has **2,000 credit**.

### Stock at the end

| Product | Received | Sold (confirmed sales) | Void returns | On hand |
|---|---:|---|---:|---:|
| A | 100 | 2 (s1) + 1 (s5) + 10 (s6) + 1 (s8) = 14 | 0 | **86** |
| B | 50 | 3 (s2) + 1 (s4) + 2 (s7) = 6 | 0 | **44** |
| C | 40 | 1 (s3) + 5 (s6) + 2 (s9) = 8 | +2 (s9 void) | **34** |
| D | 20 | 0 | 0 | **20** |
| E | 2 | 3 (s10) | 0 | **−1** |
| F | 10 | 1 (s1) | 0 | **9** |

## 2. Definitions (spec section 14)

- **Revenue** = confirmed sales' `subtotal − discount_total`, by `sale_date`. Voided, cancelled and draft sales never count. (`subtotal` is the sum of line totals, which already include line-level price overrides.)
- **Line revenue** = sum of `line_total` of the lines in scope. Used wherever figures are split by product, level, subject or language: an order-level discount belongs to the whole order, not to a product, so it is reported as its own line, never spread over products.
- **Collections** = valid payments' `amount`, by `paid_at` date (cash received, not allocations). Voided payments never count.
- **Gross profit** per line = `line_total − unit_cost × quantity` (snapshot cost at confirmation). **Net profit** = gross profit − order-level discounts.
- **Catalog grouping** uses each product's *current* level, subject and language.
- **Stock valuation** counts `max(0, stock_on_hand)` at current cost and current selling price; products with negative stock are counted separately.
- **Low stock**: active products with `stock_on_hand ≤ reorder_level`; shortfall = `reorder_level − stock_on_hand`. Each row has a `status`: `out_of_stock` when `stock_on_hand ≤ 0`, otherwise `low` (amended 3.2).
- **Dead stock**: active products with `stock_on_hand > 0` and no sale in the `days` (default 90) before the as-of date, i.e. no `sale_out` movement on or after `as_of − days` **belonging to a sale that is still confirmed**. A voided sale sold nothing, so its `sale_out` is ignored (amended 3.2).
- **Receivables aging**: confirmed sales with `balance_due > 0`, by days past `due_date` on the as-of date: *not yet due* (≤ 0 days), 1–30, 31–60, 61–90, 90+. Balances are today's balances; the as-of date only moves the buckets. Grand total = sum of customers' `outstanding_balance`.
- **Weeks** start on Monday; a week is labelled by its Monday. Periods with no activity are listed with zeros.
- **Row order**: periods in date order; profit rows by gross profit, largest first; best sellers by the chosen measure (quantity or revenue), then the other; low stock by shortfall, largest first; dead stock never-sold first, then oldest last sale; stock valuation by SKU; aging by customer name. Ties: by label.

## 3. Expected figures

### 3.1 Sales summary, June 2026 (2026-06-01 to 2026-06-30)

Confirmed June sales: 5 (5,000), 6 (80,000 − 4,000 = 76,000), 7 (7,000), 8 (5,000), 10 (3,000). Sale 9 is void.

- Line revenue (gross): 5,000 + 80,000 + 7,000 + 5,000 + 3,000 = **100,000**
- Order discounts: **4,000** (sale 6)
- Revenue: 100,000 − 4,000 = **96,000**; sales count **5**
- Collections: P2 76,000 + P3 5,000 = **81,000** (P4 voided; P1 is in May)

**group_by=day** (30 rows; only these are non-zero):

| Day | Sales | Gross | Order disc. | Revenue | Collections |
|---|---:|---:|---:|---:|---:|
| 2026-06-10 | 1 | 80,000 | 4,000 | 76,000 | 0 |
| 2026-06-12 | 0 | 0 | 0 | 0 | 76,000 |
| 2026-06-14 | 1 | 7,000 | 0 | 7,000 | 0 |
| 2026-06-15 | 2 | 10,000 | 0 | 10,000 | 0 |
| 2026-06-20 | 1 | 3,000 | 0 | 3,000 | 0 |
| 2026-06-21 | 0 | 0 | 0 | 0 | 5,000 |

**group_by=week** (2026-06-01 is a Monday; 5 rows):

| Week of | Sales | Revenue | Collections |
|---|---:|---:|---:|
| 2026-06-01 | 0 | 0 | 0 |
| 2026-06-08 | 2 (s6, s7) | 76,000 + 7,000 = **83,000** | 76,000 |
| 2026-06-15 | 3 (s5, s8, s10) | 5,000 + 5,000 + 3,000 = **13,000** | 5,000 |
| 2026-06-22 | 0 | 0 | 0 (P4 voided) |
| 2026-06-29 | 0 | 0 | 0 |

Timezone boundary: sale 7 (Sunday 23:30) is in the week of 06-08; sale 8 (Monday 00:30) is in the week of 06-15.

**group_by=month**, 2026-01-01 to 2026-06-30 (6 rows):

| Month | Sales | Revenue | Collections |
|---|---:|---:|---:|
| 2026-01 | 0 | 0 | 0 |
| 2026-02 | 1 | 12,000 | 0 |
| 2026-03 | 1 | 12,000 | 0 |
| 2026-04 | 1 | 6,000 | 0 |
| 2026-05 | 1 | 4,000 | 2,000 |
| 2026-06 | 5 | 96,000 | 81,000 |
| **Total** | **9** | **130,000** | **83,000** |

**Filters** (June, totals):

| Filter | Sales | Gross | Order disc. | Revenue | Collections |
|---|---:|---:|---:|---:|---:|
| customer = Alpha | 3 (s6, s7, s8) | 92,000 | 4,000 | **88,000** | 76,000 (P4 voided) |
| level = Primary 4 | 5 sales with a P4 line | A: 5,000 + 50,000 + 5,000; B: 7,000; E: 3,000 = **70,000** | not split (null) | 70,000 | not applicable (null) |
| subject = Science | 1 (s7) | **7,000** | null | 7,000 | null |
| language = English | 5 | **100,000** | null | 100,000 | null |

With a level, subject or language filter only matching lines count (line revenue) and the order discount and collections are not split (returned as null).

### 3.2 Profit

**June 2026, group_by=product** (line by line; the report lists them by gross profit: A, C, B, E):

| Product | Qty | Revenue | Cost | Gross profit |
|---|---:|---:|---:|---:|
| A | 1 (s5) + 10 (s6) + 1 (s8) = 12 | 60,000 | 12 × 3,000 = 36,000 | 24,000 |
| B | 2 (s7) | 7,000 | 2 × 2,500 = 5,000 | 2,000 |
| C | 5 (s6) | 30,000 | 5 × 3,600 = 18,000 | 12,000 |
| E | 3 (s10) | 3,000 | 3 × 500 = 1,500 | 1,500 |
| **Total** | 22 | **100,000** | **60,500** | **39,500** |
| Order-level discounts | | | | **−4,000** |
| **Net profit** | | | | **35,500** |

Check: revenue 96,000 − cost 60,500 = 35,500.

**June, group_by=level**: Primary 4 (A, B, E): revenue 70,000, cost 42,500, gross 27,500. JHS 1 (C): 30,000, 18,000, 12,000. Order discounts 4,000; net 35,500.

**June, group_by=subject**: Mathematics (A, C, E): revenue 93,000, cost 55,500, gross 37,500. Science (B): 7,000, 5,000, 2,000. Net 35,500.

**June, group_by=language**: English: 100,000, 60,500, 39,500. Net 35,500.

**2026-01-01 to 2026-06-30, group_by=period (month)**:

| Month | Revenue | Cost | Gross profit |
|---|---:|---:|---:|
| 2026-01 | 0 | 0 | 0 |
| 2026-02 | A 10,000 + F 2,000 = 12,000 | 6,000 + 1,000 = 7,000 | 5,000 |
| 2026-03 | 12,000 | 3 × 2,500 = 7,500 | 4,500 |
| 2026-04 | 6,000 | 3,600 | 2,400 |
| 2026-05 | 4,000 | 2,500 | 1,500 |
| 2026-06 | 100,000 | 60,500 | 39,500 |
| **Total** | **134,000** | **81,100** | **52,900** |
| Order-level discounts | | | **−4,000** |
| **Net profit** | | | **48,900** |

Check: revenue (sales summary) 130,000 − cost 81,100 = 48,900.

### 3.3 Best sellers, June 2026

By product, by quantity: **A** 12 (60,000), **C** 5 (30,000), **E** 3 (3,000), **B** 2 (7,000). By revenue: A 60,000, C 30,000, B 7,000, E 3,000.
By level: Primary 4: 12 + 2 + 3 = **17** books, 70,000; JHS 1: **5**, 30,000.
By subject: Mathematics: 12 + 5 + 3 = **20**, 93,000; Science: **2**, 7,000.
By language: English **22**, 100,000.
Sale 9 (void, C×2) is not counted.

### 3.4 Stock valuation (end of dataset)

| Product | On hand | Counted | At cost | At price |
|---|---:|---:|---:|---:|
| A | 86 | 86 | 86 × 3,000 = 258,000 | 86 × 5,000 = 430,000 |
| B | 44 | 44 | 44 × 2,500 = 110,000 | 44 × 4,000 = 176,000 |
| C | 34 | 34 | 34 × 3,600 = 122,400 | 34 × 6,000 = 204,000 |
| D | 20 | 20 | 20 × 1,800 = 36,000 | 20 × 3,000 = 60,000 |
| E | −1 | 0 | 0 | 0 |
| F | 9 | 9 | 9 × 1,000 = 9,000 | 9 × 2,000 = 18,000 |
| **Total** | | **193** | **535,400** | **888,000** |

Negative-stock products: **1** (E, −1). The totals equal the sum of the product rows.

### 3.5 Low stock

`stock ≤ reorder_level`: **C** 34 ≤ 40 (shortfall 6, status `low`), **A** 86 ≤ 90 (shortfall 4, `low`), **E** −1 ≤ 0 (shortfall 1, `out_of_stock` since −1 ≤ 0). Count **3**. Ordered by shortfall, largest first. B (44 > 10), D (20 > 5), F (9 > 0) are not low.

Edge case (separate test, not in the fixture): a product with stock **0** and reorder level **0** is listed (0 ≤ 0) with shortfall **0** and status **`out_of_stock`**; the ordering and the count rules are unchanged.

### 3.6 Dead stock, as of 2026-06-30, days = 90

Cut-off: 2026-06-30 − 90 days = **2026-04-01**. Products with stock > 0 and no `sale_out` on or after 2026-04-01:

- **D**: never sold; 20 on hand; 36,000 at cost.
- **F**: last sold 2026-02-02 (148 days before 2026-06-30); 9 on hand; 9,000 at cost.

Not dead: A (last sold 06-15), B (06-14), C (06-10 and 04-20; the 06-16 sale was voided and does not count). E has stock −1, not > 0. With days = 150 the cut-off is 2026-01-31 and only D remains.

**Voided sale ignored: as of 2026-06-30, days = 15.** Cut-off 2026-06-30 − 15 = **2026-06-15**. Last *confirmed* sale per product: A 06-15 (s5, s8: on the cut-off, so sold), B 06-14 (s7), C **06-10** (s6; s9 on 06-16 is void), D never, F 02-02.

| Product | Last sold | Days since | On hand | At cost |
|---|---|---:|---:|---:|
| D | never | | 20 | 36,000 |
| F | 2026-02-02 | 148 | 9 | 9,000 |
| C | 2026-06-10 | 20 | 34 | 122,400 |
| B | 2026-06-14 | 16 | 44 | 110,000 |
| **Total** | | | | **277,400** (count **4**) |

If the voided sale counted, C would show last sold 06-16 (≥ cut-off) and drop out: D, F, B only. This is the case that tells the two rules apart.

### 3.7 Receivables aging, as of 2026-06-30

| Sale | Customer | Due | Days past due | Bucket | Balance |
|---|---|---|---:|---|---:|
| 1 | Beta | 2026-03-01 | 121 | 90+ | 12,000 |
| 2 | Beta | 2026-04-20 | 71 | 61–90 | 10,000 |
| 3 | Beta | 2026-05-20 | 41 | 31–60 | 6,000 |
| 4 | Beta | 2026-06-20 | 10 | 1–30 | 4,000 |
| 5 | Beta | 2026-07-15 | −15 | not yet due | 5,000 |
| 7 | Alpha | 2026-07-14 | −14 | not yet due | 7,000 |
| 8 | Alpha | 2026-07-15 | −15 | not yet due | 5,000 |

(Days: 03-01 to 06-30 = 31 + 30 + 31 + 29 = 121; 04-20 to 06-30 = 10 + 31 + 30 = 71; 05-20 to 06-30 = 11 + 30 = 41; 06-20 to 06-30 = 10.)

| Customer | Not yet due | 1–30 | 31–60 | 61–90 | 90+ | Total |
|---|---:|---:|---:|---:|---:|---:|
| Alpha School | 12,000 | 0 | 0 | 0 | 0 | 12,000 |
| Beta School | 5,000 | 4,000 | 6,000 | 10,000 | 12,000 | 37,000 |
| **Total** | **17,000** | **4,000** | **6,000** | **10,000** | **12,000** | **49,000** |

Gamma (balance 0, credit 2,000) does not appear. Grand total 49,000 = sum of `outstanding_balance` (12,000 + 37,000 + 0). Sales 6 and 10 (paid in full) and 9 (void) do not appear.

**Bucket boundaries** (Beta's five invoices on other as-of dates, chosen so invoices fall exactly on a boundary; the days a bucket starts and ends are both checked):

| As of | s1 (due 03-01, 12,000) | s2 (due 04-20, 10,000) | s3 (due 05-20, 6,000) | s4 (due 06-20, 4,000) | s5 (due 07-15, 5,000) |
|---|---|---|---|---|---|
| 2026-06-20 | 111 → 90+ | **61 → 61–90** | **31 → 31–60** | **0 → not yet due** | −25 → not yet due |
| 2026-06-21 | 112 → 90+ | 62 → 61–90 | 32 → 31–60 | **1 → 1–30** | −24 → not yet due |
| 2026-07-19 | 140 → 90+ | **90 → 61–90** | **60 → 31–60** | 29 → 1–30 | 4 → 1–30 |
| 2026-07-20 | 141 → 90+ | **91 → 90+** | **61 → 61–90** | **30 → 1–30** | 5 → 1–30 |
| 2026-07-21 | 142 → 90+ | 92 → 90+ | 62 → 61–90 | **31 → 31–60** | 6 → 1–30 |

(Days: 03-01 to 06-20 = 31 + 30 + 31 + 19 = 111; 04-20 to 06-20 = 30 + 31 = 61; 05-20 to 06-20 = 31; 04-20 to 07-19 = 30 + 31 + 29 = 90; 05-20 to 07-19 = 31 + 29 = 60; 06-20 to 07-19 = 29; each later date adds one.)

Beta's row on those dates:

| As of | Not yet due | 1–30 | 31–60 | 61–90 | 90+ | Total |
|---|---:|---:|---:|---:|---:|---:|
| 2026-06-20 | 4,000 + 5,000 = 9,000 | 0 | 6,000 | 10,000 | 12,000 | 37,000 |
| 2026-06-21 | 5,000 | 4,000 | 6,000 | 10,000 | 12,000 | 37,000 |
| 2026-07-19 | 0 | 4,000 + 5,000 = 9,000 | 6,000 | 10,000 | 12,000 | 37,000 |
| 2026-07-20 | 0 | 9,000 | 0 | 6,000 | 12,000 + 10,000 = 22,000 | 37,000 |
| 2026-07-21 | 0 | 5,000 | 4,000 | 6,000 | 22,000 | 37,000 |

### 3.8 Dashboard

**As of 2026-06-30:**
- Sales today: **0** (0 sales); sales this month (June 1–30): **96,000** (5 sales)
- Collections today: **0**; this month: **81,000**
- Total owed: **49,000**; overdue (past due on 2026-06-30): 12,000 + 10,000 + 6,000 + 4,000 = **32,000**
- Customer credit held: **2,000**
- Low-stock products: **3**
- Top 5 sellers this month by quantity: A 12, C 5, E 3, B 2

**As of 2026-06-15** (today and month-to-date; owed, credit and low stock are always current):
- Sales today: **10,000** (2 sales: s5 and s8; s7 at 23:30 the night before is not today); month to date (June 1–15): 76,000 + 7,000 + 10,000 = **93,000** (4 sales)
- Collections today: **0**; month to date: **76,000**
- Overdue on 2026-06-15: sales 1, 2, 3 (due before 06-15) = 12,000 + 10,000 + 6,000 = **28,000** (sale 4, due 06-20, is not yet overdue)

### 3.9 Customer statements (3.2)

**Events and signs** (positive = the customer owes):

| Event | When | Amount |
|---|---|---:|
| Invoice | sale `confirmed_at` | + total |
| Invoice void | sale `voided_at` | − total |
| Payment | `paid_at` | − amount |
| Payment void | payment `voided_at` | + amount |

Credit applications move money between a payment and an invoice and are not statement events (they change neither side). Drafts and cancelled sales never appear. **Opening balance** = sum of all events before `from` (00:00 Accra). **Lines** = events from `from` 00:00 to the end of `to`, ordered by time; at the **same timestamp**: invoices, then payments, then invoice voids, then payment voids; then by record id. **Running balance** = opening + lines so far; **closing** = opening + debits − credits, where debits are the positive lines and credits the negative lines (as positive numbers).

Every event in the dataset, by customer:

- **Alpha**: s6 06-10 11:00 +76,000; P2 06-12 12:00 −76,000; s7 06-14 23:30 +7,000; s8 06-15 00:30 +5,000; s9 06-16 09:00 +12,000; s9 void 06-16 15:00 −12,000; P4 06-25 12:00 −1,000; P4 void 06-25 13:00 +1,000.
- **Beta**: s1 02-02 10:00 +12,000; s2 03-20 10:00 +12,000; s3 04-20 10:00 +6,000; P1 05-10 12:00 −2,000; s4 05-20 10:00 +4,000; s5 06-15 10:00 +5,000.
- **Gamma**: s10 06-20 10:00 +3,000; P3 06-21 12:00 −5,000.

#### (a) June 2026 (2026-06-01 to 2026-06-30)

**Alpha**, opening **0** (no events before June):

| When | Line | Amount | Balance |
|---|---|---:|---:|
| 06-10 11:00 | Invoice s6 | +76,000 | 76,000 |
| 06-12 12:00 | Payment P2 | −76,000 | 0 |
| 06-14 23:30 | Invoice s7 | +7,000 | 7,000 |
| 06-15 00:30 | Invoice s8 | +5,000 | 12,000 |
| 06-16 09:00 | Invoice s9 | +12,000 | 24,000 |
| 06-16 15:00 | Void of invoice s9 | −12,000 | 12,000 |
| 06-25 12:00 | Payment P4 | −1,000 | 11,000 |
| 06-25 13:00 | Void of payment P4 | +1,000 | 12,000 |

Debits 76,000 + 7,000 + 5,000 + 12,000 + 1,000 = **101,000**; credits 76,000 + 12,000 + 1,000 = **89,000**; closing 0 + 101,000 − 89,000 = **12,000**.

**Beta**, opening = 12,000 + 12,000 + 6,000 − 2,000 + 4,000 = **32,000**:

| When | Line | Amount | Balance |
|---|---|---:|---:|
| 06-15 10:00 | Invoice s5 | +5,000 | 37,000 |

Debits **5,000**; credits **0**; closing **37,000**.

**Gamma**, opening **0**:

| When | Line | Amount | Balance |
|---|---|---:|---:|
| 06-20 10:00 | Invoice s10 | +3,000 | 3,000 |
| 06-21 12:00 | Payment P3 | −5,000 | −2,000 |

Debits **3,000**; credits **5,000**; closing **−2,000** (the customer is owed 2,000: its credit).

#### (b) January to June 2026 (2026-01-01 to 2026-06-30)

**Alpha** and **Gamma**: no events before June, so identical to (a): opening 0; Alpha closing **12,000** (8 lines, debits 101,000, credits 89,000); Gamma closing **−2,000** (2 lines, debits 3,000, credits 5,000).

**Beta**, opening **0**:

| When | Line | Amount | Balance |
|---|---|---:|---:|
| 02-02 10:00 | Invoice s1 | +12,000 | 12,000 |
| 03-20 10:00 | Invoice s2 | +12,000 | 24,000 |
| 04-20 10:00 | Invoice s3 | +6,000 | 30,000 |
| 05-10 12:00 | Payment P1 | −2,000 | 28,000 |
| 05-20 10:00 | Invoice s4 | +4,000 | 32,000 |
| 06-15 10:00 | Invoice s5 | +5,000 | 37,000 |

Debits 12,000 + 12,000 + 6,000 + 4,000 + 5,000 = **39,000**; credits **2,000**; closing **37,000**.

#### (c) Mid-dataset: 2026-06-21 to 2026-06-30 (every opening balance non-zero)

**Alpha**, opening = 76,000 − 76,000 + 7,000 + 5,000 + 12,000 − 12,000 = **12,000**:

| When | Line | Amount | Balance |
|---|---|---:|---:|
| 06-25 12:00 | Payment P4 | −1,000 | 11,000 |
| 06-25 13:00 | Void of payment P4 | +1,000 | 12,000 |

Debits **1,000**; credits **1,000**; closing **12,000**.

**Beta**, opening = 32,000 + 5,000 = **37,000**; no lines; debits 0, credits 0; closing **37,000**.

**Gamma**, opening = **3,000** (s10 on 06-20; P3 is 06-21 12:00, inside the period):

| When | Line | Amount | Balance |
|---|---|---:|---:|
| 06-21 12:00 | Payment P3 | −5,000 | −2,000 |

Debits **0**; credits **5,000**; closing **−2,000**.

#### (d) Timezone boundary (Alpha)

- **2026-06-15 to 2026-06-15**: opening = 76,000 − 76,000 + 7,000 (s7 at 06-14 23:30 is the day before) = **7,000**; one line, s8 at 00:30 +5,000 → **12,000**.
- **2026-06-01 to 2026-06-14**: opening 0; lines s6, P2, s7 (23:30 is still the 14th); closing **7,000**; s8 is not included.
- **Midnight exactly** (separate test; customer Echo, not in the fixture): one invoice, A×1 = **5,000**, confirmed at **2026-07-02 00:00:00**. Statement 2026-07-02 to 07-02: opening **0** (the event is not *before* `from`), one line +5,000, closing **5,000**. Statement 2026-07-01 to 07-01: opening 0, no lines, closing **0**. Counting it in the opening as well would give 10,000.

#### (e) Same-timestamp ordering (separate test; customer Delta, not in the fixture)

Everything at **2026-07-01 10:00:00**, recorded in this order: payment Q of 4,000 (no open invoice, so all of it is credit), then sale X (A×2 = 10,000) confirmed, then payment Q voided, then sale X voided. Statement for 2026-07-01, opening 0, lines in rule order (not recording order):

| Line | Amount | Balance |
|---|---:|---:|
| Invoice X | +10,000 | 10,000 |
| Payment Q | −4,000 | 6,000 |
| Void of invoice X | −10,000 | −4,000 |
| Void of payment Q | +4,000 | 0 |

Closing **0** = Delta's outstanding 0 − credit 0. (In recording order the balances would read −4,000, 6,000, 10,000, 0.)

#### Property: closing = outstanding − credit

For a period that ends after the last event (to = 2026-12-31): Alpha 12,000 − 0 = **12,000**; Beta 37,000 − 0 = **37,000**; Gamma 0 − 2,000 = **−2,000**. These match the closing balances above.

### 3.10 Stock-take (3.3)

**Rules.** A count lists products (all active, or a filter). Entering a counted quantity records, at that moment, `system_qty` = the product's `stock_on_hand`, `baseline_movement_id` = its latest movement, and `variance` = counted − system. Re-entering recomputes all three. Applying (owner, once) turns each **non-zero variance of a counted item** into **one** `count_adjustment` movement of exactly that variance, so whatever happened between counting and applying (sales, receipts) is kept. Uncounted items and zero variances write nothing. If any adjustment would leave stock below 0 while `allow_negative_stock` is off, nothing at all is applied (`count_conflict`). Variance value = variance × the product's cost price at apply (stored on the item as `unit_cost`).

Both scenarios start from the end of the dataset (section 1): stock **A 86, B 44, C 34, D 20, E −1, F 9**; costs A 3,000, B 2,500, C 3,600, D 1,800, E 500, F 1,000; `allow_negative_stock` off. All times 2026-07-01, Accra.

#### Count K1: scenarios (1), (2), (3), (5), (6)

- **09:00** count created for all active products: 6 items A–F, reference **CNT-2026-000001**, status open.
- **10:00** counted: A **84**, B **44**, C **30**, D **21**, F **8**. E is not counted.

  | Item | system_qty | counted | variance |
  |---|---:|---:|---:|
  | A | 86 | 84 | **−2** |
  | B | 44 | 44 | **0** |
  | C | 34 | 30 | **−4** |
  | D | 20 | 21 | **+1** |
  | F | 9 | 8 | **−1** |
  | E | (null) | (null) | (null) |

- **11:00** sale to Alpha, confirmed: C×2, F×3. Stock C 34 − 2 = **32**, F 9 − 3 = **6**.
- **11:30** C re-entered as **31**: (3) system_qty is now **32** (the balance after the sale), variance 31 − 32 = **−1** (not −4 any more, and not 31 − 34 = −3), baseline = the C `sale_out` movement of the 11:00 sale.
- **17:00** applied. Stock just before: A 86, B 44, C 32, D 20, E −1, F 6.

  | Product | Before | Adjustment (= variance) | `balance_after` = after |
  |---|---:|---:|---:|
  | A | 86 | −2 | **84** |
  | B | 44 | none (variance 0) | **44** |
  | C | 32 | −1 | **31** |
  | D | 20 | +1 | **21** |
  | E | −1 | none (uncounted) | **−1** |
  | F | 6 | −1 | **5** |

  **4** `count_adjustment` movements (A, C, D, F), each referencing the count.

  (1) **F**: counted 8 at 10:00 (variance −1), then 3 sold. Applied: 9 − 3 − 1 = **5**. The sale is preserved; setting stock to the counted 8 would erase it, and applying counted − current (8 − 6 = +2) would invent stock.
  (2) **E** is untouched: still −1, no movement, its item has no system_qty, counted_qty or variance.
  (6) **Variance at cost**: A −2 × 3,000 = −6,000; C −1 × 3,600 = −3,600; D +1 × 1,800 = +1,800; F −1 × 1,000 = −1,000. Units −2 − 1 + 1 − 1 = **−3**; losses 6,000 + 3,600 + 1,000 = **10,600**; gains **1,800**; net **−8,800**. Items counted **5** of **6**.
- **17:05** (5) applied again: **409**, code `stock_count_not_open`; still 4 movements, stock unchanged. Entering a count on the applied count is also 409.
- `stock:reconcile` afterwards: clean.

#### Count K2: scenario (4), refused

- **09:00** count created (all active, **CNT-2026-000001** in its own test).
- **10:00** counted: D **22** (system 20, variance **+2**), F **0** (system 9, variance **−9**).
- **11:00** sale F×1: F **8**.
- **17:00** apply: F would be 8 − 9 = **−1** < 0 with `allow_negative_stock` off. **Refused, 409 `count_conflict`**, `details.items` = [{RPT-F, stock_on_hand **8**, variance **−9**, resulting **−1**}]. **Nothing written**: D still **20** (its valid +2 is not applied either), F still **8**, no `count_adjustment` movement, count still open, `applied_at` null.
- With `allow_negative_stock` turned on, the same apply succeeds: D **22**, F **−1**, 2 movements; value +2 × 1,800 − 9 × 1,000 = 3,600 − 9,000 = **−5,400**.

## 4. Invariants also asserted

- Voided sale 9 and voided payment P4 appear in no figure.
- Aging grand total = sum of customers' `outstanding_balance`.
- Valuation totals = sum of the product rows.
- Timezone boundary: sale 7 (23:30) belongs to 2026-06-14 and the week of 06-08; sale 8 (00:30) to 2026-06-15 and the week of 06-15.
- Money invariants (`customers:reconcile`) hold after the fixture.

## 5. Phase 3.4: the figures through the clients

**Status: PASS** (2026-10-06). Admin (5.1), phone (5.2 a to e) and apply plus reconcile (5.2 f) all pass; tagged `phase-3-complete`. Exact output in 5.4.

### 5.1 Admin (Filament, Livewire): `tests/Feature/Filament/ReportsAndStockTakeTest.php`

Run on the dataset of section 1, every figure as a literal from sections 3.1 to 3.10:

| Screen | Checked |
|---|---|
| Sales summary page | June by week: 06-08 gross 870.00, discounts 40.00, revenue 830.00, collections 760.00; 06-15 130.00 / 50.00; totals 1,000.00 / 40.00 / 960.00 / 810.00. Level P4 filter: note shown, gross and revenue 700.00, discounts and collections "—". |
| Profit page | June by product: Maths P4 12, 600.00, 360.00, 240.00; gross 395.00, order-level discounts −40.00, net 355.00. Jan–Jun by period: Feb 120.00 / 70.00 / 50.00; net 489.00. |
| Best sellers page | By subject: Mathematics 20, 930.00; Science 2, 70.00. |
| Stock valuation page | 193 units, 5,354.00 at cost, 8,880.00 at price; negative-stock products 1. |
| Low stock page | C (34/40, short 6, Low), A (86/90, 4, Low), E (−1/0, 1, Out of stock). |
| Dead stock page | 90 days: D (never, 360.00), F (2026-02-02, 148 days, 90.00), cut-off 2026-04-01. 15 days: D, F, C (2026-06-10, 20), B (2026-06-14, 16), 2,774.00. |
| Receivables aging page | "Buckets as of 2026-06-30"; Alpha 120.00 not yet due; Beta 50.00 / 40.00 / 60.00 / 100.00 / 120.00 = 370.00; totals 170.00 / 40.00 / 60.00 / 100.00 / 120.00 = 490.00; no Gamma. |
| Dashboard widgets (as of 2026-06-30) | Sales today 0.00 (0 sales); this month 960.00 (5); collections today 0.00, month 810.00; owed 490.00, overdue 320.00; credit 20.00; low stock 3. Chart: 30 days from 06-01 summing to 960.00. Who owes most: Beta, Alpha (no Gamma). Low-stock table: C, A, E. |
| Stock-take resource (count K1) | Created CNT-2026-000001 with 6 items; inline entry A 84, B 44, C 30, D 21, F 8; sale C×2, F×3; C re-entered 31 (system 32, variance −1); "With a variance" filter: A, C, D, F. Apply confirmation: "5 of 6 products counted; 4 stock adjustments of -3 units. Net variance at cost GHS -88.00 (losses GHS 106.00, gains GHS 18.00)". Applied: A 84, B 44, C 31, D 21, E −1, F 5; 4 movements; entries closed; sheet downloads. |
| Customer "Statement" action | Gamma, June: downloads `statement-{code}-2026-06-01-2026-06-30.pdf`; "to" before "from" is refused. |

Result: **7 tests, 89 assertions, PASS**.

### 5.2 Phone (Pixel_9a): `flutter/integration_test/phase3_acceptance_test.dart`

Dataset: section 1, loaded into `schoolbook_test` by `php artisan test --group=phase3-phone --exclude-group=none` (migrate:fresh on `mysql_testing` only, then the fixture). The run date D must be on or after 2026-08-01, so that no dataset sale falls on D or in its month and every open invoice (due dates up to 2026-07-15) is past due. Hand-calculated:

**a. Dashboard cards on D:** sales today GHS 0.00 (0 sales); this month GHS 0.00 (0 sales); collected today GHS 0.00, month GHS 0.00; owed **GHS 490.00** (12,000 + 37,000); overdue **GHS 490.00** (all seven open invoices are due by 07-15: s1 12,000, s2 10,000, s3 6,000, s4 4,000, s5 5,000, s7 7,000, s8 5,000); credit **GHS 20.00** (Gamma); low stock **3**.

**b. Who owes most** (from the owed card): total GHS 490.00; Beta first, overdue GHS 370.00, owes GHS 370.00; Alpha, overdue GHS 120.00, owes GHS 120.00; Gamma absent. (The "over 90 days" part depends on D: on 2026-10-06 Beta has 32,000 over 90 days, s5 being 83 days past due; not asserted.)

**c. Low stock:** Maths JHS1 (RPT-C) 34 left, reorder at 40, short 6; Maths P4 (RPT-A) 86 left, reorder at 90, short 4; Maths P4 Workbook (RPT-E) −1 left, out of stock.

**d. Statement:** Beta, 2026-01-01 to 2026-06-30, shared from customer detail as `statement-{code}-2026-01-01-2026-06-30.pdf` (a PDF); its figures as in 3.9 (b): opening 0, balances 12,000, 24,000, 30,000, 28,000, 32,000, 37,000; debits 39,000, credits 2,000, closing 37,000.

**e. Stock-take entry:** new count CNT-2026-000001 (0 of 6 counted); entered A 84, B 44, C 30, D 21, F 8 (E not counted). Nothing is sold during the run, so system quantities are the dataset's: variances A 84 − 86 = **−2**, B **0**, C 30 − 34 = **−4**, D 21 − 20 = **+1**, F 8 − 9 = **−1**. Progress **5 of 6 counted**; units −2 − 4 + 1 − 1 = **−6**; value at cost −2 × 3,000 − 4 × 3,600 + 1 × 1,800 − 1 × 1,000 = −6,000 − 14,400 + 1,800 − 1,000 = **−19,600** ("GHS -196.00"). The variances filter lists, by SKU (the API's item order), A (system 86, 84, −2), C (34, 30, −4), D (20, 21, +1), F (9, 8, −1). The phone shows "Applying the count is done from the web admin." and has no apply button.

**f. Apply on the web, then reconcile:** applying the count gives A **84**, B **44**, C **30**, D **21**, E **−1**, F **8**, four `count_adjustment` movements; `customers:reconcile` and `stock:reconcile` both clean.

### 5.3 Procedure for the phone run

1. `cd laravel; php artisan test --group=phase3-phone --exclude-group=none` (loads the dataset into `schoolbook_test`).
2. A server on **port 8000** serving `schoolbook_test`: `$env:DB_DATABASE='schoolbook_test'; php artisan serve --port=8000` (process environment wins over `.env`). Port 8000 is the owner's dev server's port, so that server is stopped first, by the owner; nothing touches the dev database.
3. `cd flutter; flutter test integration_test/phase3_acceptance_test.dart -d emulator-5554 | Tee-Object run.txt` (with `--dart-define=OWNER_PASSWORD=...` if `.env` sets one).
4. Apply the count from the web admin (Inventory → Stock-takes → CNT-2026-000001 → Apply count), then `$env:DB_DATABASE='schoolbook_test'; php artisan customers:reconcile; php artisan stock:reconcile`.
5. Stop the test server; the owner restarts the dev server.

### 5.4 Run record (2026-10-06, Pixel_9a `emulator-5554`)

Server: the owner's `composer run dev` was stopped (owner's choice: "You stop and restart it"); `$env:DB_DATABASE='schoolbook_test'; php artisan serve --port=8000`. Checked before the run that this server served the throwaway database: `/customers` listed only Alpha School, Beta School and Gamma Academy, and `/reports/dashboard` gave owed 49000, overdue 49000, credit 2000, low stock 3.

Dataset: `php artisan test --group=phase3-phone --exclude-group=none` → `1 passed (6 assertions)` (reloaded before the final run).

Phone, `flutter test integration_test/phase3_acceptance_test.dart -d emulator-5554`:

```
ACCEPTANCE: 1 card_sales_today: Sales today | GHS 0.00 | 0 sales
ACCEPTANCE: 1 card_sales_month: This month | GHS 0.00 | 0 sales
ACCEPTANCE: 1 card_collections: Collected today | GHS 0.00 | Month GHS 0.00
ACCEPTANCE: 1 card_owed: Owed to you | GHS 490.00
ACCEPTANCE: 1 card_overdue: Overdue | GHS 490.00
ACCEPTANCE: 1 card_credit: Credit held | GHS 20.00
ACCEPTANCE: 1 card_low_stock: Low stock | 3 | products
ACCEPTANCE: 2 who owes most: Total owed | GHS 490.00 | Beta School | Overdue GHS 370.00 (over 90 days GHS 320.00) | GHS 370.00 | Alpha School | Overdue GHS 120.00 | GHS 120.00
ACCEPTANCE: 3 low stock: Maths JHS1 | RPT-C | reorder at 40 | 34 left | Short 6 | Maths P4 | RPT-A | reorder at 90 | 86 left | Short 4 | Maths P4 Workbook | RPT-E | reorder at 0 | -1 left | Out of stock
ACCEPTANCE: 4 statement shared: statement-CUS-6636-2026-01-01-2026-06-30.pdf, 878831 bytes, starts %PDF
ACCEPTANCE: 4b Beta statement: opening 0, balances [12000, 24000, 30000, 28000, 32000, 37000], totals {debits: 39000, credits: 2000}, closing 37000
ACCEPTANCE: 5 count opened: CNT-2026-000001, 0 of 6 counted
ACCEPTANCE: 5b 5 of 6 counted; Variance so far: -6 units, GHS -196.00 at cost
ACCEPTANCE: 5c variances: Maths P4 | RPT-A | system 86 | 84 | -2 | Maths JHS1 | RPT-C | system 34 | 30 | -4 | Science JHS1 | RPT-D | system 20 | 21 | +1 | Science JHS1 Workbook | RPT-F | system 9 | 8 | -1
ACCEPTANCE: 5d done: apply CNT-2026-000001 on the web admin, then run both reconcile commands
00:56 +1: All tests passed!
```

Beta's "over 90 days GHS 320.00" is the 2026-10-06 value predicted in 5.2 b (s1 to s4; s5 is 83 days past due).

Apply (5.2 f), with the action the admin's "Apply count" button calls (`ApplyStockCount`, run through `php artisan tinker` with `DB_DATABASE=schoolbook_test`: I cannot click the browser; the button itself is covered by 5.1):

```
database: schoolbook_test
before: {"items":6,"counted":5,"variance_units":-6,"losses":21400,"gains":1800,"variance_value":-19600}
status: applied, applied_at: 2026-10-06 08:40:48
stock: {"RPT-A":84,"RPT-B":44,"RPT-C":30,"RPT-D":21,"RPT-E":-1,"RPT-F":8}
count_adjustment movements: [["RPT-A",-2,84,3000],["RPT-C",-4,30,3600],["RPT-D",1,21,1800],["RPT-F",-1,8,1000]]
--- customers:reconcile
All money invariants hold.
exit 0
--- stock:reconcile
Stock matches the movements.
exit 0
```

Then the test server was stopped and the owner's `composer run dev` restarted (server, queue, Vite; port 8000) without the database override.

**Attempts before the passing run** (all recorded, none changed a figure):

1. Timed out waiting for the dashboard after login: the phone's login took 35 s and hit PHP's 30-second limit (`Maximum execution time of 30 seconds exceeded`); my own login a minute earlier took 3 s.
2. Same wait timed out: login took 25 s and the dashboard request queued behind it on the single-threaded `php artisan serve`. The test's network waits went from 30 s to 90 s. Steps 1 to 4 then passed.
3. Step 5 read the progress text through a helper that looks *under* a key; the key is on the `Text` itself (test bug, fixed).
4. Every figure matched, but my expected order of the variance rows was wrong: the API lists count items by SKU (documented in docs/api.md), not C first. Corrected here (5.2 e) and in the test; the dataset was reloaded and the run repeated.

Observations for later phases: a login from the emulator takes 25-35 s on `php artisan serve` on this machine (single-threaded; not seen from the host); a 6-line statement PDF is 879 KB because DomPDF embeds the whole DejaVu font (font subsetting is worth turning on before statements are shared over WhatsApp).

