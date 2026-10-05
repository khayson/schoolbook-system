# Approved list (reference catalog)

The approved list is NaCCA's list of textbooks and supplementary materials approved for pre-tertiary schools. It sits **next to** the shop's products and does not replace them. Products keep their own prices, costs and stock. A product can point to an approved title (`products.reference_book_id`, several products per title, for example a learner's book and a teacher's guide; `variant_label` tells them apart). Products for titles not on the list (SHS, stationery) still work and are simply "not on the list".

**Source:** NaCCA (nacca.gov.gh), the regulator that approves teaching and learning materials under Act 1023. GES pages re-post it. The December 2024 edition is "List of Approved Standards-Based Curriculum (SBC) Textbooks for Kindergarten to Junior High School and Supplementary Learning Materials".

**Copyright:** the document says no part may be reproduced without NaCCA's prior written permission. It is used as an internal lookup for the shop only. The PDF and anything imported from it are **never committed**: they live in `laravel/storage/app/reference/` (git-ignored) and in the database. Test fixtures are synthetic: invented titles laid out with the real geometry. Ask NaCCA (contact details are in the document) to confirm permission, and whether they can supply the list as a spreadsheet.

## Workflow

1. **Import** on the server (the file stays local):
   ```
   php artisan reference:import storage/app/reference/nacca-2024-12.pdf --edition="NaCCA December 2024" --published=2024-12-01 --source-url="https://nacca.gov.gh/..."
   ```
   Every row is parsed, mapped and compared with the live list, then lands in **staging** as a draft edition. The command prints the counts and any skipped rows. Nothing live changes.
2. **Review** in the admin: *Approved list > Imports > Review*. Rows needing attention come first: **Fix** (errors), **Check** (warnings), then rows still to decide.
   - Row actions: **Accept**, **Fix** (edit level, band, subject, language, author, publisher, title or category; the row is re-checked and counts as reviewed), **Use "…"** (merge a publisher spelling, see below), **Exclude** (for a removed title: **Keep on list**), **Undo**.
   - **Accept in bulk:** all unchanged rows, all new or changed rows without issues, all removals.
   - Rows with an **error** cannot be accepted until they are fixed or excluded.
3. **Publish:** a confirmation shows what will go live (new, changed, unchanged, withdrawn, publishers to add, rows left out). Only **accepted** rows are applied. Undecided and excluded rows are left out, and the previous live edition becomes *superseded*.
4. **Discard** a draft to start again. Its staged rows are deleted; the edition record stays in the history as *discarded*.

Only one draft can exist at a time, and the file that is already live cannot be imported again (same SHA-256).

## Parsing (`ReferenceListParser`)

The plain text of the PDF loses the column boundaries on about a quarter of the rows, so the parser works from **positioned text runs** (`smalot/pdfparser`, x/y of every run):

- **Rows:** a serial number ("12.") at the left (x < 80) starts a row. Everything below it, up to the next serial, heading or table header, belongs to it. Cells are vertically centred, so a wrapped row's title can sit below its serial. Wrapped lines are joined with spaces.
- **Columns:** the header labels ("TITLE OF MATERIAL", "LEVEL", "AUTHOR/PUBLISHER") are centred over their columns. A column's real left edge is therefore taken from the data: the most common x where text starts between two header labels, per page and table. A run at or right of the publisher edge is publisher text; in textbook tables, a run between the level edge and the publisher edge is the level.
- **Headings:** text at the left margin (x < 60), or a numbered section heading anywhere on the line ("4.0 – LIST OF …" is centred). Section 3 is textbooks (headings give the subject). Section 4.1 is subject-based supplements (headings give the level band); 4.2 readers, 4.3 guidance, 4.4 e-learning and others.
- **Skipped:** page numbers ("Page | N", y < 60), blank numbered rows (reported with page and section), front matter.
- **Statistics:** the tables in section 2 are captured (`reference_editions.stated_counts`) so an import can be checked against NaCCA's own numbers.
- **Level glued to the title** (a single run): the last level token of the title is used, and the row gets a warning.

## Mapping rules (`ReferenceMapper`)

| What | Rule |
|------|------|
| Textbook level | `KG 1-2` → KG 1-2; `Basic 1-6` (also `B4`, `Primary 4`) → **Primary 1-6**; `JHS 1-3` (also `JHS1`) → JHS 1-3. Anything else is an **error** (`unknown_level`). |
| Supplementary band | Heading → band: Creche/Nursery/Kindergarten `kg`, Lower Primary `lower_primary` (P1-3), Upper Primary `upper_primary` (P4-6), Junior High School `jhs`, Senior High School `shs`. The level is set only if the title names exactly one level inside the band ("… for Kindergarten 2", "Basic 3"; not "Primary 4C" or "Book 4"), with low confidence. Readers, guidance and e-learning have no level. |
| Textbook subject | Section heading → subject: Language and Literacy/English Language → English Language; Numeracy/Mathematics → Mathematics; Science; Creative Arts (and Design) → Creative Arts; Ghanaian Languages → Ghanaian Language; History of Ghana → History; Our World and Our People → Our World Our People; Religious and Moral Education → RME; Computing → Computing/ICT; French; Physical Education (and Health) → **Physical Education and Health**, created on publish if missing (warning `subject_missing`); Career Technology. |
| Supplementary subject | Keyword rules on the title, first match wins: French, Ghanaian language (Twi, Fante/Mfantse, Ewe, Dagbani, …), Computing, History, Our World Our People, RME, Career Technology, Social Studies, Creative Arts (drawing, colouring, mosaic, …), Science, Mathematics (maths, numeracy, numbers, fractions, …), English (phonics, reading, writing, handwriting, letters, …). Readers default to English. **Low confidence.** A subject-based supplement with no match gets a warning (`subject_unknown`). |
| Language | French textbooks → French. Otherwise a language named in the title (Asante/Akuapem Twi, Fante, Ewe, Ga, Dagbani, Dagaare, Gonja, Nzema, Kasem, Dangme, French; low confidence), else English. "Twi" alone is ambiguous: left blank with a warning (`language_unknown`). |
| Author / publisher | "Authors /Publisher" is split at the last "/". A slash at the very start or end gets a warning (`publisher_check`). No publisher at all is an **error** (`missing_publisher`). |
| Publisher spelling | Compared ignoring case, punctuation, "&"/"and", apostrophes, `Ltd/Limited/Company/Co/PLC/Inc/GH/Ghana/The` and simple plurals ("Stationeries" = "Stationery"). Equal spellings merge automatically. An unknown spelling at least 85% similar to a known publisher, or to a more common spelling in the same list, gets a "same publisher?" suggestion (`publisher_similar`); typos (a dropped or swapped letter) are never merged silently. |

**Confidence:** *high* when level, subject and language come from the list's structure; *low* when any of them was guessed from title keywords. The review page can filter on it. Low confidence alone is not an issue.

**Issues:** errors are `missing_publisher`, `unknown_level` and `duplicate` (same title, level and publisher as an earlier row). Warnings are `publisher_similar`, `publisher_check`, `subject_missing`, `subject_unknown`, `unknown_subject`, `unknown_band`, `language_unknown`, `level_from_title` and `continued_on_next_page`.

## Identity and editions

- **Natural key** (`reference_books.natural_key`) = SHA-1 of category, normalized title, level (id, or band), and publisher (id once known; until then, the normalized spelling). Renaming a publisher does not change its titles' keys.
- **Diff** against the live list (all reference books): same key with the same title, level label, band, subject, language, author and printed publisher means *unchanged*; any of those different means *changed* (the review page shows old → new). A key not on the live list is *new*. A live, approved title missing from the new list is *removed*. The serial number is not compared, because it changes whenever the list is renumbered.
- **Publishing** creates new titles, updates changed ones, records `last_seen_edition_id` on unchanged ones, and sets removed ones to **withdrawn**. Nothing is ever deleted: products linked to a withdrawn title keep working, and a withdrawn title that reappears is approved again (shown as *changed: status*).
- **Publishers:** spellings that match no publisher become one new publisher per spelling group, named after the group's most common spelling. Every spelling seen, including a printed one merged away during review (`reference_import_rows.printed_publisher`), is stored in `publisher_aliases`, so the next edition resolves it without asking.
- **ISBNs:** the December 2024 list has none (older editions had them). `reference_books.isbn` is filled over time by scanning barcodes (Phase 3.A.2/3.A.3).

## Using the list in the shop (3.A.2)

**Add one title** (admin, *Approved list > Approved titles > Add to my products*, or *Coverage > Not in my products*): a form prefilled with the title's level, subject, language and publisher. The owner adds a variant if they stock more than one version ("Learner's Book", "Teacher's Guide"), cost, price and opening stock. A title listed only for a band (for example "Lower Primary") must be given a class. The product is linked to the title; the SKU is generated when left blank (`BK-000123`); opening stock is received through the stock ledger at the cost price.

**Add many** (*Approved titles*, select rows, *Create products (CSV template)*): downloads a CSV with `reference_book_id, title, variant_label, level, subject, language, publisher, sku, cost, price, opening_stock`, already filled with each title's details. Fill in cost, price and opening stock (copy a line per variant; delete lines you do not want), then upload it in *Products > Import CSV*. Blank catalog columns are taken from the approved title; one bad row and nothing is created.

**API:** `POST /products` with `reference_book_id` (same prefill rules; explicit values win), `GET /reference-books` (search and filters), `GET /reference-books/snapshot` (the whole list for offline search, gzip + ETag), `GET /reference-editions/active`, `POST /products/attach-code`. See `docs/api.md`.

**Prefill rules:** title, level, subject, language and publisher come from the title when not given. The title gets the variant in brackets. Missing level (band-only titles), subject or language must be chosen. Linking a product to a withdrawn title is allowed (the badge shows "Withdrawn").

**Scan to learn codes** (`POST /products/attach-code`): an unknown scanned code is attached to a product once; later scans find the product. A valid ISBN-13 goes to the product's ISBN (and to its approved title if that has none); other codes to the barcode. A code another product already has, even a deleted one, is refused (`duplicate_code`), and an occupied slot is never overwritten (`code_slot_taken`).

**Products table:** a badge shows *Approved* (linked to an approved title), *Withdrawn* (the title left the list) or *Not on list*, with a filter.

**Coverage** (*Approved list > Coverage*):

- **In my products:** an approved title with at least one product linked to it (not deleted), whatever its stock.
- **Not in my products:** approved and no product: what schools may ask for. Grouped by level; band-only titles under their band; readers, guidance and e-learning under their category.
- **My products not on the list:** active products with no approved title, or whose title was withdrawn.

## On the phone (3.A.3)

- **Offline copy:** at login (and on pull-to-refresh in *Products > Add from approved list*) the app asks for `GET /reference-books/snapshot` with the stored ETag: unchanged lists answer 304, changed ones are downloaded (gzip) and kept in a JSON file on the phone. The stock per title (`GET /reference-books?stocked=1`) is fetched with it and shown "as of" that sync. Without a connection, search uses the stored copy and the screen says "Offline".
- **Search on the phone** follows the server's rules: every word matches the start of a word in title, publisher, author, subject or level; "maths" = "mathematics"; "basic 4" / "primary 4" / "p4" / "b4", "kg 2", "jhs 1" filter by class (band-only titles included when their band covers the class); accents and apostrophes ignored. Ranking: exact title words, title prefixes, then other fields. Each result says "In stock N" or "Not in your products".
- **Quick-create:** tapping a title asks only for what the list does not say (variant, cost, price, opening stock, code; the class for band-only titles; subject or language when missing) and sends `POST /products` with a persisted idempotency key (one per title and payload), so a retry after a dropped connection cannot create a second product. A scanned code is attached after the product exists; if it belongs to another product, the product is still saved and the app says why the code was not attached.
- **Receive stock:** the one search box shows *your products* (server search) and *approved titles you do not carry yet* (phone search). Choosing such a title runs the quick-create without opening stock (the receipt line is the stock) and adds the new product as a line.
- **Scanning resolves products first** (`GET /products/by-code`: SKU, ISBN, barcode). An unknown code is offered for attaching: to one of your products (`POST /products/attach-code`), or to an approved title as a new product. If the phone's copy of the list has a title with that ISBN, it is offered first as a hint, but the user still chooses (a title can have several variants, and the list learns only the first ISBN). After attaching, the same code scans straight to the product.

## Search

`ReferenceBookSearch`: a level written in the query ("basic 4", "primary 4", "p4", "b4", "kg 2", "jhs 1") filters by that level and includes band-only titles whose band covers it; "maths"/"math" match "mathematics". MySQL/MariaDB use a FULLTEXT index on the normalized title, publisher and author (boolean mode, prefix match, relevance order). InnoDB ignores words under 3 letters and its stop-words ("the", "for", ...), so those words are matched with LIKE or dropped. SQLite (tests) uses LIKE throughout. `reference_books.isbn` is indexed (not unique: uniqueness belongs to products).

## Publishing safeguards

If an accepted row would take the identity (natural key) of a *different* live title, the whole publish is rolled back, the row gets the error "This change would duplicate another title on the list" (`key_collision`) and is set back to undecided, and the owner sees "N accepted row(s) would duplicate another title ... Nothing was published." Fix or exclude the row and publish again. A unique-index violation is caught the same way as a backstop. In normal use the review screens prevent this (every fix re-matches the row's key); the check protects against stale state.

## Tables

| Table | Purpose |
|-------|---------|
| `reference_editions` | One per import: label, source URL, file SHA-256, printed date, importer, status `draft/active/superseded/discarded`, row counts, skipped rows, stated counts. |
| `reference_books` | The approved titles (live list). |
| `reference_import_rows` | Staging rows of a draft: parsed and mapped fields, issues, diff action and changes, review decision (`resolved`, `excluded`). |
| `publisher_aliases` | Printed spellings → publisher. |
| `products.reference_book_id`, `products.variant_label` | Link from the shop's products. |

## First import of the real list (December 2024, on the throwaway database)

| Check | Result |
|-------|--------|
| Rows read | 1,570 (textbooks 689, supplementary 881); 2 blank rows skipped (Mathematics #42, Our World and Our People #30) |
| Against NaCCA's statistics table | Every section matches except Mathematics (136 read vs 137 stated) and Our World and Our People (39 vs 40). In each case the difference is exactly the blank row NaCCA numbered and counted. Supplementary: 535 subject-based, 285 readers, 21 guidance, 40 e-learning, all as stated. |
| Rows to check | 83: 34 publisher spellings, 4 duplicates (errors; printed twice in the list), 9 Physical Education (subject created on publish), 32 subject-based supplements with no keyword subject (mostly KG "Integrated" materials), 3 "Twi" without dialect, 1 odd author/publisher separator |
| Confidence | 737 high, 833 low (keyword-guessed supplementary subjects) |
| Publish (all suggestions applied, duplicates excluded) | 1,566 titles, 264 publishers, 272 aliases; staging 43 s (mostly PDF reading), publishing 6 s |

The full acceptance with 25 hand-checked rows is part of Phase 3.A.3 (`docs/acceptance-phase3a.md`).
