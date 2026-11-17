# 8. Setup & operations

## Requirements

| Tool | Version | Why |
|---|---|---|
| PHP | 8.2 – 8.4 | Laravel 12 needs ≥ 8.2; locked `phpoffice/phpspreadsheet 1.30.7` requires `< 8.5` |
| PHP extensions | `zip`, `gd`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `mbstring`, `fileinfo`, `iconv`, `pdo_sqlite` (or `pdo_mysql`) | PhpSpreadsheet + Laravel |
| Composer | 2.x | PHP deps |
| Node.js / npm | 20+ | Vite build (welcome page assets) |
| qpdf | 10+ | Cuts the statements' pages out of a PDF of last year's statements ([14](14-financial-statements.md)); the tests use it too |
| AI provider API key | — | Classification (Anthropic by default; [06 § Switching the AI provider](06-configuration.md#switching-the-ai-provider)) |

### macOS

```bash
brew install php@8.4 composer node qpdf
brew link --force --overwrite php@8.4   # if php 8.5 is also installed: brew unlink php first
php -v                                  # must show 8.4.x
```

### Windows

XAMPP with PHP 8.2–8.4 (the project was originally developed at `C:\xampp\htdocs\Accounting-System`) plus Composer and Node installers. Enable `extension=zip` and `extension=gd` in `php.ini`. qpdf: the Windows build from github.com/qpdf/qpdf/releases, with `QPDF_BINARY` set to its `qpdf.exe` when it isn't on the `PATH`.

## Install

```bash
cd Accounting-System/accounting-import
composer install
npm install
cp .env.example .env            # if missing
php artisan key:generate        # if APP_KEY empty
touch database/database.sqlite  # if missing
php artisan migrate
npm run build
php artisan users:create-admin admin --name="الاسم"   # the first administrator of the main office ([15](15-access.md), [18](18-offices-and-client-portal.md))
```

Then set the key of the provider in use in `.env` (`ANTHROPIC_API_KEY`, or `GEMINI_API_KEY` with `CLASSIFIER_PROVIDER=gemini`; optionally `CLASSIFIER_ANTHROPIC_MODEL` / `CLASSIFIER_GEMINI_MODEL`), and `CLASSIFIER_CACHE=true` to use the classification cache. A key left empty or `<need-to-change>` fails every run with `… API key is missing …` before any AI call. See [06 § Switching the AI provider](06-configuration.md#switching-the-ai-provider).

**After changing `.env`, run `php artisan queue:restart`**: a running worker keeps its old settings.

SQLite is the default. On MySQL / MariaDB the connection sets `PDO::MYSQL_ATTR_FOUND_ROWS` (affected rows = matched rows, as on SQLite; see [03 § lease](03-classification-pipeline.md#running-queued-job-batches-in-parallel)). The migrations also run on MySQL: `classification_import_mappings` has a short explicit unique index name (`cim_import_kind_parent_normalized_unique`, MySQL's 64-character limit) and `kind` / `parent` lengths of 20 / 100 (3072-byte key limit).

After every pull: `php artisan migrate`, then **restart the queue worker** (a running worker keeps the old code; new jobs then fail, e.g. `TaxonomyMapperRepositoryInterface is not instantiable`).

**One-time cache cleanup.** Migration `2026_10_06_000002_cleanup_classification_cache` runs `App\Services\CacheCleanup` once (after `2026_10_06_000001` adds `classification_cache.base_hash`) and logs its counts as `classification_cache_cleanup`. It deletes `ai` entries whose key carried a `ClassificationCache::NOT_STORED_FLAGS` flag in a finished (`done`) run, converts side-pair entries to their base class (except `side_locked` ones) and fills a missing `base_hash`; `updated_at` is left as is. It is idempotent. Repeat it, or preview it, with:

```bash
php artisan classification:cache-cleanup --dry-run   # only count
php artisan classification:cache-cleanup
```

`composer run setup` runs most of these steps in one go (it does not add the API key).

## Run

```bash
php artisan serve                        # the app
php artisan queue:work --timeout=1800    # second terminal: classification jobs
php artisan schedule:work                # third terminal: hourly classification:prune (see below)
```

- UI: http://127.0.0.1:8000/classifier
- Health: http://127.0.0.1:8000/up

`composer run dev` starts the server, queue listener, log tail (Pail) and Vite together.

### Port

Set once in `.env`:

```dotenv
SERVER_PORT=8000
APP_URL=http://127.0.0.1:${SERVER_PORT}
```

- `php artisan serve` (and `composer run dev`) reads `SERVER_PORT` as its default `--port`.
- `APP_URL` interpolates it (used for URLs generated outside a request, e.g. console).
- The UI uses a relative API URL, so it needs no port.

A one-off override still works: `php artisan serve --port=9000`.

## Test

```bash
php artisan test                                              # offline, free (Anthropic wire format)
CLASSIFIER_PROVIDER=gemini php artisan test                   # the same suite, Gemini wire format
RUN_LIVE_TESTS=1 php artisan test --filter=LiveBalanceXlsxTest   # real API of the provider in use, costs a little
RUN_LIVE_TESTS=1 CLASSIFIER_PROVIDER=gemini php artisan test --filter=LiveBalanceXlsxTest
```

The suite runs with either provider: fakes answer in the active provider's wire format (`tests/Support/FakeWire.php`); tests of one provider's own behaviour call `$this->useProvider(...)`.

PHPUnit uses in-memory SQLite (`phpunit.xml`). The same suite runs on MySQL: `phpunit.xml`'s `<env>` values don't override variables already set, so point it at an empty database, e.g. a throwaway MySQL 8 container:

```bash
docker run -d --rm --name accounting-mysql-test -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=accounting_test \
  -p 127.0.0.1:33061:3306 mysql:8.0 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33061 DB_DATABASE=accounting_test DB_USERNAME=root DB_PASSWORD=test php artisan test
docker stop accounting-mysql-test                             # --rm deletes it
```

Write tests that read JSON columns by key, not by position: MySQL's JSON type does not keep the key order.

**CI** (`.github/workflows/tests.yml`): every pull request and every push to `main` runs `composer test` three times: SQLite, MySQL 8 (a service container, utf8mb4), and SQLite with `CLASSIFIER_PROVIDER=gemini`. It starts from `.env.example` with a fresh `APP_KEY` and no API keys, so a test must not depend on your local `.env`; one that reads an env variable sets `$_ENV`/`$_SERVER` as well as `putenv` (`env()` reads them first; see `tests/Unit/ConcurrencyConfigTest.php`). Generated test workbooks are cached by content (`TrialBalanceFiles::workbook`), so the same rows always give the same file hash.

**API contract** (`tests/Feature/ApiContractTest.php`): every endpoint, its valid request and every status the spec documents; each response body is validated against `public/docs/openapi.yaml` (JSON Schema, `opis/json-schema`) and must carry no undocumented field (`tests/Support/OpenApiContract.php`); a last test fails if a documented status is never sent. The AI is `tests/Support/FakeAi.php`: a file with «PAUSE-ME» pauses for confirmation, «FAIL-AI» makes the provider fail. It runs in the normal suite through the HTTP kernel. To run the same checks over real HTTP against a live server (a throwaway database; your real data is not touched):

```bash
S=/tmp/contract && mkdir -p $S && touch $S/contract.sqlite
php -S 127.0.0.1:8012 tests/Support/contract/fake-ai-server.php &          # the AI
(cd public && env DB_CONNECTION=sqlite DB_DATABASE=$S/contract.sqlite QUEUE_CONNECTION=sync CLASSIFIER_CACHE=true \
  CLASSIFIER_PROVIDER=anthropic CLASSIFIER_ANTHROPIC_BASE_URL=http://127.0.0.1:8012/v1 ANTHROPIC_API_KEY=fake PHP_CLI_SERVER_WORKERS=4 \
  php -S 127.0.0.1:8011 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php &)   # the app
CONTRACT_BASE_URL=http://127.0.0.1:8011 DB_CONNECTION=sqlite DB_DATABASE=$S/contract.sqlite CONTRACT_REPORT=$S/report.json \
  php artisan test --filter=ApiContractTest
```

For Gemini, use `CLASSIFIER_PROVIDER=gemini CLASSIFIER_GEMINI_BASE_URL=http://127.0.0.1:8012/v1beta GEMINI_API_KEY=fake` instead: the fake server answers any path, in the request's format. The 500 cases are checked through the kernel only (a live server can't be made to crash).

The AI provider is faked with `Http::fake` and `Http::preventStrayRequests()`, so tests are free, offline and deterministic.

| File | Covers |
|---|---|
| `tests/Unit/AmountParserTest.php` | Separators, Arabic-Indic/Persian digits, negatives, currency, Dr/Cr, invalid values |
| `tests/Unit/SpreadsheetReaderTest.php` | Excel row numbers/letters, all sheets, formulas, CSV, prompt row format |
| `tests/Unit/AccountExtractorTest.php` | Structure normalize/validate (every error), extraction: pairs, net/side, number+name split, fallback, non-numeric |
| `tests/Unit/TaxonomyRepositoryTest.php` | 154 unique sub classes, periodic opening/closing stock and «مخصصات أخرى», the five statement classes (v3), every entry's statement / main class / normal side, every main class has a family, normal side matches the family except contra accounts, audit fixes and added entries, `find` / `normalSide` / `familyOf` |
| `tests/Unit/ClassificationKeyTest.php` | Arabic spelling variants (diacritics, tatweel, أ/إ/آ, ة, ى, spaces) give one key; the base key is code + name without the budget item |
| `tests/Feature/ClassifyTrialBalanceTest.php` | Endpoint contract, both AI requests, rules and caps, Egyptian reason fallback, names as uploaded, batching + context, 502, 422s, CSV |
| `tests/Feature/FileStructuresTest.php` | English/reordered headers, title + two-row headers, second sheet, number+name cell, signed and Dr/Cr balances, Arabic digits, formulas, parent/total/section rows |
| `tests/Feature/IntegrityChecksTest.php` | Label retry and merge, still-missing rows kept for review, duplicates, unknown sub class, stray rows, non-numeric amounts, parent detection, debit≠credit and total-row warnings, structure retry and 422 |
| `tests/Feature/ClassificationJobsTest.php` | Submit → 202 → poll = sync result, file deleted, failures, 404, progress, warming call then the rolling pool, no warming for one request, retries, failed batch (batch 1 included) kept as unclassified in `meta.unclassified` (a partial result, not a warning), every batch failing aborts, no credit / invalid key stop the run |
| `tests/Feature/RollingPoolTest.php` | The real rolling pool with a scripted Guzzle handler: a freed slot starts the next request, the limit is never exceeded (matching call included, sent first), missing-only retry in the same slot, `Retry-After` / 1.5 s waits, 2 retries, fatal errors abort |
| `tests/Unit/StructureChecksTest.php` | Every structure check fires on a wrong mapping and stays quiet on a correct one; both-sides headers never contradict; merged group titles; minimum sample sizes; after-classification checks |
| `tests/Feature/StructureDetectionTest.php` | Doubts become warnings without a retry, the retry gets errors only, issue logging (run id, measurements, no cell values), upload deleted by `failed()`, log retention |
| `tests/Feature/DroppedRowsTest.php` | Non-account rows with balances: one grouped warning with count, first 10 rows and closing balances; totals, parents and zero rows excluded; unlabelled unnamed rows included |
| `tests/Feature/ConfirmationTest.php` | Pausing on errors / pausing doubts only, the column preview, confirm (corrected mapping classifies, still-wrong 422, twice 409, body validation), cancel (waiting, queued, not while processing), prune (expired runs, orphaned uploads), hourly schedule, sync 422 with the mapping |
| `tests/Unit/ConcurrencyConfigTest.php` | `CLASSIFIER_CONCURRENCY` parsing: valid values, and 0 / negative / text / decimal / empty / unset → 4 |
| `tests/Unit/Statements/LineCatalogueTest.php` | `config/financial_statements.php`: every taxonomy sub-class has a rule, every rule points to a known line, the lines are exactly the template's input lines; the statements (and the cash flow and changes in equity) follow the template's rows, total formulas and columns, every line on exactly one statement; function words read from a file's own column |
| `tests/Unit/Statements/LayoutProfileTest.php` | Layouts C / OC / OMC from the real groups; copied groups ignored; closing derived without closing columns; empty groups, partial TB, counts; post-closing; `Figure` rules |
| `tests/Unit/Statements/LineMapperTest.php` | Sub-class over main-class rules; side rules per column group (overdraft); expenses by function (client label, default, nature lines untouched); doubted rows pending; unmapped, choices, excluded parents; the v3 statement sub-classes on their own lines; overrides and splits; partners' current choice |
| `tests/Unit/Statements/PeriodicInventoryPairsTest.php` | Opening / closing stock accounts on the inventory line proposed for cost of revenue and never moved; closing-only files; totals of several stock accounts and movements; no proposal for ordinary inventory, cost-of-sales stock accounts or overrides |
| `tests/Unit/Statements/LineAggregatorTest.php` | Amounts signed by the line side; contra vs wrong side; only the groups the file has; a bank split between overdraft and cash across years; shares and pending status; unmapped and excluded rows left out |
| `tests/Unit/StatementTemplatesTest.php` | `resources/templates/sa-ifrs-smes-3`: no personal data or printer settings in either package, comments are `[review]` marks only; the workbook's sheets, no `#REF!`, amount cells empty or formulas, the balance / equity / cash checks are 0 for balanced figures; every docx placeholder in one text run, blocks balanced and named uniquely (70), no hard-coded period, city or reference leftovers |
| `tests/Feature/InSheetAdjustmentsTest.php` | Adjustment columns in reconciliation, adjustments balance check, full-trial-balance 4-group warnings, SUBTOTAL rows forced to totals, suspected parents out of totals, sheet matching skipped, audit requests, manual calculation warning |
| `tests/Feature/AdjustmentsAndNotesTest.php` | Remarks column (mapped, inferred, on skipped rows), other sheets, adjustments matched by number or by the matching call, its retry, its failure / gaps / fatal error, no matching call when not needed, unknown numbers, unmatched-difference warning |
| `tests/Feature/BalanceXlsxTest.php` | `tests/Fixtures/balance.xlsx` end to end with Claude faked: exact reconciliation, adjustments, abnormal balances, notes, partial trial balance |
| `tests/Feature/LiveBalanceXlsxTest.php` | The same expectations against the **real** API of `CLASSIFIER_PROVIDER`. Skipped unless `RUN_LIVE_TESTS=1`; writes the response to `storage/logs/live-balance-xlsx-<provider>.json` |
| `tests/Feature/ErrorHandlingTest.php` | 422 validation (with/without `Accept`), 405, JSON 404, JSON 500 |
| `tests/Feature/ClassifierPageTest.php` | Page renders, relative API URL (no hard-coded host/port), `/up`, `serve` port from `SERVER_PORT` |
| `tests/Feature/ApiVersioningTest.php` | API served under `/api/v1` only (old paths 404), returned URLs versioned |
| `tests/Feature/ClassifierFixesTest.php` | Note-number column read as `note_reference` (mapped by the AI, or rescued from `notes`; real remarks stay remarks); a function label in the file's classification doesn't contradict an expense nature; periodic opening/closing stock, warranty provisions and the v3 statement sub-classes on their natural side; the statement-level numbering scheme (high `prefix_mismatch`, codes with letters, mixed numbering); leave-one-out family check |
| `tests/Feature/SampleTrialBalanceTest.php` | `tests/Fixtures/sample-trial-balance.xlsx` (a real client trial balance, names anonymised) with the AI answers of a live run (`tests/Fixtures/mappings/sample-trial-balance*.json`): 282 accounts, no reconciliation flags, the `إيضاح` column as note references, the periodic stock accounts caught by the statement scheme; mapped to statement lines: nothing unmapped, both stock accounts proposed for cost of revenue (check 26a), net assets = the year's profit (179,588.87, pre-closing); current-year statements balanced at 26,981,374.81 with the profit in retained earnings, both stock accounts as errors, the 1-riyal rounding difference reported; comparative balance sheet from the opening columns balanced at 23,897,950.48; movement schedules: PPE additions 280,743.94 and depreciation 91,888.90, the end-of-service reversal (975.34) not a payment, doubtful debts charged with no expense, zakat used with no charge, and with categories and the deferred-interest tag the loans note net 3,200,315.70; the 2024 result as non-cash dividends to the partners, murabaha drawn net of deferred cost, and a cash flow from 108,838.39 to the balance sheet's 34,728.95; notes (periodic stock confirmed): 23 shown and numbered, every table tied to its line, the cost build-up, the PPE note per category, the loans note net of the deferred interest, the end-of-service reversal on its row |
| `tests/Feature/CacheSafetyTest.php` | Confidence caps (60 abnormal balance, 50 `prefix_mismatch`) and those rows not cached; `possible_parent` not cached; a manual correction keeps its confidence; AI answers stored as the side pair's base class; the one-time cleanup |
| `tests/Feature/ClassificationCacheTest.php` | Same file twice sends no known rows to the AI, a different file reuses known accounts, saved row corrections (batch, all or nothing) come back in later files and unsaved ones change nothing, manual correction wins, taxonomy version bump, low confidence not stored, deleted entry, cached parent as context, spelling variants, explain, entries |
| `tests/Feature/SidePairsTest.php` | Side flips with / without review, manual corrections with and against the side (locked), employee advances by name, VAT-only flips |
| `tests/Feature/CacheImportTest.php` | Accountant import mapping, junk rows, name layer, priority over manual / AI entries, staged apply, corrections, flags, cost-of-revenue policy, code + name fallback (one clear answer, imported only), base class on apply (no conflict across sides) |
| `tests/Feature/CacheImportSiteTest.php` | Queued upload from the page, Arabic errors, reuse of mappings, duplicate file, side rules, apply removes only its own entries, JSON export / import (side locks, base_hash, base class), Excel export |
| `tests/Feature/ConsistentClassificationTest.php` | One sub class per account (majority, tie by confidence, split by side), required confidence |
| `tests/Feature/ExistingClassificationAndDuplicatesTest.php` | File classification compare / mismatch / conflict, matching call, repeated numbers |
| `tests/Feature/StatementAndUsageTest.php` | `statement_mismatch` (file classification, expense names), provider / model / usage in the result and the log, default model |
| `tests/Feature/RunCostTest.php` | Run cost from usage, retries counted, estimated savings, unknown model price → no cost |
| `tests/Feature/OpenApiSpecTest.php` | `public/docs/openapi.yaml` lists exactly the `/api/v1` routes; every `$ref` resolves; every operation has a summary, responses and a unique `operationId`; every example matches its schema |
| `tests/Unit/Statements/CurrentYearStatementsTest.php` | Balanced pre-closing (retained earnings + profit, signs, OMC and C), post-closing with movements (the P&L from them when retained earnings' credit movement equals the profit, with a distribution in the same year, or their debit movement the loss, or the current-year profit account's alone; `pl_from_movements` info; the closing entry found in the closing balance instead: a profit's credit gap, a loss's debit gap, a gap with a distribution in the movements; the error otherwise: a credit of the wrong amount, a gap of the wrong amount, a gap on the wrong side), unbalanced and unmapped (`tb_unbalanced`, `unmapped_accounts`, `sofp_unbalanced`), post-closing (missing P&L), partial, `net_income_mismatch` (an unmapped P&L class; an override is the user's decision), row-level checks on their lines, pending totals, whole-riyal rounding |
| `tests/Feature/ArabicPagesTest.php` | Every code has an Arabic name; the classifier, cache and import review pages show no code in their visible text; the detector itself (the wizard's steps are checked in the browser test) |
| `tests/Feature/PriorYearTest.php` | No source: balance sheet from the opening columns (unclosed P&L into retained earnings), income statement missing; first year (one column); a prior report (its inputs, period; deleted → missing); a prior run with check 21 an error until restated with a note, OCI figures; manual figures (lines not given = 0, a short period, an unbalanced comparative); the cells typed by hand and left empty listed in `comparative.defaulted` (lines, changes in equity, cash flow; nothing for a section not typed or another source); validation |
| `tests/Feature/PriorStatementsUploadTest.php` | Last year's issued statements from Excel (PR 17): read and waiting for review (nothing used before), rows not matched exactly need a choice, confirmed figures as the comparatives (`prior_year_statements`, file refs, lines not shown 0, the cash flow from the file), check 21 with a different closing, an unread figure missing never 0, a row left out caught by the file's totals, expenses shown positive confirmed, a year column picked when the header doesn't say, a new file waiting while the confirmed one stays, files refused; a synonym places a row the review can still move; a waiting file follows the list while a confirmed one keeps its matches. `tests/Unit/PriorStatementsFileTest.php`: how a cell reads as an amount |
| `tests/Feature/StatementSynonymsTest.php` | The synonyms of the lines (PR 17): the starting list with its lines named, add / edit / delete, one wording per statement (word order and spelling aside), never a line's own label, unknown lines and non-Arabic refused, the page |
| `tests/Feature/EquityAndCashFlowTest.php` | Changes in equity from movements (capital, reserve, dividends) and a cash flow that reaches the cash; checks 9, 11, 12 on wrong decisions; retained earnings cleared to the partners (24, non-cash dividends) as a liability and in equity (Q23); opening + closing only (net changes, loans entered); closing only (needs the prior year); a first year; checks 10 and 13 (restated → warnings); the overdraft in or out of cash (Q25); comparatives from the prior report, or typed from last year's statements (checked against the comparative balance sheet); a PPE revaluation surplus kept out of purchases, its split across categories (19) |
| `tests/Feature/NotesTest.php` | Notes (PR 11): zero notes hidden and the rest renumbered, the statements' note column in step, forced on / off (27); a comparative figure alone shows a note; the company's and the approval's wording; early application only before 2027, its reference to the first-application note only with that note; the approval with or without the Hijri date; the first-application note and its questions; going-concern indicators (25), include / exclude with a reason; item tables by class (14), typed prior-year notes and movement tables (14, 17 comparative); the periodic build-up; the partners vs the capital (20); related parties proposed, confirmed and on both sides (14, 26e); policies by line, answer or force; first year and restated wording; a reworded paragraph kept, flagged when its template text moves; every block and placeholder of the template filled |
| `tests/Feature/FinancialReportPagesTest.php` | The financial-report pages (PR 13a): Arabic shells, `PageConfig` with relative URLs, the steps, unknown / unfinished runs and reports; every string a script uses is Arabic in `lang/ar/financial_reports.php`; the classifier links a finished run to a new report, no longer to a preview; the old preview addresses redirect to the review step (a run's latest report, or a new report from a run with none) |
| `tests/Feature/ArabicValidationTest.php` | Every Laravel validation rule has an Arabic message and every field an Arabic name; with `Accept-Language: ar` each endpoint the pages use answers in Arabic with no Latin word left; without it, the default messages |
| `tests/Feature/ReportUpdateTest.php` | `PATCH /financial-reports/{id}` (company, period, after the comparatives, the prior report's company) and `GET /classify/jobs` (newest first, by status, without results) |
| `tests/Browser/FinancialReportPagesTest.php` | In Chrome (Dusk): the list, a new report from a run, steps 1–3 saved (company profile with a field error, manual comparatives, a line chosen, an adjusting entry); steps 4–5 (questions answered, a suggestion confirmed then all, the approval answered, a note hidden, a paragraph reworded); suggested answers saved only when accepted in the box (back, only mine, accept); after each, the visible text Arabic with no code, no `null` / `undefined`, no row reference, no script error, and accounts as «number — name»; the review (13c: the manual prior-year cells counted as 0, the adjusting entry in full and the moved account with its line by the classification, linking to step 3, a line opened to its accounts, a warning acknowledged with a note, the final closed then open), a draft and a final (asked first) on the versions step, each version's Excel download, a version opened read-only at `#versions/<n>`, «changed since the final», an unknown version; the old preview link landing on the review; parent accounts: the probable parent excluded with a reason in step 3 (none without), the parent by levels listed as left out, an account excluded from its editor, each exclusion in the review's summary; a report with most checks, inputs, overrides, entries, notes and a version read in Arabic on the review and the version |
| `tests/Feature/AccountNamesTest.php` | «number — name» for one account and a list; the report's suggestion bases, proposals and checks name accounts, never rows |
| `tests/Feature/ParentRowsTest.php` | Parent rows at classification (made-up codes; the child's code never starts with the parent's): leading marks (after a leading space), leading spaces, the cell's indentation and a level column each make a parent, left out of the totals, and an unmarked account is no parent; with no levels a row explaining the total row's difference is only proposed, and the warning names it; equal neighbours in a file that adds up aren't parents |
| `tests/Unit/ParentRowsTest.php` | `ParentRows` on built rows: each marker's levels, no marker without two levels or on too few accounts, gaps, parents by level, by amounts, and which candidates explain the difference |
| `tests/Feature/ParentRowsReportTest.php` | In a report: a parent by levels left out (check 1 balances); a probable parent counted, pending, proposed and blocking a final until excluded with a reason (out of the statements and of check 1) or kept; an exclusion needs a reason and nothing else; any account can be excluded |
| `tests/Feature/XlsxExportTest.php` | The Excel export (PR 14): a final fills the template (sheets, RTL, company and dates, whole riyals and parentheses, note numbers, totals as formulas, one column in a first year, zero lines and their heading hidden, the checks at 0, the changes in equity and the cash flow tie, no PPE sheet without the note, no draft mark, no template comment), the journal entries (account by number and name or line, Dr / Cr to the halala, each entry and all balanced); the same version gives the same bytes, even after the report changes; a draft marked (first sheet, cover, every header) with what blocks a final, the missing figures by name and the failing checks, «[ناقص]» in a missing cell and the total over it, the PPE note's category columns; a revaluation as its own PPE row, the closing cost the template's sum over the rows shown and equal to the report's; entry lines named in the state; 404s |
| `tests/Feature/DocxExportTest.php` | The Word export (PR 15): the file shows what the version shows (the same notes in order, every paragraph and table cell for cell, read right to left), whole riyals and Western digits, a first year's one-year tables, right to left, no placeholder or `[review]` comment left, the landscape PPE section kept with only its categories that have amounts (grid rebuilt) or removed without PPE, page numbers running on, a draft's header mark and first page, the user's wording, the same bytes for the same version, 404s. `SampleTrialBalanceTest` checks the same on the sample file (23 notes, both years). Helpers: `tests/Support/DocxFiles.php` |
| `tests/Feature/PdfExportTest.php` | The PDF export (PR 16): the print view shows what the version shows (every statement line and figure by the wizard's rule, both years' changes in equity, the notes numbered, every paragraph and table), a first year's one column, Western digits, the Arabic font embedded, landscape pages, the notes running on in portrait with amount columns one width and totals marked, a draft's marks and «[ناقص]», the same bytes for the same version, 404s. `SampleTrialBalanceTest` renders the sample file (23 notes, the PPE note's wide table in portrait) |
| `tests/Feature/VersionsTest.php` | Versions and the final (PR 12): a ready report goes final (status, payload); drafts always, a final blocked with its reasons; pending suggestions, missing figures and pending mappings block; warning acknowledgements, tied to their amounts, kept time, withdrawn; a corrected run as a warning until acknowledged; a later final supersedes, versions never change, `changed_since_final`; a first year's notes miss no prior year |
| `tests/Feature/MovementSchedulesTest.php` | Movement suggestions confirmed, edited, rejected, reset (a confirmation is of an amount); accumulated depreciation of no category (Q8); CWIP transfers; disposal proceeds (18, gain or loss); opening + closing only (the user's split, 19; charge from the expense); closing only (opening from the prior year or the user); reversals not payments (26c), provisions with no P&L counterpart (26b, tagging the expense), zakat used with no charge (23); the loans note with the deferred finance cost and the current portion (Q36, 19); entries by account in the note, by line flagged (15); override validation |
| `tests/Feature/ReportInputsTest.php` | The intake per run (partners, zakat asked / required, post-closing asks nothing); zakat an error until entered or confirmed zero with a mandatory reason (charge → expense and provision), or settled by an entry; OCI and partners inputs, stale values dropped; overrides confirm the periodic stock proposals, shares, validation; moved rows keep where the classification puts them (`mapping.overridden`, an unclassified row's reason); adjusting entries reach the statements (origin, refs, profit), must balance and be complete; an entry on an unmapped account is reported |
| `tests/Feature/CompaniesTest.php` | Defaults, Hijri date kept as entered, partial update, year-end day checked against the month (stored or sent), scope (`llc`, `SA`, `ifrs_for_smes`, `SAR`), validation before lookup, search in names and numbers, pagination |
| `tests/Feature/FinancialReportsTest.php` | A report from a done run: layout, mapping (unmapped with names, excluded parents, periodic-stock proposals), stored result hash; 409 for every other run status; validation; a later row correction gives `run_changed` and a state read from the run as it is now; list filters; deleting a company deletes its reports |
| `tests/Feature/ApiContractTest.php` | Every endpoint × every documented status, responses validated against the spec (no undocumented fields); request side: missing / wrong-type / wrong-enum fields → 422, extra fields ignored. Also runs against a live server |
| `tests/Feature/JobLeaseTest.php` | Lease, heartbeat, stalled runs, `failed()` rules; writes that change nothing keep the lease (the MySQL case); a real loss logs `classification_lease_lost` |
| `tests/Feature/QueueSafetyTest.php` | Worker refuses a `retry_after` not above the job timeout |
| `tests/Feature/LlmProvidersTest.php` | Gemini request, switching by `CLASSIFIER_PROVIDER` only (a key never reaches the other provider), missing / `<need-to-change>` keys fail before any call (sync 502, queued `failed`), unknown provider (faked) |
| `tests/Feature/ClassifierDriverContractTest.php` | The same checks against both drivers: forced tool call, input and usage, answers without a tool call (gaps), missing key, 5xx retried twice, fatal 4xx not retried, rate-limit delay capped at 60 s, warm-up only where needed |
| `tests/Feature/GeminiDriverTest.php` | Gemini's own behaviour: forced function call, thinking level, the 154-item enum kept, null inside an enum → `anyOf`, gaps retried for missing rows only, thinking billed as output, daily quota fatal, body `RetryInfo` delay, fatal messages, price by start date |
| `tests/Feature/ProviderFieldsTest.php` | `provider` / `model` on job status and import summaries; `null` when queued, from before the fields, or no AI needed |
| `tests/Feature/ClassifierCommandsTest.php` | `classifier:probe` (all accepted, a rejected schema fails) and `classifier:compare` (agreement, disagreeing classes) |
| `tests/Feature/MalformedToolOutputTest.php` | Lists sent as JSON strings decoded, malformed items skipped, strict classification tool |
| `tests/Feature/ZeroMarkersAndOverridesTest.php` | Dash zeros, "confirm anyway" for measurement errors, severe override warning, blocking errors stay blocking |

### Browser tests (Dusk, PR 13)

`tests/Browser` drives the financial-report pages in headless Chrome: once the scripts have drawn a page, its visible text must be Arabic with no code (`tests/Support/ArabicText.php`, shared with the server-rendered page tests) and no script error. Not part of `php artisan test`; CI runs them in their own job.

```bash
php artisan dusk:chrome-driver --detect      # once: a ChromeDriver matching the installed Chrome
scripts/dusk.sh                              # serves the app on 127.0.0.1:8010 with database/dusk.sqlite, runs Dusk, stops the server
scripts/dusk.sh --filter=FinancialReportPagesTest
```

Without a system Chrome (e.g. this Mac), use Chrome for Testing of the ChromeDriver's version and point Dusk at it:

```bash
npx @puppeteer/browsers install chrome@<chromedriver version> --path ~/.cache/chrome-for-testing
export DUSK_CHROME_BINARY="$HOME/.cache/chrome-for-testing/chrome/mac_arm-<version>/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing"
```

Failures leave screenshots in `tests/Browser/screenshots` (gitignored) and the server's output in `storage/logs/dusk-server.log`. The browser tests use their own database file, never the app's.

Helpers: `tests/Support/TrialBalanceFiles.php` builds real `.xlsx` workbooks (multi-sheet) with PhpSpreadsheet and fakes both AI calls in the active provider's format (`fakeLlm($structure, $labels)`; closures for retries); `tests/Support/FakeWire.php` reads and writes each provider's wire format.

## Logs & debugging

- `storage/logs/laravel.log` — exceptions, including reported `ProviderException`s.
- `storage/logs/classification-*.log` — `llm_no_tool_call` warnings (`kind`, `tool`, `reason`): an answer without a tool call, retried for the missing rows.
- `php artisan pail` — live log tail.
- `APP_DEBUG=true` in `.env` for local detail.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `composer install`: `phpspreadsheet ... requires php <8.5.0` | Use PHP 8.4 (see above) |
| UI shows "Failed to fetch" | Server not running / stopped |
| Upload stays "في الطابور" | No queue worker: run `php artisan queue:work --timeout=1800` |
| Accountant import fails at once: «حصل خطأ غير متوقع…», log says `... is not instantiable` | The worker runs old code: stop it and start it again |
| `php artisan serve`: `Failed to listen on 127.0.0.1:8000 (Address already in use)` | The app is already running (another terminal). Use it, or stop that process first |
| Accountant import: «الملف ده نفسه اترفع قبل كده» | Same file hash as an earlier import; open that one, or upload with `force=1` (the page asks) |
| 502 `… API key is missing. Set … in .env, then run php artisan queue:restart.` | The active provider's key is empty or `<need-to-change>`: set it, then `php artisan queue:restart` |
| 502 `AI provider error` / `AI provider rejected the request: …` | Invalid key or model (`CLASSIFIER_ANTHROPIC_MODEL` / `CLASSIFIER_GEMINI_MODEL`), rate limit, used-up credit or Gemini daily quota, or network. Check `laravel.log` |
| `.env` change ignored by queued runs | The worker keeps its old settings: `php artisan queue:restart` |
| 422 `ما قدرناش نقرا أعمدة الميزان صح: …` (sync endpoint) | The AI's mapping still fails the structure checks after the retry; the message lists them, and `storage/logs/classification-*.log` has every check with its measurement. The page lets the user correct the mapping instead |
| Upload stops at "راجع قراءة أعمدة الملف" | The run waits for the user to confirm the column mapping (`needs_confirmation`) |
| 422 `The file field is required.` | No file sent |
| 500 `Maximum execution time of 300 seconds exceeded` | Sync endpoint only (`set_time_limit(300)`): use the jobs endpoints (the page does), whose job has an 1800 s timeout |
| Config edits ignored | `php artisan config:clear` |

## Live provider checks

Both commands make real AI calls and cost real money.

```bash
php artisan classifier:probe [--provider=gemini]
php artisan classifier:compare tests/Fixtures/balance.xlsx [--providers=anthropic,gemini] [--report=path]
```

- `classifier:probe`: one real call of each kind (structure with integer enums; a row batch with the 154-item sub-class enum and `minimum` / `maximum`; the matching call with integer enums; budget mappings with a nullable type; note mappings with `null` inside enums). Prints accepted / rejected per call with HTTP status, finish reason, then tokens and cost. Exit 1 if anything is rejected. Default provider: `CLASSIFIER_PROVIDER`.
- `classifier:compare`: classifies the file with both providers (the first is the reference), classification cache off (nothing read or stored). Prints rows, time and cost per provider, sub-class and main-class agreement, and the classes they disagree on most. JSON report: `storage/logs/classifier-compare-<time>.json` (or `--report`).

## Scheduler: required for deleting abandoned uploads

A run waiting for the user keeps its uploaded file and a preview of its columns (client data). `classification:prune` runs hourly and:
- expires runs left waiting `confirmation_hours` (24 h) without a confirm or cancel, deleting their file and preview;
- fails **stalled** runs: `running` with a heartbeat older than `stale_minutes` (10) — the worker crashed or was killed — with `التصنيف وقف في النص… ارفع الملف تاني.`, and deletes their file. Runs waiting for confirmation are never treated as stalled. This check also runs alone **every 5 minutes** (`classification:prune --stalled`), so a crashed run shows as failed within about 15 minutes, not up to an hour;
- deletes uploads no active run refers to.

**It only runs if the scheduler runs.** Locally: `php artisan schedule:work`. In production, add the cron entry, or abandoned files and previews are **never deleted**:

```cron
* * * * * cd /path/to/accounting-import && php artisan schedule:run >> /dev/null 2>&1
```

Check with `php artisan schedule:list`; run it by hand with `php artisan classification:prune`.

## Trying the confirmation panel without the AI (local only)

```bash
php artisan classification:demo-confirmation tests/Fixtures/balance.xlsx tests/Fixtures/mappings/balance.json --mutation=swap-opening-closing
```

Creates a run in `needs_confirmation` from a real file and a known mapping made wrong (`swap-opening-closing`, `swap-closing-sides`, `notes-as-name`, `skip-rows`, or `none`), runs the structure checks, and prints `…/classifier?run=<id>`. The page opens any run with `?run=<id>`. Confirming queues the run for classification, which needs the API. Refuses outside `APP_ENV=local`. Correct mappings of the real test files: `tests/Fixtures/mappings/`.

## Stale review notes of rows confirmed earlier

A saved or confirmed class re-derives the row's flags, review state and note. Rows corrected before that (2026-10-26) can keep an
old note while no longer under review. `php artisan classification:refresh-review-notes <run…>` lists them (manual
corrections only); `--apply` rewrites only their `note`, nothing else, and a second run changes nothing. Without options the
note is rebuilt from the row's present fields, which also drops the AI's low-confidence explanation; `--warnings-only` drops
only the warnings of flags the row no longer has, keeping reasons, file notes and present warnings (the safer choice). Try it
on a copy of the database first (`DB_DATABASE=<copy> php artisan …`).

## Deployment notes

**Several queue workers need MySQL/PostgreSQL or Redis for the queue, not SQLite.** The database queue on SQLite has no row locks (`FOR UPDATE` is ignored): workers are only kept apart by SQLite's single write lock, and under load they hit `database is locked`. One worker on SQLite is fine. Set `QUEUE_CONNECTION` (and `DB_CONNECTION` for a database queue) accordingly, keep `retry_after` above 1800 s on that connection too (Redis: `REDIS_QUEUE_RETRY_AFTER`).

No production deployment is configured. For one: set `APP_ENV=production`, `APP_DEBUG=false`, run `php artisan config:cache route:cache view:cache`, serve `public/` via nginx/Apache + PHP-FPM, set PHP `max_execution_time` ≥ 300 and upload limits ≥ 20 MB (`upload_max_filesize`, `post_max_size`; a PDF of last year's statements may be 20 MB), and set `APP_URL` to the public URL.

**qpdf on the server** (last year's statements as a PDF): install it where the queue workers run (Debian/Ubuntu `sudo apt-get install -y qpdf`, Alpine `apk add qpdf`, RHEL/Fedora `dnf install qpdf`), check `qpdf --version`, and set `QPDF_BINARY` to its full path if the workers' `PATH` doesn't have it (`which qpdf`). The PDFs are kept under `storage/app/private/prior-statements/<report>/`: give that the same backups as the database. Restart the workers after deploying (`php artisan queue:restart`).
