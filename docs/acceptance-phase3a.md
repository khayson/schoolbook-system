# Phase 3.A acceptance: approved list (NaCCA)

**Status: PASS** (2026-10-05). All five steps pass; tagged `phase-3a-complete`.

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

## 4. Owner's first review and publish (dev database): PASS

Backup before publishing (owner, 2026-10-05): `php artisan backup:run --only-db`, 103.66 KB, verified.

**First publish (owner, 2026-10-05 17:18):**

```
Approved list published
1487 added, 0 updated, 0 unchanged, 0 withdrawn, 251 publishers added. 79 undecided and 4 excluded rows were left out.
```

Checked on the database afterwards: edition #1 active; 1,487 approved titles, 251 publishers and aliases; the Dagbani row (page 50, e-learning, serial 35) fixed to Dagbani; the 4 duplicates excluded (page 13 #138; page 50 #12, #36, #39); `customers:reconcile`: "All money invariants hold."

The 79 undecided rows are all the warning rows: 34 publisher spellings, 9 Physical Education, 32 supplements with no subject, 3 generic "Twi" titles, 1 odd author/publisher separator. They were left out because the bulk accept skips rows with issues and the publish confirmation's mention was easy to miss. Finding: a published edition is read-only, so they could no longer be decided. Fixed by `reference:import --again` plus a capitalised warning in the publish confirmation (see `docs/decisions.md`).

**Completion review (owner, 2026-10-05 17:38):** `reference:import ... --edition="NaCCA December 2024 (completion)" --again` staged edition #2: 1,490 unchanged (the live titles plus the 4 duplicate copies), 79 new (the rows left out), 1 changed (the Dagbani row, back to English because the fix lives on the title). The owner accepted all unchanged, excluded the 4 duplicates, merged the publisher spellings, accepted the Physical Education, no-subject and separator rows and the 3 "Twi" titles (without a language: the phone asks for it when one is added as a product), and left the Dagbani "changed" row undecided so the live title keeps Dagbani.

```
Approved list published
79 added, 0 updated, 1486 unchanged, 0 withdrawn, 15 publishers added. 1 undecided and 4 excluded rows were left out.
```

Checked on the database: edition #2 active, #1 superseded; **1,566 approved titles** (= 1,570 read − 4 duplicates), 0 withdrawn; 266 publishers, 272 spellings kept as aliases; subject "Physical Education and Health" created with its 9 titles; the Dagbani title still Dagbani; `customers:reconcile`: "All money invariants hold."

Result: **PASS**.

## 5. Phone acceptance (Pixel_9a, dev database): PASS

Setup: Pixel_9a emulator (`emulator-5554`, cold boot), app debug build, `flutter test integration_test/phase3a_acceptance_test.dart -d emulator-5554 --dart-define=API_BASE_URL=http://10.0.2.2:8001/api/v1`. API: a second `php artisan serve --port=8001` on the **dev database** with the owner-published list (the owner's own dev server on port 8000 was not touched). A host script watched that server's log: when the test requested `GET /acceptance-go-offline` it stopped the server for 30 seconds and started it again, so "offline" is a real unreachable server, not a fake.

**Attempts** (at most two retries for test-script faults; no expected figure changed):

1. Failed after step 3: the test opened the quick-create as a nested route and waited for the product page, but the app (correctly) returns to the approved list it came from. Test changed to find the new product through the API. One product was created (BK-000001).
2. Failed at product 5: the test opened the class menu before the class list had loaded. Test changed to wait for the option. Products BK-000002 to BK-000005 and receipt GRN-2026-000001 were created.
3. **PASS** (retry 2), run 22880610, 2 min 12 s:

```
ACCEPTANCE: 1 logged in; approved list on the phone: 1566 titles, edition "NaCCA December 2024 (completion)", ETag "f6444d7f6a00a2cb3b03c14f504b2ad207102cee"
ACCEPTANCE: 2 online search "maths p4": 31 titles, all Primary 4
ACCEPTANCE: 2b re-sync: ETag unchanged ("f6444d7f6a00a2cb3b03c14f504b2ad207102cee"), list kept
ACCEPTANCE: 3 offline: screen says "Offline"; cold start loads 1566 titles from the phone; "maths p4" gives the same 31 titles; "science jhs 2" gives 7
ACCEPTANCE: 3b server back: online again, ETag "f6444d7f6a00a2cb3b03c14f504b2ad207102cee"
ACCEPTANCE: 4.1 added BK-000006 (title #3, variant Learner's Book) stock 0
ACCEPTANCE: 4.2 added BK-000007 (title #3, variant Teacher's Guide) stock 0
ACCEPTANCE: 4.3 added BK-000008 (title #58) stock 5
ACCEPTANCE: 4.4 added BK-000009 (title #194) stock 0 barcode 2900228806102
ACCEPTANCE: 4.5 added BK-000010 (title #820, listed for Lower Primary) stock 3 class Primary 2
ACCEPTANCE: 4.6-4.10 added BK-000011 to BK-000015 (titles #339, #371, #392, #473, #507) stock 0
ACCEPTANCE: 5 received 7 x BK-000016 (title #4) through the list (created from the approved list in the receipt)
ACCEPTANCE: 6 unknown code 2800228806105 attached to BK-000015; scanning it again opens that product
ACCEPTANCE: 6b the same code on another product: 409 duplicate_code ("Code 2800228806105 already belongs to ... (BK-000015).")
ACCEPTANCE: DONE run 22880610: products 6,7,8,9,10,11,12,13,14,15,16
+1: All tests passed!
```

(The test log prints the titles; they are replaced here by approved-title ids because this file is committed.)

Every product was checked through the API as it was created: linked to its title, cost 2,750 and price 104,000 pesewas as typed ("27.50", "1,040.00"), title as the list (with the variant in brackets), stock equal to the opening stock, the barcode stored, the class chosen.

**Server checks** after the run:

| Check | Result |
|---|---|
| Products linked to an approved title | 16 of 16 (11 from the passing run, 5 left from attempts 1 and 2) |
| `stock_on_hand` = sum of the product's stock movements | 16 of 16 |
| Last `balance_after` = `stock_on_hand` | 16 of 16 (0 differences) |
| Goods receipts | 4: three "Opening stock" (BK-000004 x5, BK-000008 x5, BK-000010 x3), and GRN-2026-000004 for the receive step (BK-000016 x7 at 3,000) |
| `customers:reconcile` | "All money invariants hold.", exit 0 |

**Rerun at the owner's request** (run 30021719, 2 min 28 s, PASS), with two added checks answering "different port, same data?":

- **Same database behind both ports.** The acceptance server (8001) and the owner's dev server (8000) are the same Laravel code reading the same `laravel/.env` (MySQL `127.0.0.1:3306`, database `schoolbook`); the port only chooses which server process answers. On the emulator `10.0.2.2` is the host computer. The test wrote BK-000017 through port 8001 and read it back through the owner's server on port 8000: same id, SKU, title and cost.
- **The offline copy equals the server's list.** Only the approved list (reference titles) is stored on the phone; products and stock are never created or stored offline (every product was created after the server was back). After reconnecting, the test downloaded the list fresh from the server, without the ETag: same ETag `"f6444d7f6a00a2cb3b03c14f504b2ad207102cee"` and the same 1,566 title ids, in the same order, as the copy used offline.

```
host:  19:52:21 api started · 19:54:11 offline signal seen: api stopped · 19:54:41 api started again
ACCEPTANCE: 3 offline: screen says "Offline"; cold start loads 1566 titles from the phone; "maths p4" gives the same 31 titles; "science jhs 2" gives 7
ACCEPTANCE: 3c phone copy used offline = server list: same ETag "f6444d7f6a00a2cb3b03c14f504b2ad207102cee", same 1566 title ids in the same order
ACCEPTANCE: 4.0 BK-000017 written through port 8001 is read back through the dev server on port 8000: same database
ACCEPTANCE: 4.1-4.10 added BK-000017 to BK-000026 (variants, opening stock, barcode, class for a Lower Primary title)
ACCEPTANCE: 5 received 7 x BK-000027 through the list
ACCEPTANCE: 6 unknown code 2800300217195 attached to BK-000026; scanning it again opens that product
ACCEPTANCE: 6b the same code on another product: 409 duplicate_code
+1: All tests passed!
```

Server checks after the rerun: 27 of 27 products linked, stock equal to movements for all 27, 0 balance differences, receipts GRN-2026-000005/6 (opening stock) and GRN-2026-000007 (receive step, 7 at 3,000), `customers:reconcile` clean.

**Final run on the owner's dev server only** (run 30777467, 2 min 12 s, PASS). At the owner's request no second server was started: the app used its default `http://10.0.2.2:8000/api/v1`, the owner's own `composer run dev` server, which kept running throughout (nothing listened on 8001). "Offline" is now the emulator's **airplane mode**: `flutter/scripts/phone-offline-window.ps1` watches the test output for `ACCEPTANCE-GO-OFFLINE`, turns airplane mode on for 30 s, then off. The server is never touched. This is the procedure from now on; the earlier runs above used a second server on 8001 and are kept for the record.

```
host:  20:05:11 watching test output · 20:06:34 airplane mode ON (phone offline) · 20:07:04 airplane mode OFF (phone online)
ACCEPTANCE: 1 logged in; approved list on the phone: 1566 titles, edition "NaCCA December 2024 (completion)", ETag "f6444d7f6a00a2cb3b03c14f504b2ad207102cee"
ACCEPTANCE: 2 online search "maths p4": 31 titles, all Primary 4
ACCEPTANCE: 2b re-sync: ETag unchanged, list kept
ACCEPTANCE: 3 offline: screen says "Offline"; cold start loads 1566 titles from the phone; "maths p4" gives the same 31 titles; "science jhs 2" gives 7
ACCEPTANCE: 3b online again, ETag "f6444d7f6a00a2cb3b03c14f504b2ad207102cee"
ACCEPTANCE: 3c phone copy used offline = server list: same ETag, same 1566 title ids in the same order
ACCEPTANCE: 4.1-4.10 added BK-000028 to BK-000037 (two variants of one title, opening stock 5, barcode 2900307774674, class Primary 2 for a Lower Primary title, five more)
ACCEPTANCE: 5 received 7 x BK-000038 through the list
ACCEPTANCE: 6 unknown code 2800307774677 attached to BK-000037; scanning it again opens that product
ACCEPTANCE: 6b the same code on another product: 409 duplicate_code
+1: All tests passed!
```

Server checks after this run: 38 of 38 products linked; stock equal to movements and 0 balance differences for all 38; receipts GRN-2026-000008/9 (opening stock) and GRN-2026-000010 (receive step, 7 at 3,000); `customers:reconcile` clean; airplane mode off again.

The test products stay in the dev database (owner agreed). They are ordinary products and can be deleted in the admin.

Result: **PASS**.

## Result

**PASS.** Steps 1 to 5 pass; tagged `phase-3a-complete`.
