# Phase 3.A acceptance: approved list (NaCCA)

**Status: in progress, not yet PASS.** Steps 1 to 3 are done and recorded below. Steps 4 and 5 wait for the owner's first review of the flagged rows on the dev database. `phase-3a-complete` is tagged only when every step passes.

Copyright note: this file is committed, and the NaCCA list must not be reproduced, so rows are identified by page, section and serial number rather than by title.

## 1. Import of the real list (dev database `schoolbook`)

File: `laravel/storage/app/reference/nacca-2024-12.pdf` (git-ignored), "List of Approved Standards-Based Curriculum (SBC) Textbooks for Kindergarten to Junior High School and Supplementary Learning Materials", © December 2024, 51 pages, SHA-256 `01f1b4ea6a3fc93c04666a9b719538427c58fe053930fc3dfb177f2f5c29619c`.

```
php artisan reference:import storage/app/reference/nacca-2024-12.pdf --edition="NaCCA December 2024" --published=2024-12-01 --source-url="https://nacca.gov.gh/wp-content/uploads/2025/01/NaCCA_List_of_Approved_Instructional_Resources_Textbooks_and_Supplementary.pdf"

Staged "NaCCA December 2024" (#1) for review.
| Rows | New  | Unchanged | Changed | Removed | With issues | Skipped |
| 1570 | 1570 | 0         | 0       | 0       | 83          | 2       |
  skipped: page 8, NUMERACY/MATHEMATICS #42 (blank)
  skipped: page 19, OUR WORLD AND OUR PEOPLE #30 (blank)
```

Result: **PASS** (staged as edition #1, status draft; nothing live).

## 2. Counts against NaCCA's own statistics table (PDF section 2)

| Section | Stated in the PDF | Read | Difference |
|---|---:|---:|---|
| Language & Literacy / English Language | 56 | 56 | — |
| Numeracy / Mathematics | 137 | 136 | −1: serial 42 on page 8 is blank in the PDF (numbered, no title); counted by NaCCA, reported as skipped |
| Science | 156 | 156 | — (Science has a table but no line in the PDF's contents page) |
| Creative Arts | 32 | 32 | — |
| Ghanaian Language | 21 | 21 | — |
| History of Ghana | 81 | 81 | — |
| Our World and Our People | 40 | 39 | −1: serial 30 on page 19 is blank in the PDF; reported as skipped |
| Religious and Moral Education | 66 | 66 | — |
| Computing | 51 | 51 | — |
| Physical Education and Health | 9 | 9 | — |
| French | 32 | 32 | — |
| Career Technology | 10 | 10 | — |
| **Textbooks** | **691** | **689** | −2, both blank rows |
| Subject-based supplementary materials | 535 | 535 | — (KG 175, Lower Primary 168, Upper Primary 139, JHS 12, SHS 41) |
| Readers (story books) | 285 | 285 | — |
| Guidance and counselling | 21 | 21 | — |
| E-learning / games / manipulatives / manuals / others | 40 | 40 | — |
| **All** | **1,572** | **1,570** | −2, both blank rows |

Result: **PASS**. Every difference is explained by a row NaCCA numbered and counted but left blank.

## 3. Hand check of 25 random rows

Sample: 25 staged rows drawn with a fixed seed (`mt_srand(20261005)`) from the 1,570, each compared by hand with the PDF's own plain text around that serial number (extracted independently of the positional parser). Checked: title, level (textbooks) or band (supplements), author/publisher split, and the mapped subject and language.

| # | Page | Section | Serial | Parsed exactly as printed | Mapping |
|---:|---:|---|---:|---|---|
| 1 | 6 | English textbooks | 27 | yes | ok |
| 2 | 6 | English textbooks | 32 | yes | ok |
| 3 | 8 | Mathematics textbooks | 10 | yes | ok |
| 4 | 12 | Science textbooks | 63 | yes (title, level and publisher each wrapped over lines) | ok |
| 5 | 15 | Creative Arts textbooks | 7 | yes (wrapped) | ok |
| 6 | 19 | Our World and Our People | 11 | yes (double space collapsed) | ok |
| 7 | 20 | RME textbooks | 23 | yes | ok |
| 8 | 23 | Computing textbooks | 48 | yes | ok |
| 9 | 27 | Supplements, KG | 46 | yes (authors and publisher split at "/") | ok |
| 10 | 29 | Supplements, KG | 105 | yes (publisher typo kept as printed; merge suggested in review) | ok |
| 11 | 31 | Supplements, Lower Primary | 2 | yes | ok |
| 12 | 32 | Supplements, Lower Primary | 35 | yes | ok |
| 13 | 32 | Supplements, Lower Primary | 57 | yes | ok |
| 14 | 35 | Supplements, Lower Primary | 127 | yes (NaCCA lists a book 6 under Lower Primary; kept as printed) | ok |
| 15 | 35 | Supplements, Lower Primary | 146 | yes | ok |
| 16 | 38 | Supplements, Upper Primary | 62 | yes | ok (French, language French) |
| 17 | 38 | Supplements, Upper Primary | 88 | yes | ok |
| 18 | 41 | Supplements, SHS | 13 | yes | ok |
| 19 | 43 | Readers | 37 | yes | ok |
| 20 | 44 | Readers | 72 | yes | ok |
| 21 | 44 | Readers | 77 | yes | ok |
| 22 | 45 | Readers | 105 | yes | ok |
| 23 | 46 | Readers | 158 | yes | ok |
| 24 | 50 | E-learning | 14 | yes (wrapped) | ok |
| 25 | 50 | E-learning | 35 | yes | **language wrong**: a Dagbani title, defaulted to English with *high* confidence |

Result: parsing **25/25**; mapping **24/25**.

Finding and fix: a supplementary or e-learning title in a Ghanaian language that no keyword recognises defaulted to English and was marked high confidence, so the review page's "low confidence" filter would not surface it. Fixed in `ReferenceMapper` (3.A.3): a language that is only *defaulted* to English on a non-textbook row is now low confidence (textbook sections are English unless they say otherwise). Test: `a non-textbook title with no language keyword defaults to English with low confidence`. The draft already staged (edition #1) was left untouched so the owner's review is not disturbed. Row 25 can be corrected there with **Fix** (language Dagbani); later imports get the new rule.

## 4. Owner's first review and publish (dev database)

Backup before publishing (owner, 2026-10-05): `php artisan backup:run --only-db`, 103.66 KB, verified.

**First publish (owner, 2026-10-05 17:18):**

```
Approved list published
1487 added, 0 updated, 0 unchanged, 0 withdrawn, 251 publishers added. 79 undecided and 4 excluded rows were left out.
```

Checked on the database afterwards: edition #1 active; 1,487 approved titles, 251 publishers and aliases; the Dagbani row (page 50, e-learning, serial 35) fixed to Dagbani; the 4 duplicates excluded (page 13 #138; page 50 #12, #36, #39); `customers:reconcile`: "All money invariants hold."

The 79 undecided rows are all the warning rows: 34 publisher spellings, 9 Physical Education, 32 supplements with no subject, 3 generic "Twi" titles, 1 odd author/publisher separator. They were left out because the bulk accept skips rows with issues and the publish confirmation's mention was easy to miss. Finding: a published edition is read-only, so they could no longer be decided. Fixed by `reference:import --again` plus a capitalised warning in the publish confirmation (see `docs/decisions.md`).

**Completion review:** PENDING (owner). Re-import with `--again`, accept all unchanged, decide the 79, publish. To record: the summary.

## 5. Phone acceptance (Pixel_9a, dev server): PENDING (after step 4)

Planned script, against the owner-reviewed list:

1. Log in; the approved list downloads (status line shows the edition and title count). Turn networking off, search "maths p4" and a publisher name: results come from the phone's copy, with an "Offline" note. Networking back on.
2. Add 10 products from the list (*Products > Add from approved list*): at least one band-only title (class chosen), one with a variant, one with opening stock, one with a scanned or typed code. Each shows "In stock N" afterwards.
3. Receive stock through the list: search a title not carried yet, add it from the "On the approved list" section, receive it with the rest of a receipt.
4. Scan-attach: an unknown code, attached to one of the products; scanning it again opens that product.
5. Server checks: products linked (`reference_book_id`), one goods receipt for step 3, `customers:reconcile` clean, every product's `stock_on_hand` equal to the sum of its movements.

## Result

Not yet tagged. Steps 1 to 3 PASS; steps 4 and 5 pending.
