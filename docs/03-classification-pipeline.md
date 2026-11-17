# 3. Classification pipeline

End-to-end flow of a classification: `POST /api/v1/classify/jobs` (queued, used by the page) and `POST /api/v1/classify/trial-balance` (sync) run the same service.

**Principle: PHP checks the numbers, the AI only classifies.** "Claude" below means the configured AI provider (`CLASSIFIER_PROVIDER`, Claude by default).
- Claude finds the file's structure and labels and classifies rows. It never returns amounts, never sees check results, and never decides `needs_review`.
- PHP reads every amount from the original cells and runs every numeric check.

```mermaid
sequenceDiagram
    participant C as ClassificationService
    participant R as SpreadsheetReader
    participant E as AccountExtractor
    participant A as AccountClassifierRepository
    participant X as AI provider (ClassifierDriver)

    C->>R: read(file), sheetNames(file)
    R-->>C: sheets → rows → cells, sheet titles
    C->>A: detectStructure(preview of every sheet, names, all column letters)
    A->>X: forced tool submit_structure
    X-->>A: sheet, header_rows, first_data_row, columns (+notes), other_sheets (+adjustments structure)
    C->>E: validate — one retry with the errors — withInferredNotes
    C->>E: extract every data row (exact values, remarks) + adjustment lines
    C->>C: ClassificationCache::lookup — rows the cache knows skip the AI (11)
    C->>A: warmCache (only with more than one request; only Anthropic sends it, Gemini sends nothing)
    A->>X: submit_rows, max_tokens 1 (writes the cached prefix: rules + column map + header + full chart + adjustment lines + existing values)
    par at most `concurrency` requests in flight, matching call first
        C->>A: matching call (only when the file has name-only/repeated-number adjustment lines or existing classification values)
        A->>X: submit_rows, no rows
        X-->>A: adjustment_matches, existing_class_matches
    and batches of 100 (`CLASSIFIER_BATCH_SIZE`) rows
        C->>A: batch (rows, cached file context)
        A->>X: forced tool submit_rows
        X-->>A: rows[row, row_type, sub_class, confidence, reason]
    end
    C->>C: validate each answer — one retry of only the gaps, in the same slot
    C->>C: data_flags (reconciliation, abnormal balances, side flips), parent detection, account-number check, file classification, duplicates, adjustments matching, file checks, confidence caps
    C->>C: ClassificationCache::store — good AI answers kept for next time
```

## Running: queued job, batches in parallel

- `POST /api/v1/classify/jobs` stores the file and creates a `classification_runs` row. It dispatches `App\Jobs\ClassifyTrialBalance` (timeout 1800 s, 1 try) and returns 202.
- The job calls `ClassificationService::detect($file, $progress)`, then (unless it pauses for confirmation) `classifyDetected`. The progress callback records `stage` and `done/total` batches on the run, and the page polls `GET /api/v1/classify/jobs/{id}` every 2 s.
- **Requests** (`labelRows` → `AccountClassifierRepository::classifyRowsRolling`):
  - with more than one request, a **cache-warming call** runs first: Anthropic sends it (`max_tokens` 1, same prefix), so every request reads the cache instead of each writing it; Gemini caches implicitly and sends nothing for it
  - then a **rolling pool** keeps at most `concurrency` (`CLASSIFIER_CONCURRENCY`, default 4) requests in flight; as soon as one finishes, the next starts
  - the **matching call** (adjustment lines + existing classification values) is first in line, and is only sent when the file needs it; batches never ask for matches
  - every request gets up to 2 retries on timeouts, connection errors, 408, 409, 429 and 5xx (waiting 1.5 s; a 429 waits the provider's delay, capped at 60 s: Anthropic's `Retry-After`, Gemini's `RetryInfo.retryDelay` from the body, else `Retry-After`); the waits don't block the other slots. Retry delays and fatal errors are each driver's (`AnthropicDriver`, `GeminiDriver`); `LlmTransport` sends (120 s timeout, 3 attempts)
  - an answer with gaps is retried once with **only the missing** rows / matches, in the same slot, and only the gaps are filled. An answer **without a tool call** (Gemini `MALFORMED_FUNCTION_CALL`, `SAFETY`, `MAX_TOKENS`, a blocked prompt; Anthropic without `tool_use`) is all gaps, handled the same way, and logs an `llm_no_tool_call` warning (`kind`, `tool`, `reason`)
  - a batch that still fails comes back as unclassified rows; batch 1 is no different, and finished batches are kept
  - any unclassified account rows (failed batches, or rows the AI left out even after the retry) make the result **partial**: `meta.partial` and `meta.unclassified` (count, ranges, cause), shown as a red banner above the warnings. The run is still `done`
  - matches still missing add a warning (`ما قدرناش نطابق N سطر من قيود التسوية…` / `ما قدرناش نقارن تصنيف الملف…`), so "no issues found" is never confused with "the check didn't run"
  - the file aborts only on a fatal provider error, or when **every** batch failed
- **Fatal provider errors stop the run immediately and are not retried** (`ProviderException` with `fatal`, decided by the driver): every 4xx except 408, 409 and 429, plus Gemini's used-up daily quota, plus a missing key (detected before any call). The run fails with the driver's message, else `AI provider rejected the request: <message>`. Only timeouts, connection errors, 408, 409, 429 and 5xx are retried.

| Provider | Case | Message |
|---|---|---|
| both | key empty or `<need-to-change>` | `<Provider> API key is missing. Set <VAR> in .env, then run php artisan queue:restart.` |
| Anthropic | body mentions `credit balance` | `Anthropic API credit balance is too low. Add credits, then retry.` |
| Anthropic | 401 | `Anthropic API key is invalid. Check ANTHROPIC_API_KEY.` |
| Gemini | 400 `API_KEY_INVALID`, or 401 | `Gemini API key is invalid. Check GEMINI_API_KEY.` |
| Gemini | 429 whose `QuotaFailure` `quotaId` contains `PerDay` | `Gemini daily quota for <model> is used up. It resets at midnight Pacific time; retry then, or raise the quota in Google AI Studio.` |
| Gemini | 402 | `Gemini prepaid credit is used up. Add credit in Google AI Studio, then retry.` |

- **One worker per run (lease).** Taking a queued run sets `status = running` and a random `lease` in one conditional update. Every later write (progress, heartbeat, done / failed / needs_confirmation, file cleanup) requires that lease on a running run; a worker that lost it (`LeaseLost`) stops without writing or deleting anything.
  - **The affected-row count is not the proof.** MySQL counts only *changed* rows by default, so a write in the same second with the same stage (e.g. the first `reading` progress right after the claim) reports 0. When an update reports 0, the job checks that it still holds the lease (`lease` + `status = running`) before giving up. A real loss is logged once as a warning, `classification_lease_lost {run_id, at, status, stage, taken_by_another_worker}`, where `at` is the write that noticed it (`heartbeat`, `progress:<stage>`, `finish:<status>`, `fail`).
  - The MySQL / MariaDB connections also set `PDO::MYSQL_ATTR_FOUND_ROWS` (`config/database.php`), so affected rows mean matched rows, as on SQLite. The one-step status changes (claim, confirm, cancel, expiry, stalled, `failed()`, the import job's claim) always change `status`, so their count is reliable either way.
- **Heartbeat.** `heartbeat_at` is refreshed at every stage, before every AI attempt and after every response (structure detection, cache warming, matching call, batches, retries after a `Retry-After` wait), throttled to every 15 s. The longest silence is one 120 s call plus a capped 60 s wait; a run is stale only after `stale_minutes` (10).
- **Stalled runs** (worker crashed or killed, heartbeat older than 10 min) are failed by `classification:prune --stalled`, scheduled every 5 minutes (within ~15 min of the crash), instead of waiting ~31 min for `retry_after`.
- **`failed()`** only touches a `running` run, never one waiting for confirmation (no worker by design; only expiry ends it). A second pick-up after `retry_after` (`MaxAttemptsExceededException`) fails the run only if its heartbeat is stale; the worker's own timeout or an uncaught error fails it at once.
- **After changing code or `.env`, run `php artisan queue:restart`.** A running worker keeps the code and settings it loaded.
- Measured on a ~780-row file, 16 batches of 50, `claude-haiku-4-5`: **98 s** with the rolling pool (148 s with the earlier fixed groups of 4).

## Step 1: Validation
`ClassifyTrialBalanceRequest`: `file` required, `mimes:xlsx,xls,csv`, `max:10240` KB.

## Step 2: Minimal reading (`SpreadsheetReader`)
- `read()` loads the file with PhpSpreadsheet (read-only) and returns every sheet as `sheet number → Excel row → column letter → value`. **Formulas use the value Excel saved in the file.** They're recalculated only if no saved value exists, because recalculating real workbooks (e.g. thousands of `SUBTOTAL` formulas) exhausted memory. Empty cells, rows and sheets are dropped. CSV encoding is auto-detected.
- `sheetNames()` returns the sheet titles (e.g. `Report`, `قيود التسويات`). CSV files get `Sheet N`.
- Rows are sent to Claude as `R12 | A: 110101 | B: الصندوق | C: 5000`.

## Step 3: Structure detection (Call 1, once per file)
Claude receives the first 30 non-empty rows of up to 10 sheets, headed `### Sheet N: <title>`. The forced tool `submit_structure` returns:

| Field | Meaning |
|---|---|
| `sheet`, `header_rows`, `first_data_row` | Where the trial balance is |
| `columns` | Column letter per field (`AccountExtractor::FIELDS`): number, name, `{opening,period,adjustment,closing}_{debit,credit,net,side}` (**adjustment** = in-sheet `تسوية` columns, optional), **`notes`** (remarks about a *problem* with the row, even without a header), **`audit_request`** (auditor requests / PBC items / instructions, never notes) and **`note_reference`** (a note-number column such as `إيضاح`: `5`, `15/أ`; a reference, never notes) |
| `other_sheets` | `[{sheet, type, description}]`; `type` is one of `adjustments`, `subsidiary_ledger`, `notes`, `other`. **Adjustments sheets also return `first_data_row` and `columns` {account_name, account_number, debit, credit}** |

The allowed column letters are taken from the whole file, not just the preview, so a remark far down the sheet can still be mapped.

**Structure checks** (`StructureChecks::check`). Each issue is an **error** (the mapping is certainly wrong) or a **doubt** (plausible but suspicious). An uncertain check is always a doubt: a false doubt only adds a warning, while a false error blocks a correct file. Thresholds live in `config('classification.structure_checks')`.

| Check | Severity | Fires when |
|---|---|---|
| `sheet_exists`, `required_fields`, `column_exists`, `first_data_row`, `other_sheets` | error | The sheet is missing; the name or a complete balance group is missing; a mapped column is empty; the first data row is outside the sheet; an other-sheet problem |
| `duplicate_mapping` | error | One column mapped to two fields (number + name in one cell is allowed) |
| `header_rows` | error / doubt | A header row is not above the first data row / the header cells are mostly numbers |
| `header_contradiction` | error | A debit column's header names only credit (مدين/Dr/debit vs دائن/Cr/credit), or the reverse. Headers naming both sides ("الرصيد مدين/دائن", "طبيعة الرصيد") never trigger it |
| `group_contradiction` | doubt | A header names only another group (افتتاحي / حركة / تسويات / ختامي…), including a merged group title up to 3 columns to the left |
| `amount_numeric` | error / doubt | An amount column is below 50% / 80% numbers |
| `name_fill`, `name_text` | error / doubt | Rows with amounts have no name; the name column holds numbers |
| `number_code`, `number_distinct` | doubt | Account numbers don't look like codes, or are almost all the same |
| `side_values` | error / doubt | A side column doesn't hold debit/credit words |
| `text_numeric` | doubt | Notes / requests / file-classification column is mostly numbers |
| `group_fill` | doubt | A balance group has values on almost no rows (adjustments exempt) |
| `groups_swapped`, `sides_swapped` | error | With opening, movement and closing mapped, the equation closing = opening + movement (+ adjustments) is tested as mapped and with each plausible exchange: opening ↔ closing, movement ↔ closing, or one group's debit ↔ credit. Only rows an exchange decides count (they reconcile one way only; rows with no opening prove nothing). When ≥ 90% of at least 5 deciding rows favour an exchange, the error names the one fixing the most rows. Calibrated: correct mappings 0%, swapped 100%, on every file. Opening ↔ movement can't be seen (the equation adds them): only `group_contradiction` catches it |
| `row_reconciliation` | doubt | The equation holds on under half the rows and no exchange explains it |
| `first_rows`, `first_row_is_header`, `rows_skipped` | doubt / error / doubt | No early row has a name and an amount; the first data row's amount cells are words (error with ≥ 2 amount columns); account-like rows sit between the header and the first data row |
| `debit_credit_swap`, `total_as_account` | doubt | After classification: at least 60% of at least 20 classified accounts are on the abnormal side (the only way to see a consistent swap); rows named like totals were labelled as accounts |

- Only **errors** go back to Claude for the one retry, each with its exact message.
- **Queued runs (the page):** if errors remain after the retry, or a doubt in `structure_checks.pause_on` is found (`group_contradiction`, `row_reconciliation`, `rows_skipped`, `amount_numeric`, `side_values`, `group_fill`, `name_fill`, `header_rows`: the ones that make results wrong), the run stops at `needs_confirmation`. The page shows the problems, every column with its header and sample values, and the first rows; the user confirms or corrects the mapping (checked again) or cancels. See [05 § waiting for confirmation](05-api.md#background-jobs-used-by-the-page). Every other doubt is added to `meta.warnings` as `راجع قراءة الأعمدة: …`, and the run continues.
- **Sync endpoint:** never waits. Doubts become warnings; errors left after the retry fail it with the actual problems (`ما قدرناش نقرا أعمدة الميزان صح:` + one line each) as `422 {message, problems, structure}`.
- On confirm, the log records which fields the user changed from the AI's mapping (`structure_confirmed`): the best evidence for tuning the checks.
- Every issue is logged to the `classification` channel (`storage/logs/classification-*.log`, JSON, kept 30 days): run id, stage, attempt, check, severity, field, column, measured value, threshold. No cell values are logged.
- Tune without the AI: `php artisan classification:check-structure <file> <mapping.json>` lists every issue with its measured value.
- **Calibration** after changing a check: run it with `--calibrate` on each real file and its correct mapping in `tests/Fixtures/mappings/` (`balance.json` with `tests/Fixtures/balance.xlsx`; the others are client files kept outside the repo: `16-batch-file.json`, `erp-export-xls.json`, `arabian-company.json`, `dash-zeros.json`). The correct mapping must have no error, and every wrong mapping (opening↔closing, closing sides, remarks as name, skipped rows) must still be caught; compare the table with the one before the change.
- A cell of only dashes (`-`, `—`, `–`, `ـ`, `--`, `(-)`) is zero for the checks and the extraction (`AmountParser`).

**Remarks safety net** (`withInferredNotes`): if Claude maps no `notes` column, an unmapped column with no header whose values are all text is used as the remarks column.

**Note-number safety net** (`AccountExtractor::withNoteReferences`, on the AI's answer only, never on a mapping the user confirmed): a `notes` column whose values are all note numbers (at least 3; `5`, `(7)`, `15/أ`, Arabic digits too) becomes `note_reference`. Otherwise every numbered row would get a `source_note` and need a review.

## Step 4: Extraction (`AccountExtractor::extract`, `adjustmentLines`)
- Every data row is extracted into exact values, including the remarks cell as `note`. See [05 § input format](05-api.md#input-file-format) for the number formats handled.
- Each adjustments sheet is parsed into lines `{row, account_name, account_number, debit, credit}`. Blank lines and zero lines are skipped.
- A line with an account number is matched in PHP: to that account, or to `null` if the number isn't in the trial balance. Name-only lines are left for Claude to match.

## Step 5: Classification (Call 2, batches of 100 (`CLASSIFIER_BATCH_SIZE`))

**Zero rows.** A row zero in every real column group (each debit and credit within 0.005: zero to the halala) is not classified: never sent to the AI, left out of the results, and listed in one info message (`N صف رصيدهم صفر في الافتتاحي والحركة والختامي (حسابات صفرية أو عناوين)، اتشالوا من التبويب (…)`). It still goes as a neighbour for context, and the nearest one above a row is sent as its heading. A zero row an adjusting entry is matched to stays an account.

**Side pairs** re-decide the AI's answers by the row's balance side, as they do the cache's ([11 § side pairs](11-classification-cache.md#side-pairs-classes-that-depend-on-the-balance-side)).

**Cached prefix, identical for every request of the file** (warming call, matching call, batches). It is sent as two system parts, the second marked as the end of the cached prefix (Anthropic: `cache_control: ephemeral`; Gemini caches repeated prefixes implicitly and gets the parts as plain `systemInstruction` text):
1. **Rules** (`AccountClassifierRepository::CLASSIFY_RULES`, in English):
   - the row types
   - one sub_class per account
   - infer the numbering scheme from the file (there is no hard-coded first-digit rule); the name is the primary signal and the number supports it
   - classify similar accounts consistently
   - the ambiguous cases: عهدة → عُهد العاملين vs سلفة → سلف العاملين; deferred cheques → أوراق قبض; credit card → liability, debit/mada → bank; bank guarantee → تأمينات لدى الغير; contra accounts → their contra sub_class; accounts marked "لا تستخدمه" are classified normally
   - the confidence rubric (90–100 / 70–89 / below 70), with balances excluded from scoring
   - `reason` in Egyptian Arabic
   - no amounts in the output
   - how to fill `adjustment_matches` and `existing_class_matches`
2. **File context:**
   - the column map and header rows
   - **the full chart of accounts** (number | name for every data row, no balances)
   - the name-only adjustment lines (`A1 | قيود التسويات R2 | بنك الرياض | 0 | 1751.2`)
   - the distinct existing classification values (`X1 | ممتلكات والالات ومعدات`)

**Per-request message** (never part of the cached prefix):
- batch: context rows (±3, for context only) and the rows to classify
- matching call: "No rows to classify. Return only adjustment_matches for adjustment lines A1, A2… and existing_class_matches for existing classification values X1…"; its retry lists only the ids still missing

**Tool `submit_rows`:** identical in every request, so the cache holds.
- `rows[]`: `row` (integer, **no per-batch enum**), `row_type`, `sub_class` (enum of the taxonomy's 154 sub classes, `TaxonomyRepository::subClasses()`), `confidence`, `reason`
- `adjustment_matches[]` (only when the file has lines to match): `adjustment_row` (enum of line ids), `account_row` (integer: the R number of the trial-balance row; omitted when the account isn't in the trial balance)
- `existing_class_matches[]` (only when the file has its own classification): `existing_id` (enum), `main_classes` (enum of the main classes)
- `strict: true` (Anthropic strict tool use; Gemini ignores it)
- Gemini receives the schema as `parametersJsonSchema` with `functionCallingConfig` mode `ANY` + `allowedFunctionNames`. A `null` inside an `enum` becomes `anyOf: [{type, enum without null}, {type: null}]`; type arrays with `null`, integer enums, `minimum` / `maximum` and the 145-item enum are sent as is (documented as supported by Google; not yet confirmed by a live call)

**Validation and retry.** PHP accepts a label only if:
- its row was requested, and it appears exactly once
- its `row_type` is known
- for accounts, its `sub_class` is in the table
- a match references a known line once, and names a known account row of the chart or none (`validMatches`)

If anything is missing, only the missing part is sent **once more** and only the gaps are filled. Rows still unlabelled become unclassified rows (confidence 0), or are skipped if empty. Adjustment lines still unmatched become `null`, and a warning says how many were not matched.

`confidence` is required in the tool schema. If an account label still comes back without it, the row keeps its sub_class with confidence 0, needs review, and its note says `الذكاء الاصطناعي مادّاش درجة ثقة للتصنيف ده` instead of the reason.

**One classification per account (`unifyLabels`).** The AI can label rows of the same account differently, across batches or even in one batch. Rows with the same account number and name (normalized whitespace, both non-empty) that got different sub_classes are unified: the majority wins, a tie goes to the higher confidence, then the first row; the winner's confidence and reason are copied. Every row of such a group gets an `inconsistent_classification` flag (needs review): the disagreement shows the AI was unsure, and the majority can be wrong. Exception: when the labels differ in `normal_side` (a supplier with a debit balance → `دفعات مقدمة للموردين`), rows are first split by balance side (closing, else opening; none when both are zero) and unified per side only.

## Step 6: Data checks (`ClassificationService`, PHP only)

Every flag is an object: `{type, difference, message}`, plus an optional `severity`: `"info"` = low priority, shown in `note` but never sets `needs_review` on its own; `"high"` = leads the note, before the reason and the other flags (shown in red on the page).

| Type | When | `difference` | Message example |
|---|---|---|---|
| `reconciliation` | The file has opening, period and closing columns (pairs or net), and `\|actual − expected\| > reconciliation_tolerance` (0.01). `expected = (oD − oC) + (pD − pC) + (aD − aC)` (adjustments are 0 when the file has no adjustment columns), `actual = cD − cC` | `actual − expected`, rounded to 2 decimals | `الرصيد الختامي أعلى من المحسوب بـ 1,174.00` / `الرصيد الختامي أقل من المحسوب بـ 1,751.20` |
| `abnormal_opening` / `abnormal_closing` | The balance sits on the side opposite the sub_class's `normal_side`, by more than `materiality_threshold` (1.00) | net balance (debit − credit, so negative = credit) | `رصيد افتتاحي دائن 2,876.90 على حساب طبيعته مدينة` |
| `invalid_number` | A mapped amount cell isn't a number (read as 0) | `null` | `قيمة غير رقمية في العمود G` |
| `classification_mismatch` | Our main class is not one of the main classes Claude mapped the file's own classification value to (column D, else E) | `null` | `تصنيف الملف «X» مش متوافق مع التصنيف المقترح «Y»` |
| `statement_mismatch` (`severity: "high"`) | The statement of our class (income statement vs balance sheet) is not one the file's own classification points to; or, without that evidence, the name starts with `مصروف` / `مصاريف` / `مصروفات` (not prepaid, accrued or deferred: `مقدم`، `مستحق`، `مؤجل`) and our class is on the balance sheet. Moves profit, so its message leads the note and one file warning lists the rows. Replaces `classification_mismatch` on the row | `null` | `⛔ تصنيف الملف «…» بيقول إن الحساب ده في قائمة الدخل، والتصنيف المقترح حطه في قائمة المركز المالي («…»). ده بيغيّر صافي الربح، راجعه الأول.` |
| `existing_classification_conflict` | The same account number carries different file classifications on different rows | `null` | `نفس رقم الحساب متصنف في الملف بأكتر من تصنيف: «A» (الصف 10 في الملف)، «B» (الصف 55 في الملف)` |
| `duplicate_account_number` (`severity: "info"`) | The same account number on rows with **different names** (normalized whitespace). Normal with sub-accounts or branches, so it doesn't set `needs_review`; one file warning gives the count and the first 10 numbers | `null` | `رقم الحساب ده متكرر بأسماء مختلفة: «…» (الصف 12 في الملف)، «…» (الصف 40 في الملف)` |
| `inconsistent_classification` | Rows of the same account (number + name) got different sub_classes and were unified (see Step 5) | `null` | `الحساب ده اتصنف بأكتر من تصنيف («A» في R37، R43 · «B» في R50)، تم توحيده على «A»` |
| `possible_parent` | The account number prefixes other accounts' numbers and its closing equals their sum (double counting). Left out of the totals and the statements | `null` | `قد يكون حساباً تجميعياً (…)` |
| `parent_level` (`severity: "info"`) | The file marks its levels and a deeper row follows this one ([parent rows](#parent-rows)). Left out of the totals and the statements; `rows`: the rows under it | `null` | `حساب تجميعي حسب مستويات الملف (تحته الصف 4 في الملف)، …` |
| `probable_parent` | No known level, its net in every real group equals the sum of the account rows right below it, and it explains why the file doesn't add up ([parent rows](#parent-rows)). Still counted; needs review; the report proposes excluding it | `null` | `ممكن يكون حساب تجميعي: أرصدته بتساوي مجموع … اللي تحته، …` |
| `prefix_mismatch` | The file's own numbering scheme, inferred from the file **without the tested account** (leave-one-out: an account is never evidence for its own class). No hard-coded digit rule. Two forms (`flagNumberPrefixes`): **statement** (`severity: high`, `flagStatementPrefixes`): the first digits split into a balance-sheet range and an income-statement range (one split point, either order; ≥ `statement_min_share` 95% of accounts agree, ≥ `min_accounts` 5 on each side; codes only need to start with a digit, `1204MBA009` counts as 1); an account on the other statement moves profit, so it leads the note and joins the ⛔ statement warning. **Family** (no severity): at least `min_accounts` (5) *other* classified accounts with numeric numbers (≥ `min_length` 2 digits) start with the same digit and ≥ `min_share` (80%) of them share one family (cost counts as expenses); an account in another family. A row flagged by the statement form is not flagged again by the family form | `null` | `⛔ رقم الحساب بيبدأ بـ 6، وترقيم الملف بيحط الحسابات اللي بتبدأ من 1 لـ 3 في قائمة المركز المالي ومن 4 لـ 8 في قائمة الدخل، …` / `رقم الحساب بيبدأ بـ 1، وفي الملف ده 5 من 5 حساب تاني بيبدأوا بيه «أصول»، لكن التصنيف المقترح «مصروفات»` |
| `side_flip` (`severity: "info"` unless the pair has `flag: true`) | The cache's sub-class of a side pair was re-decided by the row's balance side ([11 § side pairs](11-classification-cache.md#side-pairs-classes-that-depend-on-the-balance-side)). Customers / suppliers need review; employees / VAT are only noted | `null` | `التصنيف اتعكس حسب اتجاه الرصيد: «عملاء» ← «دفعات مقدمة من العملاء». راجعه: رصيد عميل/مورد على الجانب العكسي.` |
| `side_contradiction` | The row's side contradicts a cached class the pair may not flip (`only_names`: a non-VAT government receivable with a credit balance). Always needs review | `null` | `الرصيد دائن وده عكس طبيعة «…»، والتصنيف ما اتعكسش لأن اسم الحساب مش من الأسماء اللي بتتعكس (…). راجعه.` |

| `rule_review` | A classification rule matched the account and its `review_side` covers the balance side ([17](17-classification-rules.md)) | `null` | `قاعدة «مخصص الزكاة»: الرصيد مدين، وده محتاج مراجعة.` |
| `missing_account_number` | No account number, in a file whose other accounts have one: no code group can catch it | `null` | `رقم الحساب ناقص: …` |
| `cash_credit` | A cash class (`cash_classes`: box / bank) with a credit closing balance above `materiality_threshold`, after the adjustments; replaces `abnormal_closing`. One file warning lists them | closing net | `صندوق برصيد ختامي دائن 1,200.00: …` |
| `unused_movement` | The name has one of `unused_words` («لا تستخدمه») and the account has movement (period or adjustments). One file warning lists them | `null` | `الحساب ده متعلّم «لا تستخدمه» وعليه حركة. …` |

A rule or a side pair that put an account on the other side on purpose (a debit «جاري التأمينات» coded 2, now an asset) is not flagged by the family form of `prefix_mismatch`.

**Confidence caps** (`capConfidence`, config `confidence_caps`), applied after every flag, to AI and cache answers: `abnormal_opening` / `abnormal_closing` / `cash_credit` → at most 60 (`abnormal_balance`), `prefix_mismatch` → at most 50. Rows answered by a `manual_correction` or an exact-key `imported` entry keep their confidence; the flag still sets `needs_review`. A capped row below `review_threshold` (70) shows its reason in the note.

**Not stored in the cache** (`ClassificationCache::NOT_STORED_FLAGS`): an AI answer whose row carries `statement_mismatch`, `classification_mismatch`, `inconsistent_classification`, `abnormal_opening`, `abnormal_closing`, `possible_parent` or `prefix_mismatch` ([11](11-classification-cache.md#storing-ai-answers-classificationcachestore)).

### Parent rows

A parent (group) row the AI kept as an account counts its children twice. `ParentRows` finds them generically, never from a client's codes or names, after the rows are labelled, from every row of the sheet in file order (sections and totals included):

1. **Levels.** The file's marker, the first that gives at least two levels among the rows it marks and marks at least half the account rows: a level column (header «المستوى» / "Level"), the name cell's indentation, leading spaces (read raw: `SpreadsheetReader::levelHints`), or leading marks («. . .», `·`, `-`, …; one per level). A row followed by a deeper row (past rows of no known level) is a parent, whatever its code, **when its net in every real column group is the sum of its direct children's** (the rows under it not under another row under it): flag `parent_level`, left out of the totals and the statements. A row whose children don't add up is an account. The marker is trusted only when at least one parent with a balance adds up (`ParentRows::trusted`); otherwise its marks are decoration (e.g. dots of every depth before every account) and no row has a known level, so step 2 runs on every row. An account the marker skips (no dots where the others have some) has no known level: neither a parent nor the end of one.
2. **Amounts.** A row of no known level (every row, when the file has no reliable marker) whose net in every real column group equals the sum of the account rows right below it (up to the next non-account row), **and** that explains why the file doesn't add up: its net (alone, or with the other candidates) is the trial balance's debit − credit in every group, or its debit and credit are what the accounts exceed the file's total row by. Equal neighbours in a file that adds up are never parents. Flag `probable_parent`: still counted, needs review, and the report proposes excluding it; the user decides.
3. The existing number rule (`possible_parent`) runs on the rest.

When the accounts don't match the file's total row and the difference is one row's balance, the warning names it: `مجموع الحسابات لا يطابق صف الإجمالي في الملف (الصف 7 في الملف): الفرق بيساوي رصيد «4100 — …» (الصف 3 في الملف)، ممكن يكون حساب تجميعي اتحسب مرتين` (a parent and its only child have the same balance: the probable parent is named).

**Rows left out of the totals.** Totals and parents are left out on purpose. Any other non-account row (AI `other` / `section`, or unlabelled with no name or number) that carries balances gets **one** file warning: the count, the first 10 rows (`… و N صف تاني في الملف`), and their closing debit / credit, e.g. `2 صف فيهم أرصدة ومش داخلين في الإجماليات لأنهم مش متصنفين كحسابات (الصفوف 3، 5 في الملف). أرصدتهم الختامية: مدين 250.50، دائن 40.00. راجعهم.`

**The file's own classification.** Call 1 can map `existing_classification` (e.g. `بنود الميزانية`) and `existing_note_classification` (e.g. `إيضاحات`); formula cells such as `=F46` use their saved text.
- The chart in the cached prefix shows each row's file classification.
- The distinct values (X1…Xn) are listed once, and the matching call returns `existing_class_matches`: each value → the **set** of our main classes it covers. A broad line like `المصروفات العمومية والإدارية` covers many expense main classes.
- The rules tell Claude to treat the file's classification as strong evidence and follow it unless the name clearly contradicts it.
- PHP then flags `classification_mismatch` and `existing_classification_conflict` (see the table).

**Duplicate account numbers.** Rows are keyed by **row number** everywhere: labels, extraction, adjustment sums, chart. Account numbers are only used to find repeats.
- The same number with **different names** is a row flag.
- The same number with the **same name** gets one info message (`فيه N رقم حساب متكرر بنفس الاسم…`), typical of a consolidated trial balance with branches stacked. No row flag.

**Adjustments matching** (`applyAdjustments`), keyed by trial-balance **row**:
- A numbered adjustment line matches in PHP only when its number appears on exactly one row.
- Name-only lines and repeated numbers are matched by Claude, which returns `account_row`, the R number from the chart.. It is skipped for rows that have in-sheet adjustment amounts:
- For each account, the matched lines are summed as `Σ(debit − credit)`.
- If that sum equals a reconciliation `difference` (within 0.01), the message becomes `الفرق ده مطابق لقيد تسوية بمبلغ X (شيت <name>)`, and the flag gains `matched_adjustment: {sheet, amount}`. **The flag stays, so needs_review stays true.**
- If an adjustments sheet exists and any reconciliation difference is unmatched, a file warning is added: `فيه شيت قيود تسوية، وممكن تكون الأرصدة الختامية متضمناها. راجع ده.`

**Row fields** (`finalizeRow`):
- `classification_confidence` and `classification_reason` come from Claude or the cache; the confidence may be capped (above).
- `data_flags` come from PHP.
- `source_note` comes from the file's remarks column.
- `needs_review = classification_confidence < 70 || a data_flags entry without severity "info" || source_note ≠ null`.
- `note` joins, for display: the reason (when confidence < 70) or `لم يتم تصنيف الحساب`, every flag message, and `ملاحظة في الملف: …`.

## Step 7: File-level checks (`fileChecks`)
- **Debit = credit** per group present (opening, period, adjustments, closing). Differences at or below `materiality_threshold` (1.00) are rounding: an **info** message `فرق تقريب X في <group>`, never a warning.
  - On a **full** trial balance (all five major groups present: assets, liabilities, equity, income, expenses) every unbalanced group is a **warning**.
  - **Refused:** a trial balance, full or partial, whose opening, period or closing debits and credits differ by `reject_unbalanced_from` or more (1.00, `CLASSIFIER_REJECT_UNBALANCED_FROM`: only rounding below it passes) fails the run (sync: 422) with `الميزان مش متزن، فاترفض التبويب: <group> (الفرق X)، …`, after the AI's answers are cached. Parents are left out first; probable parents too (the user excludes them in the report), so a file off by a parent counted twice is not refused. The adjustments group is never refused. The test suite sets a huge limit (`phpunit.xml`: its files are made-up partial trial balances); refusal tests set 1.00. **Before the AI** (`clearlyUnbalanced`, right after extraction): the file is refused with the same message, with no AI call, when it is off however its rows are read (the leaves of a numbered chart, the same with the unnumbered rows, every row; formula totals left out), unless a reading is off by exactly one row's balances (a parent counted twice). A row the AI calls a parent or section that the file balances only with is counted as an account (`rescueParents`, flag `parent_rescued`). No opening balances: accepted, with a warning to enter last year's figures in the report.
  - On a partial one, the imbalance is a single **info** message, `الملف ده ميزان جزئي (فيه أصول بس)، فعدم التوازن متوقع`. Unbalanced **adjustments** are still a warning, because adjusting entries balance by themselves.
- **What's counted:** only account rows. Total/section/parent rows never reach the totals. Rows with a `SUBTOTAL`/`AGGREGATE` formula are forced to totals whatever Claude says. Rows flagged `possible_parent` or `parent_level` are left out of the sums.
- **Manual calculation:** if the .xlsx was saved in manual calculation mode, a warning says the saved formula values may be stale (the reader uses saved values, it doesn't recalculate).
- **Total row**: if rows were labelled `total`, at least one must match the account sums. Otherwise there's a warning.
- **Remarks on skipped rows** (totals, sections) become warnings; **audit requests on skipped rows** become info. Neither is ever dropped.

## Step 8: Response

**Messages in two languages.** Every message the pipeline writes (flags, the note, warnings, info, structure issues, run errors) is built as a phrase (`lang/{ar,en}/classification_messages.php`, `App\Support\Phrase`). The stored result keeps the Arabic exactly where it always was, with the phrase beside it (`message_phrase`, `note_phrase`, `classification_reason_phrase`, `meta.warnings_phrases` / `info_phrases`; issues' `user_phrase`; the run's `error_phrase` column). The API (`ClassificationResponse::present`) drops the phrases and adds the same text in the request's language: `data_flags[].message_text`, `note_text`, `classification_reason_text`, `meta.warnings_text` / `info_text`, `error_text`, `confirmation.issues[].user_text`, `confirmation.fields_text`, `problems_text`, `doubts_text`. A run stored before phrases gets its Arabic there. Data (taxonomy and statement names, account names, file values, the AI's `reason`, config labels) is never translated.
`ClassificationResponse::make` builds `{data, meta}`; the row fields are listed in [05-api.md](05-api.md). `meta`: `total`, `needs_review`, `avg_confidence`, `skipped_rows`, `partial`, `unclassified`, `groups`, `warnings`, `info`, `side_flips`, `other_sheets`, `adjustments`, `provider`, `model`, `usage`.

## Calls, cost and size
- Call 1: once per file (+1 on invalid structure).
- Call 2: one per 100 rows (+1 per batch with gaps), plus the matching call when needed (+1 if it has gaps), plus one warming call when there is more than one request.
- The rules, the full chart, the adjustment lines and the existing values are cached by the warming call (Anthropic) or implicitly (Gemini, no warming call sent).
- Model `CLASSIFIER_ANTHROPIC_MODEL` (default `claude-sonnet-5`) or `CLASSIFIER_GEMINI_MODEL` (default `gemini-3.8-flash`), 16,000 output tokens per call (`max_tokens` / `maxOutputTokens`; Gemini's thinking counts against it, `thinkingLevel` from `CLASSIFIER_GEMINI_THINKING_LEVEL`, default `low`).
- Every successful response's tokens are summed into `meta.usage` (`requests`, `input` uncached, `cache_write`, `cached`, `output`), with `meta.provider` and `meta.model`, and logged in one `classification_summary` line per file, with the row counts and the `cache` stats (`rows_from_cache`, `manual`, `ai`, `imported`, `imported_code`, `name_layer`, `rows_sent_to_llm`, `side_flips`). A run that paused for confirmation keeps the tokens its detection used.
