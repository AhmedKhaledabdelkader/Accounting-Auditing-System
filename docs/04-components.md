# 4. Components reference

Paths relative to `Accounting-System/accounting-import/`.

## `app/Http/Controllers/Api/ClassificationController.php`

- **Constructor:** `ClassificationService $service`.
- **`classify(ClassifyTrialBalanceRequest $request)`** (sync)
  - `set_time_limit(300)`.
  - `$result = $service->classifyFile(file)`.
  - `StructureNotDetected` → `422 {message, problems, structure}`; other `RuntimeException` → `422 {"message": ...}`.
  - `ProviderException` (provider error after the retries, fatal, or a missing key) → `report()` + `502 {"message": $e->getMessage()}`.
  - Success → `ClassificationResponse::make($result)`: `data` = each row through `ClassifiedAccountResource`; `meta` = `total`, `needs_review`, `avg_confidence`, `skipped_rows`, `partial`, `unclassified`, `groups`, `warnings`, `info`, `side_flips`, `other_sheets`, `adjustments`, `provider`, `model`, `usage`.
- **`submit`** stores the file, creates a `ClassificationRun`, dispatches `ClassifyTrialBalance`, returns 202 with the run status. **`show`**, **`confirm`** (mapping check, `acknowledge_doubts`), **`cancel`**: see [05](05-api.md#background-jobs-used-by-the-page). The status has `provider` / `model` (those the run started with), `cache` and `cost` blocks and versioned `status_url` / `cancel_url` / `confirm_url` (`route(..., false)`).

## `app/Http/Requests/ClassifyTrialBalanceRequest.php`

`file => required|file|mimes:xlsx,xls,csv|max:10240`. `authorize()` → `true`.

## `app/Http/Resources/ClassifiedAccountResource.php`

Outputs the row fields in fixed order plus `confidence_label` (`"85%"`).

## `app/Services/ClassificationService.php`

Orchestrates the pipeline (see [03](03-classification-pipeline.md)).

| Method | Description |
|---|---|
| `classifyFile(UploadedFile, ?progress)` | Sync: `detect` → `StructureNotDetected` on errors left → `classifyDetected` |
| `detect(file, ?progress)` | Read + `detectStructure` → `{sheets, names, file_warnings, structure, issues}` (the job pauses here on `StructureChecks::pauses`) |
| `withStructure(file, structure, log, acknowledged)` | A mapping the user confirmed, checked again; acknowledged errors become doubts |
| `classifyDetected(file, detection, ?progress)` | Extract → cache lookup → `labelRows` → unify → rows + flags → caps → cache store. Returns `{cache, side_flips, provider, model, usage, rows, skipped_rows, unclassified, groups, warnings, info, other_sheets, adjustments}` |
| `detectStructure(sheets, names)` | Preview → AI (allowed letters from the whole file) → `StructureChecks::check` → one retry with the errors → `[structure, issues]` |
| `labelRows(structure, headerRows, dataRows, fileContext, progress, cached)` | Only the rows the cache didn't answer, in batches with context rows; warm-up, matching call and batches in the rolling pool; missing-only retry → `[labels, matches, existingMap, warnings, failedRows]` |
| `validLabels(chunk, raw)` / `validMatches` / `validExistingMatches` | Keep answers for requested rows / lines / values, once each, with known values |
| `unifyLabels(labels, extracted)` | One sub class per account (number + name), split by side when the normal sides differ |
| `buildRow(account, ?ai, problems, groups, ?note)` | Taxonomy lookup, Egyptian reason check, reconciliation and abnormal-balance flags, `invalid_number` |
| `egyptianReason(reason)` | Keeps reasons containing Arabic letters; otherwise `السبب مش واضح، محتاج مراجعة` |
| `flagParentAccounts(rows)` | Prefix + equal-sum parent detection |
| `flagNumberPrefixes(rows)` | `prefix_mismatch` from the file's inferred numbering (config `prefix_check`), leave-one-out: first `flagStatementPrefixes` (balance-sheet / income-statement split of the first digits, `severity: high`, via `statementSplits`), then the per-digit family check |
| `flagExistingClassification`, `flagExpenseNames`, `flagDuplicateNumbers`, `applyAdjustments` | File classification, expense names, repeated numbers, adjustments sheets |
| `fileChecks(rows, totalRows, groups)` | Debit = credit per group (full vs partial), total-row match → `{warnings, info}` |
| `capConfidence(rows, sources)` | Config `confidence_caps`; manual / exact-key imported rows keep theirs |
| `finalizeRow(row)` | `needs_review` and `note` |

Row shape: `buildRow` plus `row`, `audit_request`, `existing_classification`, `existing_note_classification`, `note_reference`, `existing_main_classes`, `cache_key`, then `needs_review` and `note` (`finalizeRow`). The API output is `ClassifiedAccountResource` (fields in [05](05-api.md#post-apiv1classifytrial-balance-synchronous)).

## `app/Services/SpreadsheetReader.php`

| Method | Description |
|---|---|
| `read(UploadedFile): array` | `sheet → row → letter → value`, 1-based sheet/row numbers as in Excel; empty cells/rows/sheets dropped |
| `sheetNames(file, numbers)`, `subtotalRows(sheet)`, `hasManualCalculation(file)` | Sheet titles; rows with `SUBTOTAL`/`AGGREGATE` formulas (forced to totals); the manual-calculation warning |
| `static formatRow(int, array): string` | `R12 \| A: … \| B: …` prompt line |

## `app/Services/AccountExtractor.php`

| Member | Description |
|---|---|
| `GROUPS`, `FIELDS` | `opening/period/adjustment/closing`; the 23 column roles (number, name, 4 groups × debit/credit/net/side, `notes`, `audit_request`, `existing_classification`, `existing_note_classification`, `note_reference`) |
| `normalize(raw)` | Tool input → `{sheet, header_rows, first_data_row, columns: field → letter\|null}` |
| `presentGroups(structure)` | Groups with a debit+credit pair or a net column |
| `validate(sheets, structure): list<string>` | English error list (fed back to the AI on retry); empty = valid |
| `dataRows(sheet, structure)` | Rows ≥ `first_data_row`, excluding `header_rows` |
| `extract(cells, structure)` | `{account, problems}`: exact values, number/name split, net/side handling, group fallback |

## `app/Services/AmountParser.php`

`static parse(mixed): ?float` (empty → 0, unparseable → null) and `static asciiDigits(string)`. See [pipeline step 4](03-classification-pipeline.md#step-4-extraction-accountextractorextract-adjustmentlines).

## `app/Services/StructureChecks.php`

`check(sheets, structure)` → issues `{check, severity: error|doubt, field, column, value, threshold, ai, user}`; `errors()` → the English messages of the errors (what `AccountExtractor::validate` returns); `afterClassification(rows)` → the swap and total-as-account doubts. Thresholds: `config('classification.structure_checks')`. See [pipeline step 3](03-classification-pipeline.md#step-3-structure-detection-call-1-once-per-file).

`php artisan classification:check-structure <file> <mapping.json> [--json]` (`app/Console/Commands/CheckStructure.php`) runs the checks on a known mapping without the AI, for tuning.

## `app/Repositories/Contracts/AccountClassifierRepositoryInterface.php`

```php
public function detectStructure(array $preview, array $previousErrors = [], array $sheetNames = [], array $letters = []): array;
public function warmCache(array $structure, array $headerRows, array $fileContext): void;
public function classifyRowsRolling(array $batches, int $concurrency, Closure $onAnswer): void;
```

## `app/Repositories/Eloquents/AccountClassifierRepository.php`

- `detectStructure`: English system prompt, preview text grouped per sheet, forced tool `submit_structure`, `max_tokens` 16000. Column properties are enums of the letters of the whole file (passed by the service) plus the preview's.
- `classifyPrompt`: rules + cached file block (column map, header, full chart, adjustment lines, existing values), forced strict tool `submit_rows`, `max_tokens` 16000. The message holds the context rows, the rows to classify, and/or the ids to match; `sub_class` is an enum of the taxonomy. Returns a `ToolCall`.
- `warmCache`: the same prompt with no rows as a `ToolCall` with `warmUp: true`. `AnthropicDriver` sends it with `max_tokens` 1 to write the prompt cache; `GeminiDriver` sends nothing (`ToolAnswer::notSent()`).
- `classifyRowsRolling`: Guzzle `EachPromise` over one shared handler, at most `concurrency` in flight. Each request is a chain (answer → optional missing-only retry in the same slot). An exception from the callback rejects the whole run and is rethrown; later answers are dropped.
- `call(ToolCall, kind)`: `ClassifierDriver::classify()`, adds the answer's usage to `UsageMeter` by kind, and logs `llm_no_tool_call` (`kind`, `tool`, `reason`) for an answer without a tool call (its input is `[]`: all gaps, retried by the missing-only retry). Retries and errors are the driver's.
- Constants: `ROW_TYPES`, `FIELD_LABELS` (field descriptions used in both prompts).

## `app/Support/Llm/` (AI drivers)

Strategy: one interface, one class per provider; prompts, schemas, the missing-only retry, caching and cost stay above the drivers.

| Class | Role |
|---|---|
| `ClassifierDriver` | Interface: `classify(ToolCall): PromiseInterface<ToolAnswer>`; rejects with `ProviderException`. A missing key rejects before anything is sent |
| `ToolCall` / `ToolAnswer` | Provider-neutral request (`system` parts with a `cache` marker, `tool` {name, description, schema, strict}, `content`, `maxTokens` 16000, `warmUp`) and answer (`input`, `usage` {input, cache_write, cached, output} or null when nothing was sent, `noToolCall` reason) |
| `AnthropicDriver` | `POST {base_url or https://api.anthropic.com/v1}/messages`, `x-api-key`, `anthropic-version: 2023-06-01`, forced `tool_choice`, `cache_control: ephemeral` on the cached part, strict schema (objects closed, numeric / length bounds dropped). Sends the warm-up (`max_tokens` 1). 429 waits `Retry-After` |
| `GeminiDriver` | `POST {base_url or https://generativelanguage.googleapis.com/v1beta}/models/<model>:generateContent`, `x-goog-api-key`, `parametersJsonSchema`, `functionCallingConfig` mode `ANY` + `allowedFunctionNames`, `generationConfig.maxOutputTokens` 16000 + `thinkingConfig.thinkingLevel`. `schema()`: `null` inside an `enum` → `anyOf`. No warm-up. 429 waits `RetryInfo.retryDelay` (body), else `Retry-After`; `PerDay` quota fatal. Usage: `cachedContentTokenCount` split from `promptTokenCount`, thinking counted as output |
| `LlmTransport` | Shared HTTP (singleton): one Guzzle handler for the rolling pool, heartbeat on every attempt and response, attempt counting (`llm_requests`), `TIMEOUT` 120 s, `ATTEMPTS` 3; the driver's `retryDelay` closure decides the wait or fatal. `retryable(status)`, `delay(seconds)` (capped 60 s, default 1.5 s) |
| `ProviderException` | User-facing message + `fatal`; `from()` (transient → `AI provider error, please retry.`; fatal → driver message or `AI provider rejected the request: …`), `missingKey()` |
| `Provider` | `name()` / `model()`: the configured provider and its model, for `meta`, runs and imports |
| `UsageMeter`, `LlmCost` | Token totals per kind; cost from `config('classification.prices')` at the run's start date ([06](06-configuration.md#configclassificationphp)) |

## `app/Repositories/Contracts/TaxonomyRepositoryInterface.php` / `Eloquents/TaxonomyRepository.php`

`map()`, `subClasses()`, `mainClasses()` (those with a sub class), `find()` (→ `[statement, main class, normal side]`), `normalSide()`, `familyOf()` (the stored family), `resolveSub()` / `resolveMain()` (current or former name → current name); the concrete class also has `entries()`, `flush()`, `lineKeyOf()`, `renamedOrigins()`, `addedSubLines()`, `fromDatabase()`. Read from the `taxonomy_*` tables, or the config list without them. Changes: `Services/TaxonomyManager`; config rules follow through `Support/Taxonomy/TaxonomyRules` ([16](16-taxonomy.md)).

## Classification cache and accountant imports

Described in full in [11](11-classification-cache.md) and [12](12-accountant-imports.md).

| Class | Purpose |
|---|---|
| `Services/ClassificationCache` | `lookup()` (key entry → code + name fallback of imported entries → name layer, then side pairs), `store()` (AI answers worth reusing, as base class), `correct()` (manual correction with the row's side / lock), `baseClass()`, `explain()` |
| `Support/ClassificationKey` | The cache key, its base key (`base`, `baseHash`: code + name) and normalization rules |
| `Services/CacheCleanup` | One-time cleanup of old entries (run by migration `2026_10_06_000002`; `php artisan classification:cache-cleanup [--dry-run]`) |
| `Services/CacheImport` | Accountant files: `check()` header, `read()`, mapping (override → reuse → taxonomy → LLM), `review()` / `approve()`, `apply()`, name layer, `report()` |
| `Support/ImportNormalizer` | Spelling-independent form of hand-written classes |
| `Jobs/ProcessClassificationImport` | Queued read + mapping of an upload, with progress and Arabic errors |
| `Repositories/Contracts/TaxonomyMapperRepositoryInterface`, `Eloquents/TaxonomyMapperRepository` | LLM calls that map الميزانية values and (main class, الإيضاحات) pairs to the taxonomy (`sub_class` is an enum) |
| `Services/CacheTransfer` | Excel and JSON export, JSON import with the priority rules |
| `Http/Controllers/Api/ClassificationCacheController`, `ClassificationImportController`, `ClassificationTransferController` | HTTP for the above |
| Models | `ClassificationCacheEntry`, `ClassificationNameLayerEntry`, `ClassificationImport`, `ClassificationImportMapping`, `ClassificationImportRow`, `ClassificationRun` |
| `Console/Commands/ImportClassifiedFile` | `classification:import` (sync import, `--reapply`, `--rebuild-name-layer`) |
| `Console/Commands/CleanupClassificationCache` | `classification:cache-cleanup` (see `CacheCleanup`) |
| `Console/Commands/ProbeClassifier`, `CompareClassifiers` | `classifier:probe`, `classifier:compare`: live provider checks ([08](08-setup-and-operations.md#live-provider-checks)) |

## Financial statements generator (`app/Services/Statements/`)

Described in full in [14](14-financial-statements.md). Pure PHP, no AI.

| Class | Purpose |
|---|---|
| `LayoutProfile` | From a run's result: the real column groups (copied groups ignored), layout C / OC / OMC, closing derived when the file has none, empty groups, families (partial TB), unclassified / needs-review counts, post-closing |
| `LineCatalogue` | One template version of `config/financial_statements.php`: lines and sides, `rule()`, `sideRule()`, `functionLine()`, `functionOf()` (a function named in the file's own column), `partnersCurrentLine()` |
| `LineMapper` | Row → line(s) per column group: override → side rule → sub-class → main class (function: the file's label, then the default); unmapped, excluded parents, pending rows |
| `PeriodicInventoryPairs` | Check 26a: opening / closing stock accounts on the inventory line, found from balances and movements; proposals only |
| `LineAggregator` | Per line: closing, opening, period and adjustment movements as `Figure`s, signed by the line's side; wrong-side rows listed |
| `Figure` | `{amount, origin, status, refs}`; a missing figure has no amount |
| `StatementBuilder`, `IncomeStatementBuilder`, `BalanceSheetBuilder` | A statement in template order from the line figures (config `statements`), totals derived; income +/expenses −; retained earnings + net profit when pre-closing; missing P&L when post-closing |
| `ReportChecks` | Checks 1–8, 26, 26a, 26d of the current year; 28, the zakat rule and entries on unmapped rows; 15–19, 23, 26b, 26c on the movement schedules; 9–13, 24 on the changes in equity and cash flow; 21–22 |
| `lang/ar/statements.php` | Every Arabic name for a code (checks, severities, statuses, origins, layouts, groups, reasons, choices); pages and `title_ar` / `option_labels` read it |
| `ReportIntake` | The fields a report asks (partners, zakat, OCI), their validation, required ones still empty |
| `LineAdjustments` | The user's adjusting entries, zakat charge and loans' current portion, applied to the closing figures of their lines; returns each posting for the schedules |
| `PriorYearResolver` | Comparative figures from the report's prior-year source: a previous report, a prior run (+ OCI figures), manual figures, or a confirmed file of issued statements |
| `StatementSynonyms` | Other wordings of the statements' lines and totals (PR 17), in table `statement_synonyms`: the wording rule (normalised, sorted words), validation (one wording per statement, never a line's own label), the starting list. Edited through `Api\StatementSynonymController` and the «مرادفات البنود» page |
| `PriorStatementsFile` | Last year's issued statements from Excel (PR 17): statements by their «قائمة …» titles, the year column by its header, each row's label and amount read exactly (`unread` otherwise), a line proposed by wording (`exact` / `probable` / `none`), expenses shown positive flagged; no AI |
| `MovementSchedules` | The notes' movement tables (PR 9): runs `AssetNotesBuilder` (PPE, intangibles, investment property per category) and `ProvisionRollforwards` (provisions, impairment, dividends payable, loans note) over a `ScheduleContext` (rows, layout, mapping, postings, categories, tags, comparatives); collects suggestions and missing cells |
| `EquityStatementBuilder` | Statement of changes in equity: columns per equity line (partners' current when in equity), opening from the opening columns or the comparative, net profit and OCI, movements suggested from the equity accounts' movements or net changes |
| `CashFlowBuilder` | Cash flow, indirect method: each balance-sheet line's change on one row, the notes' charges and additions, the changes-in-equity movements, murabaha loans with profit paid in financing, the overdraft per the user's cash definition |
| `MovementSuggester` | The cells the user decides: movement suggestions (pending / confirmed / edited / rejected), movements only the user can give, and correctable facts |
| `ReportStatements` | A report's statements: its run, its own part and its comparatives (API state) |
| `CurrentYearStatements` | Runs the chain (map with overrides and tags → aggregate → entries, zakat and current portion → IS → SOFP → movement schedules → changes in equity → cash flow → intake → checks) for a run, a template version and a report's own part; comparatives from the opening columns or the given prior figures, check 21 (`ReportStatements` adds check 22, the periods) |
| `App\Services\TrialBalanceTotals` | Debit / credit totals per column group, parents left out: the classifier's file check and check 1 |
| `ReportSource` | A report's run: whether it can feed a report (only `done`, with a result), its result hash (`run_changed`), and the layout and mapping under the report's template version |
| `Http/Controllers/Api/CompanyController`, `FinancialReportController` | `/companies` (list, create, read, partial update) and `/financial-reports` (list, create from a run, read the state, intake, inputs, account overrides, adjusting entries, prior year, movement decisions) |
| `App\Services\Exports\StatementsXlsx` | A version's payload into the template's `statements.xlsx` (PR 14): amounts, «[ناقص]», zero rows and columns hidden, the draft's marks and notes, the journal entries; fixed zip times so the same version gives the same bytes |
| `App\Services\Exports\StateLabels` | Arabic names of a state's lines, movement cells and missing figures, for the exports (as `describe.js` in the pages) |
| `App\Services\Exports\NotesDocx` | A version's payload into the template's `notes.docx` (PR 15): the shown notes, paragraphs (the user's wording kept) and tables as the wizard lays them out, «[ناقص]», the empty PPE section removed, the draft's marks and page; fixed zip times so the same version gives the same bytes |
| `App\Services\Exports\StatementsPdf` | A version's payload into a PDF of the statements and notes (PR 16): the print view `exports/report-pdf.blade.php` rendered by mPDF with the open font XB Riyaz embedded; the wizard's lines, notes and tables (notes in portrait, a wide table in a smaller font; amount columns one width; headings and totals bold); a landscape page for the changes in equity; the draft's marks; the version's time and id in the file so the same version gives the same bytes |
| `App\Services\Exports\DraftNotes` | What a draft's own page says in the Word and PDF files: what blocks a final, the missing figures, the failing checks |
| `Http/Controllers/Api/ReportExportController` | `GET /financial-reports/{id}/versions/{n}/export.xlsx`, `export.docx` and `export.pdf` |
| Models | `Company`, `FinancialReport`, `FinancialReportVersion` |

## `app/Providers/AppServiceProvider.php`

Binds the repository interfaces (classifier, taxonomy, taxonomy mapper), `ClassifierDriver` (`match` on `CLASSIFIER_PROVIDER`: the only place that picks the provider), and `Heartbeat` / `UsageMeter` / `LlmTransport` as singletons. Refuses to start a queue worker whose `retry_after` is not above the job timeout. See [02 § Dependency injection](02-architecture.md#dependency-injection).

## `bootstrap/app.php`

Routing (`apiPrefix: 'api/v1'`), `/up`, JSON errors for `api/*`, custom 404 JSON, framework handling for validation/auth/HTTP exceptions, catch-all JSON 500. See [02 § Error handling](02-architecture.md#error-handling).

## Routes

| File | Content |
|---|---|
| `routes/api.php` | Under `/api/v1`: `Route::prefix('classify')->group(base_path('routes/apis/classify.php'))` `Route::prefix('classification-cache')->group(base_path('routes/apis/classification-cache.php'))`, and the same for `companies` and `financial-reports` |
| `routes/apis/classify.php` | `POST /trial-balance` (`classify.trial-balance`), `POST /jobs`, `GET /jobs/{id}`, `POST /jobs/{id}/structure`, `POST /jobs/{id}/cancel`, `PATCH /jobs/{id}/rows/{row}` (row correction, `ClassificationCacheController@correct`), `POST /jobs/{id}/corrections` (several rows at once, `correctMany`) |
| `routes/apis/classification-cache.php` | Cache explain/entries, accountant imports, export / import ([05 § other routes](05-api.md#other-routes)) |
| `routes/apis/companies.php`, `routes/apis/financial-reports.php` | `GET /`, `POST /`, `GET /{id}` (and `PATCH /{id}` for companies; `GET /{id}/intake`, `PATCH /{id}/inputs`, `PUT /{id}/account-overrides`, `PUT /{id}/adjustments`, `PUT /{id}/prior-year`, `POST /{id}/suggestions` for reports) |
| `routes/web.php` | `GET /` → `welcome`; `GET /classifier`; `GET /classification-cache`; `GET /classification-cache/imports/{id}/review`; `GET /financial-reports…` (the report pages); `GET /financial-statements/preview/{run}`, `GET /financial-reports/{report}/preview` (the old previews, redirected to the review step); `GET /api/docs` (Swagger UI, unversioned) |

## Unused defaults

`UserFactory`, `DatabaseSeeder`, `resources/js/*`, `resources/css/app.css` and the default migrations are framework scaffolding. Users, roles, permissions and Sanctum API keys: [15](15-access.md).
