# API reference

Every endpoint of the backend, what it does, what to send and what comes back. For full schemas and examples, open the Swagger UI at **`/api/docs`** (spec: [`public/docs/openapi.yaml`](../public/docs/openapi.yaml)). The spec is enforced by tests: `OpenApiSpecTest` (routes ↔ spec, references, examples) and `ApiContractTest` (every endpoint and every documented status, responses validated against the schemas).

## Conventions

- **Base path:** `/api/v1` on the same host as the app (e.g. `http://127.0.0.1:8000/api/v1`). Use same-origin relative URLs; URLs returned by the API (`status_url`, `cancel_url`, `confirm_url`, `review_url`) are relative too.
- **Headers:** send `Accept: application/json`. JSON bodies need `Content-Type: application/json`; file uploads are `multipart/form-data`.
- **Extra fields** in a request body that an endpoint doesn't list are ignored (no error).
- **No authentication yet.** Every endpoint is open; it must be protected before any deployment.
- **Errors** (all JSON):

  | Status | Body | When |
  |---|---|---|
  | 422 | `{message, errors: {field: [messages]}}` | Validation failed. Checked **before** the record lookup, so an invalid body on an unknown id is 422, not 404 |
  | 404 | `{message}` | The endpoint's own "not found" (e.g. `Classification job not found.`, `الاستيراد ده مش موجود.`, `الشركة دي مش موجودة.`) |
  | 404 | `{status: "error", message: "Route not found"}` | No route matched: wrong path, or a non-numeric id where a number is required |
  | 409 | `{message}` | The record is in the wrong state for this action (see each endpoint) |
  | 502 | `{message}` | The AI provider failed (sync endpoint only; background runs report it as `status: failed`) |
  | 500 | `{status: "error", message}` | Unexpected error |

- **Pagination** (`entries`, `mappings`, `companies`, `financial-reports`): Laravel's paginator. `data` holds the items, plus `current_page`, `last_page`, `per_page`, `total`, `from`, `to`, `next_page_url`, `prev_page_url`, `links[]`. Ask for the next page with `?page=N` until `next_page_url` is null.
- **Language:** messages meant for the end user (errors of imports, warnings, notes, reasons) are Arabic; a few technical messages are English. Validation messages (422) are English by default; send `Accept-Language: ar` to get them in Arabic, each naming its field (lang/ar/validation.php), as the pages do. Messages are not part of the contract: match on `errors` keys, never on their text.

## Typical flows

**Classify a trial balance (the classifier page)**
1. `POST /classify/jobs` with the file → `202 {id, status_url}`.
2. Poll `GET /classify/jobs/{id}` every 2 s until `status` is final:
   - `queued` / `running`: show `stage` and `progress`.
   - `needs_confirmation`: show `confirmation` (columns, problems, preview), then `POST /classify/jobs/{id}/structure` (or `…/cancel`).
   - `done`: show `result.data` (rows) and `result.meta` (warnings, totals, …).
   - `failed` / `expired` / `cancelled`: show `error`.
3. Optional: the user changes classes on the page, then `POST /classify/jobs/{id}/corrections` saves them (they come back in later files).

**Teach the system from accountant-classified files (the cache page)**
1. `POST /classification-cache/import` with the file → `202 {id, status_url, review_url}`; poll `GET /classification-cache/imports/{id}` until `pending_review` (or `failed`).
2. Review: `GET …/imports/{id}/mappings`, decide with `PATCH …/imports/mappings/{mappingId}` or `POST …/imports/{id}/mappings/approve`.
3. `POST …/imports/{id}/apply` writes the approved rows to the classification memory.

**Move the memory to another installation:** `GET /classification-cache/export.json` here, `POST /classification-cache/import-export` there.

**Start financial statements from a finished run**
1. Optional: `POST /companies` (or pick one from `GET /companies?search=`).
2. `POST /financial-reports` with `classification_run_id`, `company_id` and the period → `201` with the report's state (`409` if the run isn't `done`).
3. `GET /financial-reports/{id}` reads the state again; `run_changed: true` means the run was corrected since.

The pages at `/financial-reports` (list), `/financial-reports/new?run=` and `/financial-reports/{id}` (the wizard) do this flow through these endpoints ([docs/14](14-financial-statements.md#pages-pr-13)).

## Classification

| Method | Path | What it does |
|---|---|---|
| POST | `/classify/jobs` | Upload a trial balance for background classification |
| GET | `/classify/jobs/{id}` | Status, progress and (when done) the result of a run |
| POST | `/classify/jobs/{id}/structure` | Confirm or correct the column mapping of a run waiting for it |
| POST | `/classify/jobs/{id}/cancel` | Cancel a queued or waiting run |
| POST | `/classify/jobs/{id}/corrections` | Save the user's class changes for several rows (all or nothing) |
| PATCH | `/classify/jobs/{id}/rows/{row}` | Save one row's class change |
| POST | `/classify/trial-balance` | Classify in the request itself (synchronous, no polling) |

### `POST /classify/jobs`
Uploads a trial balance; classification runs in the background (a queue worker must be running).
- **Body** (multipart): `file`: `.xlsx`, `.xls` or `.csv`, ≤ 10 MB, any layout or language.
- **202:** the run (same shape as `GET /classify/jobs/{id}`): `id`, `status: "queued"`, `status_url`, `cancel_url`.
- **422:** missing, too large or wrong type of file.

### `GET /classify/jobs`
Runs, newest first, without their results (PR 13a: to pick a trial balance). No auth yet.
- **Query:** `status` (one of the statuses below; `done` for finished runs), `per_page` (1–100, default 25), `page`.
- **200:** paginated `{id, status, original_name, rows, created_at, updated_at, status_url}`. **422:** invalid filter.

### `GET /classify/jobs/{id}`
The run as it is now. Poll it every 2 s.
- **200:**
  - `status`: `queued` | `running` | `needs_confirmation` | `done` | `failed` | `cancelled` | `expired`
  - `stage` (while running): `reading` → `structure` → `classifying` → `checks`; `progress: {done, total}` counts AI batches
  - `error`: failure message (when `failed` / `expired`)
  - `provider`, `model`: the AI provider (`anthropic` | `gemini`) and model the run started with; a run resumed after confirmation shows the ones it resumed with. `null` while `queued` before its first start, and for older runs. In every job status response
  - `result` (when `done`): `{data: [rows], meta: {...}}`. Each row: `row` (Excel row), `account_number`, `account_name`, `financial_statement`, `main_class`, `sub_class`, balances (`opening_*`, `period_*`, `adjustment_*`, `closing_*` debit / credit), `classification_confidence` (0–100), `classification_reason`, `needs_review`, `note`, `data_flags`, `source_note`, `note_reference` (the file's note number, e.g. `5`; never affects `needs_review`), `cache_key`. `meta`: `total`, `needs_review`, `avg_confidence`, `warnings`, `info`, `partial` / `unclassified`, `groups` (which balance groups the file has), `side_flips`, `other_sheets`, `adjustments`, `provider`, `model`, `usage`
  - `confirmation` (when `needs_confirmation`): the detected mapping, the problems found, every column with its header and sample values, and `confirm_url` / `expires_at`
  - `cache`: rows answered from memory vs sent to the AI, and estimated savings; `cost`: what the AI calls cost (priced as of the run's first start; `cost_usd` null when the model has no price)
  - `cancel_url`: set while the run can still be cancelled
- **404:** unknown run.

### `POST /classify/jobs/{id}/structure`
For a run in `needs_confirmation`: accept the detected mapping or send a corrected one; the run goes back to `queued`.
- **Body** (JSON): `header_rows` (array of Excel rows), `first_data_row` (integer), `columns` (field → column letter, e.g. `{"account_name": "B", "closing_debit": "G"}`), optional `acknowledge_doubts: true`.
- **202:** the run, now `queued`.
- **422:** the mapping is invalid, or it raises new doubts / errors the user must accept: `{needs_acknowledgement: true, doubts: [...]}`. Show them, then send again with `acknowledge_doubts: true`.
- **409:** the run is not waiting for confirmation. **404:** unknown run.

### `POST /classify/jobs/{id}/cancel`
Cancels a `queued` or `needs_confirmation` run and deletes its file.
- **200:** the run, `status: "cancelled"`. **409:** already running or finished. **404:** unknown run.

### `POST /classify/jobs/{id}/corrections`
Saves the user's class changes for several rows of a `done` run at once. Each row gets the new sub class (main class and statement follow), confidence 100, and is **remembered** as a manual correction: the same account (code + name) gets this class in every later file, without the AI. Unsaved changes on the page never reach the server.
- **Body** (JSON): `{"corrections": [{"row": 4, "sub_class": "نقدية لدى البنوك"}, ...]}`. 1–5000 items, each `row` once, `sub_class` from the taxonomy.
- **200:** `rows` (the corrected rows), `corrected`, `remembered`, `not_remembered` (rows with no account name or known parent: corrected in this run only).
- **404:** unknown run, or rows not in the run (`rows` lists them; **nothing is saved**). **409:** the run is not `done`. **422:** invalid body.

### `PATCH /classify/jobs/{id}/rows/{row}`
Same as `corrections`, for one row.
- **Body:** `{"sub_class": "..."}`.
- **200:** `row` (the corrected row), `cache_entry` (the saved memory entry, or null with a `message` when the row can't be remembered).
- **404 / 409 / 422:** as above.

### `POST /classify/trial-balance`
Classifies the uploaded file in the same request and returns the result directly (can take minutes; the page uses the jobs endpoints instead). It never pauses for confirmation.
- **Body** (multipart): `file`.
- **200:** `{data, meta}`, the same as a job's `result`.
- **422:** invalid file, or the columns couldn't be read: `{message, problems, structure}`. **502:** AI provider error: `AI provider error, please retry.` (transient, after retries), a missing key (`<Provider> API key is missing. Set <VAR> in .env, then run php artisan queue:restart.`), the driver's fatal message (Anthropic credit / invalid key; Gemini invalid key, daily quota used up, prepaid credit used up; see [03](03-classification-pipeline.md#running-queued-job-batches-in-parallel)), or `AI provider rejected the request: <message>`.

## Classification memory (cache)

| Method | Path | What it does |
|---|---|---|
| GET | `/classification-cache/explain` | How the memory works, in Arabic, for the accountant |
| GET | `/classification-cache/entries` | List what the memory holds (paginated) |
| DELETE | `/classification-cache/entries/{id}` | Forget a wrong entry |

### `GET /classification-cache/explain`
- **200:** whether it is on, the lookup steps in order, normalization rules with examples, the key, stored fields, thresholds, and what is never stored. All text in Arabic, built from the code and config.

### `GET /classification-cache/entries`
- **Query:** `source` (`ai` | `manual_correction` | `imported`), `q` (search name / key / code, ≤ 200), `per_page` (1–200, default 50), `page`.
- **200:** paginated entries: `id`, `original_name`, `normalized_key`, `account_code`, `budget_item`, `row_type`, `main_class`, `sub_class`, `source`, `confidence`, `taxonomy_version`, `in_use` (whether the lookup uses it today), `hit_count`, `created_at`, `updated_at`. Entries do not record the provider or model: a trusted cached answer is reused whichever provider produced it.

### `DELETE /classification-cache/entries/{id}`
Removes an entry: the account goes back to the AI next time.
- **204:** deleted. **404:** unknown entry.

## Accountant-file imports

| Method | Path | What it does |
|---|---|---|
| GET | `/classification-cache/imports` | Previous imports, newest first |
| POST | `/classification-cache/import` | Upload an accountant-classified file (read + mapped in the background) |
| GET | `/classification-cache/imports/{id}` | An import's status (poll) and report |
| GET | `/classification-cache/imports/{id}/mappings` | The mappings to review (paginated, filterable) |
| PATCH | `/classification-cache/imports/mappings/{mappingId}` | Approve, change or skip one mapping |
| POST | `/classification-cache/imports/{id}/mappings/approve` | Approve picked mappings, or all matching the filters |
| POST | `/classification-cache/imports/{id}/apply` | Write the approved rows to the memory («تطبيق») |

**Import object** (list, upload, show): `id`, `file_name`, `status` (`queued` | `running` | `pending_review` | `applied` | `failed`), `stage` (`reading` | `budget` | `notes`), `progress {done, total}`, `error` (Arabic), `rows` (row counts), `provider` / `model` (the AI provider and model the mapping asked; `null` when no value needed the AI), `created_at`, `applied_at`, `status_url`, `review_url` (the review page).

### `GET /classification-cache/imports`
- **200:** array of import objects (up to 100).

### `POST /classification-cache/import`
- **Body** (multipart): `file` (`.xlsx` / `.xls` / `.csv`; first sheet with at least «اسم الحساب» and «الميزانية»), optional `force=1` to upload the same file again.
- **202:** the import, `queued`, with any `warnings` about missing recommended columns.
- **409:** the same file was already imported: `{message, duplicate_of}`. Ask the user, then send again with `force=1`.
- **422:** not a spreadsheet, or a required column is missing (Arabic message).

### `GET /classification-cache/imports/{id}`
- **200:** the import object plus `report`: row counts (`rows.total`, `mapped`, `approved`, `ready` = approved and not written yet, `written`, `pending_review`, `kept_existing`, `cache_entries`, `skipped`), the review summary (`review.mappings`, `reviewed`, `flagged_unreviewed`, …), skipped rows by reason, the open accounting decisions (`policies`), and the name-layer preview. For an import created by `import-export`, `report` is the transfer report instead.
- **404:** unknown import.

### `GET /classification-cache/imports/{id}/mappings`
Each distinct «الميزانية» value (`kind=budget`) or «الإيضاحات» value (`kind=note`), mapped once.
- **Query:** `kind`, `decision` (`map` | `route` | `skip`), `flagged`, `unreviewed`, `low` (confidence under the memory minimum), `q` (search, ≤ 200), `per_page` (1–500, default 100), `page`. Booleans as `1`.
- **200:** paginated mappings, most rows first: `id`, `kind`, `value`, `decision`, `main_class`, `sub_class`, `method`, `confidence`, `reason`, `flag`, `reviewed`, `rows`, `samples` (up to 3 account names), `active_sub` / `active_main` (what the rows get today), `policy`, `policy_mode`.
- **404 / 422.**

### `PATCH /classification-cache/imports/mappings/{mappingId}`
The accountant's decision on one mapping.
- **Body** (JSON): `{}` to approve as is; `{"decision": "skip"}`; `{"decision": "map", "main_class": "..."}` (budget) or `{"decision": "map", "sub_class": "..."}` (note); `{"decision": "route"}` (budget only).
- **200:** the updated `mapping` and the rebuilt `report`.
- **404:** unknown mapping. **409:** the import is still being read, or failed. **422:** invalid decision or class.

### `POST /classification-cache/imports/{id}/mappings/approve`
- **Body** (JSON): either `{"ids": [1, 2, ...]}` (1–1000), or `{"all": true}` plus the list's filters (`kind`, `decision`, `q`, `flagged`, `low`) to approve **every not-yet-reviewed** mapping matching them, on all pages.
- **200:** `approved` (count, can be 0) and the rebuilt `report`.
- **404 / 409 / 422** as above.

### `POST /classification-cache/imports/{id}/apply`
Writes the rows whose mappings are all approved to the memory. Can be run again as more get approved. It never overwrites a manual correction or an AI answer.
- **200:** `applied` (`cache_entries`, `kept_existing`, `key_conflicts`, `pending_review`, `name_layer`) and the rebuilt `report`.
- **404 / 409** (not ready yet, or failed).

## Export and transfer

| Method | Path | What it does |
|---|---|---|
| GET | `/classification-cache/export.xlsx` | Excel file of the memory for the accountant |
| GET | `/classification-cache/export.json` | Everything, as JSON, to move to another installation |
| POST | `/classification-cache/import-export` | Load such a JSON file (no AI calls) |

- **Export filters** (both): `source` (`ai` | `manual_correction` | `imported`), `from` / `to` (`YYYY-MM-DD`, on the last change). **422** for an invalid filter.
- **`export.xlsx` → 200:** an `.xlsx` download (RTL, Arabic columns, plus a name-layer sheet).
- **`export.json` → 200:** a `.json` download: `format`, `format_version`, `taxonomy_version`, `taxonomy_subclasses`, `cache_entries`, `budget_mappings`, `note_mappings`, `name_layer`.
- **`import-export`** body (multipart): `file` (the JSON). **200:** what was added, updated, kept and skipped (`entries`, `budget_mappings`, `note_mappings`, `name_layer`, `taxonomy_version`, `import_id`). A manual correction already here is never replaced. **422:** not a valid export, or from a newer version.

## Companies

Company profiles for the financial statements, reused across years. Saudi, under IFRS for SMEs, in SAR, only for now; a limited liability company or a sole proprietorship. No auth yet.

| Method | Path | What it does |
|---|---|---|
| GET | `/companies` | List profiles (paginated), most recently changed first |
| POST | `/companies` | Create a profile |
| GET | `/companies/{id}` | One profile |
| PATCH | `/companies/{id}` | Change some fields |

- **Fields:** `name_ar` (required on create), `name_en`, `legal_form` (`llc` a company, `sole_proprietorship` an establishment (مؤسسة); default `llc`, unconfirmed until the registration or the accountant gives it), `country` (`SA`), `framework` (`ifrs_for_smes`), `currency` (`SAR`), `cr_number`, `cr_date_hijri` (text, stored exactly as entered, never converted), `cr_date` (`YYYY-MM-DD`), `unified_number`, `city`, `head_office`, `activity`, `branches` (list of strings), `fiscal_year_end_month` (1–12, default 12), `fiscal_year_end_day` (default 31; must exist in the month, 29 February allowed).
- **Response:** every field, plus `id`, `profile` (an object: policy choices and useful lives filled by later steps; read-only; `legal_form_source`: `registration` | `user` once the legal form is known, `registration`: what the commercial registration gave, with `legal_form_text`, `legal_form` (`llc` | `sole_proprietorship` | `other`), `owner_name`), `legal_form_known` (bool), `articles_required` (true for a company, false for a sole proprietorship, null while the legal form isn't known: the articles are then optional; they never stop the work), `created_at`, `updated_at`.

### `GET /companies`
- **Query:** `search` (names, CR and unified numbers, ≤ 200), `per_page` (1–100, default 25), `page`.
- **200:** paginated companies. **422:** invalid filter.

### `POST /companies`
- **201:** the company. **422:** missing `name_ar`, a value out of scope, a bad date, or a year-end day the month lacks.

### `GET /companies/{id}` · `PATCH /companies/{id}`
- **PATCH body:** any of the fields; only those sent change. A changed month is checked against the stored day.
- **200:** the company. **404:** `الشركة دي مش موجودة.` **422** (PATCH) as for create, or `name_ar: null`.

### `POST /companies/{id}/registration`

The company's commercial registration (PDF or image, `multipart/form-data` field `file`, ≤ 10 MB, or `client_file_id` for a file the client uploaded). The AI copies the number, name, activity, city, dates, the legal form as printed and a sole proprietorship's owner; PHP places the legal form: a company or a sole proprietorship becomes the company's (`legal_form_source: registration`), another form (joint stock…) leaves it unknown. Empty facts are filled; a sole proprietorship's pending requests for the articles of association become not applicable. **200:** the company, plus `warning` (the registration's name differs from the company's, or null). **422** on `registration_file`: not readable, not a registration, no ten-digit number, or another client's number.

### `POST /companies/{id}/articles` · `DELETE /companies/{id}/articles`

The company's articles of association (a PDF, `multipart/form-data` field `file`; 422 for another type or over `PRIOR_PDF_MAX_KB`). The AI copies the partners; the answer is the company with `articles`: `partners` (`name`, `nationality`, `group`: `saudi` | `gulf` | `foreign` | `unknown`, `shares`, `share_value`, `percentage`, `ownership` in percent), `capital`, `shares_total`, `share_value`, `zakat_share` (the Saudi and Gulf owners' share, null when unclear), `name`, `read_at`. A provider failure answers 502 and keeps nothing. A second upload replaces the first; `DELETE` forgets the file and what was read.

## Financial reports

Financial statements built from a finished classification run ([docs/14](14-financial-statements.md)). All amounts come from the run, the user or a computation in PHP; no AI. No auth yet. Versions are frozen copies; the Excel export is made from one (PR 14).

| Method | Path | What it does |
|---|---|---|
| GET | `/financial-reports` | List reports (paginated), newest first |
| POST | `/financial-reports` | Start a draft report from a run |
| GET | `/financial-reports/{id}` | A report's state |
| PATCH | `/financial-reports/{id}` | Change the company or the period |
| GET | `/financial-reports/{id}/intake` | The fields this report asks the user |
| PATCH | `/financial-reports/{id}/inputs` | Enter or change some inputs |
| PUT | `/financial-reports/{id}/account-overrides` | Replace the account overrides |
| PUT | `/financial-reports/{id}/adjustments` | Replace the adjusting entries |
| PUT | `/financial-reports/{id}/prior-year` | Set where the comparatives come from |
| POST | `/financial-reports/{id}/prior-year/statements-file` | Upload last year's issued statements (Excel or PDF) for review |
| GET | `/financial-reports/{id}/prior-year/statements-file` | The PDF of last year's statements, to look at its pages |
| PUT | `/financial-reports/{id}/prior-year/statements-file/pages` | Confirm the statements' pages in the PDF and read them |
| GET / POST | `/statement-synonyms` | The synonyms of the statements' lines; add one |
| PUT / DELETE | `/statement-synonyms/{id}` | Change or delete a synonym |
| POST | `/financial-reports/{id}/suggestions` | Decide movement suggestions, enter movements |
| PATCH | `/financial-reports/{id}/notes` | Show or hide notes and policies, reword paragraphs |
| PUT | `/financial-reports/{id}/acknowledgements` | Acknowledge warnings |
| POST | `/financial-reports/{id}/versions` | Freeze the report as a draft or final version |
| GET | `/financial-reports/{id}/versions` | List the versions |
| GET | `/financial-reports/{id}/versions/{version}` | One version with its payload |
| GET | `/financial-reports/{id}/versions/{version}/export.xlsx` | The version's statements as an Excel file |
| GET | `/financial-reports/{id}/versions/{version}/export.docx` | The version's notes as a Word file |
| GET | `/financial-reports/{id}/versions/{version}/export.pdf` | The version's statements and notes as a PDF |

- **Summary** (list items, and part of the state): `id`, `status` (`draft` \| `final`), `company_id`, `classification_run_id`, `template_version`, `period_start`, `period_end`, `comparative_start`, `comparative_end` (null until the prior-year step; null in a first year), `last_computed_at`, `finalized_at`, `created_at`, `updated_at`, `status_url`.
- **State:** the summary plus
  - `company` (the profile, or null);
  - `layout`: `groups` (the column groups the file really has), `layout` (`C` \| `OC` \| `OMC`), `closing_derived`, `empty_groups`, `families`, `partial_tb`, `unclassified`, `needs_review`, `post_closing`;
  - `mapping`: `mapped` (count), `unmapped[]` (`row`, `reason`: `unclassified` \| `no_rule` \| `choose`, `candidates`, `message` for a choice, `account_number`, `account_name`), `excluded[]` (rows left out: `reason` `possible_parent` / `parent_level`, parents the classifier found, or `user`, with its `note` and `original` line), `proposals[]` (periodic stock re-mappings proposed, never applied), `parent_proposals[]` (probable parents: `{row, rows, current_line, message, account_number, account_name}`, counted and pending until excluded or kept);
  - `statements`: `financial_position`, `comprehensive_income` and `cash_flows` (lines `cf.*`, inflows +), each a list of rows in template order: `{type: heading, label_ar}`, `{type: line, key, label_ar, input, current, comparative, rows, check_codes, note}` (`note`: the number of the line's note, «إيضاح», when shown; balance sheet and income statement), `{type: total, key, label_ar, current, comparative, sum}`. `comparative` is absent in a first year. `current` is a figure `{amount, origin, status, refs}`; a missing figure has `amount: null`. Balance-sheet lines are positive on their own side; the income statement shows income +, expenses −. `changes_in_equity`: `{columns: [{key, label_ar, line_key}], current: [{key, label_ar, cells: {column: figure}}], comparative}` (`comparative` null in a first year) ([docs/14](14-financial-statements.md#changes-in-equity-and-cash-flow-pr-10));
  - `checks`: `{key, code, title_ar, severity, scope, line_key, rows, expected, actual, difference, message_ar, acknowledged}` (+ `group` / `amount` / `input_key` / `entry`); show `title_ar`, never the code, codes in [docs/14](14-financial-statements.md#checks-reportchecks). `key` identifies the check, amounts included (PR 12); `acknowledged`: a warning the user acknowledged;
  - `missing`: figures that can't be built: `{key, reason, input_key}`, e.g. `{key: comprehensive_income, reason: post_closing_tb}`, `{key: is.oci_total, reason: input_required, input_key: oci.has_items}`, a movement cell `{key: ppe.additions.vehicles, reason: movement_required | suggestion_rejected}`, `{key: ppe.accumulated, reason: category_split_required}`, a shown note with a figure or wording missing `{key: notes.<note>, reason: note_incomplete}`, or the company's details `{key: notes.company, reason: company_required | company_profile_incomplete}`;
  - `inputs` (object), `required_inputs` (required fields still empty), `account_overrides`, `adjustments` (numbered entries; a line on an account also has its `account_number` and `account_name`, PR 14);
  - `mapping.overridden` (PR 13c): each row the user moved (a line or shares, or the loans' contra tag), `{row, account_number, account_name, lines, original: {lines, reason}}`: where it is now, and where the classification alone puts it (`lines` null with the reason it has no line);
  - `comparative`: `{source, prior_report_id, prior_run_id, restated, restatement_note, figures, changes_in_equity, cash_flows, notes, schedules, upload, entry}`; `upload` (PR 17) is the file of last year's issued statements waiting for review, else the one in use with the choices made on it (`choices`), or null; `entry` (PR 13a) is what `PUT …/prior-year` accepts as typed as the report stands: `figures` (line keys), `changes_in_equity` `{rows, columns}`, `cash_flows` (line keys), `notes` (note → this year's classes), `build_up`, `schedules` (cell keys); `defaulted` (PR 13c) is what the comparatives count as 0 because it was typed by hand and left empty, in the same keys: `figures`, `changes_in_equity` (`[{row, column}]`), `cash_flows`, `notes` (note → classes; `<note>_build_up` → parts), `schedules` (a section with nothing typed is missing instead, and isn't listed);
  - `schedules`: the notes' movement tables `{key, kind: asset | provision | loans, title_ar, line_key, columns: [{key, label_ar}], rows: [{key, label_ar, cells: {column: figure}}], split?, increase?, decrease?}`; `suggestions`: `{key, schedule, row, column, label_ar, amount, basis_ar, rows, status: pending | confirmed | edited | rejected, value}`; `movement_decisions`: `{key: {action, amount?}}` ([docs/14](14-financial-statements.md#movement-schedules-pr-9));
  - `notes` (PR 11): every note of the template in order, `{key, number, visible, visibility: always | auto | forced_on | forced_off, title_ar, references, blocks}`; `blocks` are the shown paragraphs `{type: text, key, style, number, text_ar, template_ar, edited, outdated}` (`number`: a heading numbered under its note, «5-1», else null) and tables `{type: table, rows: [{cells: [{text_ar} | {amount: figure}]}]}` (cells right to left; a value still missing shows «[ناقص]»). `note_options`: `{visibility, texts}` (`PATCH …/notes`). `narrative`: what fills `notes.docx` for the export, `{blocks: {name: kept}, values: {placeholder: text | figure | null | false}, items: {placeholder: [values]}, note_numbers, last_note_number}`. `going_concern`: the indicators hit, `[{key, amount}]` ([docs/14](14-financial-statements.md#notes-and-narrative-pr-11));
  - `run_changed`: the run's result changed (a row corrected) after the report last took it in; check `run_changed` until acknowledged;
  - (PR 12) `acknowledgements`: `[{key, note, acknowledged_at}]`; `can_finalize` and `blocking`: `[{reason: errors | warnings_unacknowledged | missing | pending_suggestions | pending_mappings, label_ar, count}]`, what stops a final; `versions`: `{count, latest, final}` (each `{version, kind, created_at}` or null; `final` is the one in force); `changed_since_final`: null without a final, else whether what the statements and notes show differs from it ([docs/14](14-financial-statements.md#versions-and-the-final-pr-12)).

  The state is read from the run as it is now.

### `GET /financial-reports`
- **Query:** `company_id`, `classification_run_id` (UUIDs), `per_page` (1–100, default 25), `page`.
- **200:** paginated summaries. **422:** invalid filter.

### `POST /financial-reports`
- **Body:** `classification_run_id` (required), `company_id` (optional), `period_start`, `period_end` (`YYYY-MM-DD`, end after start).
- **201:** the state. Drafts are allowed with unclassified or unmapped rows; those block only a final version.
- **409:** the run isn't `done`: `{message, run_status}`.
- **422:** missing field, unknown run or company, bad date.

### `GET /financial-reports/{id}`
- **200:** the state. **404:** `التقرير ده مش موجود.`

### `PATCH /financial-reports/{id}`
- **Body:** `{company_id?, period_start?, period_end?}`, partial; `company_id: null` detaches the company.
- **200:** the state. **404.** **422:** an unknown company, a bad date, an end not after the start, a start not after the comparative period, a company other than the prior report's (change the prior year first).

### `GET /financial-reports/{id}/intake`
- **200:** `{fields: [{key, type, required, label_ar, help_ar, options, option_labels, default, check, source, value, columns?}]}` (`option_labels`: each choice's Arabic name; `type` `date` is `YYYY-MM-DD`, `choices` a list of options, `table` a list of rows with the `columns`): only the fields the run's layout, balances and notes make relevant ([docs/14](14-financial-statements.md#the-reports-own-part-pr-7)). **404.**

### `PATCH /financial-reports/{id}/inputs`
- **Body:** `{inputs: {key: value}}`; only the keys sent change, `null` removes one. An answer that opens other questions can go with them (`oci.has_items`, `first_application.this_period`, `first_application.effect`, `going_concern.decision`, `supplier_finance.has`).
- **200:** the state. **404.** **422** (errors keyed `inputs.<key>`): a key the report doesn't ask, a wrong type, a zakat charge ≤ 0, disposal proceeds, a loans' current portion, proposed dividends or supplier-finance amounts below 0, a date not `YYYY-MM-DD`, a blank text (the zero-zakat reason is mandatory), a text too long (500; narrative answers 2000; useful lives 20), an unknown or repeated choice, a table row missing a required column or with a wrong value (shares and share value above 0, row numbers whole), or both a zakat charge and a zero reason.

### `PUT /financial-reports/{id}/account-overrides`
- **Body:** `{overrides: [{row, line_key? | shares?, category?, tags?} | {row, exclude: true, reason}]}`, replacing the list (`[]` clears); at least one of a line, a category and tags, or an exclusion: `exclude: true` with a `reason` (3–500 characters) and nothing else, which leaves the account out of the statements and of the trial balance's check. Shares: two or more lines, adding up to 1. `category`: a category of the asset note of the row's line (e.g. `vehicles`). `tags`: `deferred_finance_cost` (netted against the loans), `provision_charge` (the other provisions' expense), `not_disposal` (a gain or loss on disposal kept as other income or expenses, pending confirmation).
- **200:** the state. **404.** **422:** a repeated row, both or neither of `line_key` / `shares`, a row not in the trial balance, an unknown line, shares not adding up to 1, an unknown tag, a category not of the row's note, an exclusion without a reason or with anything else on the row.

### `PUT /financial-reports/{id}/adjustments`
- **Body:** `{entries: [{reason, lines: [{row | line_key, debit, credit}]}]}`, replacing the list (`[]` clears). Each entry: a reason (3–500), 2–50 lines, debits = credits; each line one of `row` / `line_key` and one of debit / credit above 0.
- **200:** the state, entries numbered from 1. **404.** **422:** unbalanced, incomplete, a row not in the trial balance, an unknown line.

### `PUT /financial-reports/{id}/prior-year`
- **Body:** `{source: none | first_year | report | run | manual | statements, prior_report_id?, prior_run_id?, figures?, comparative_start?, comparative_end?, restated?, restatement_note?}`. `report` needs `prior_report_id` (same company, ending before this period); `run` needs `prior_run_id` (a finished run, not this report's) and takes OCI `figures` only (`{}` for none); `manual` needs `figures` (line key → amount as displayed). Dates: `run` / `manual` only (default: the year before). `restated: true` needs `restatement_note`. `run` / `manual` also take the comparative changes in equity (`changes_in_equity`: row → column → amount) and cash flow (`cash_flows`: `cf.*` line → amount) typed from last year's issued statements; cells and lines not given are 0. From last year's notes (PR 11): `manual` takes the item tables (`notes`: note → class name → amount, and `cost_of_revenue_build_up`: `opening`, `purchases`, `purchase_costs`, `closing`), `run` / `manual` the movement tables (`schedules`: `<schedule>.<row>` or `<schedule>.<row>.<column>` → amount, every row but the closing ones); a class or cell not given is then 0.
- `statements` (PR 17) uses the file uploaded below (or, saving again, the one in use): `rows` (`<block>:<row id>` → a line or total key, or `ignore`; required for every row with an amount that isn't an exact match), `columns` (block index → column letter; required where the file doesn't name the year), `expenses_shown_positive` (required when the file shows every expense positive; true turns them negative). It also takes the typed `changes_in_equity`, `cash_flows` (when the file has no cash flow), `notes` and `schedules`, the dates and `restated`.
- **200:** the state ([docs/14](14-financial-statements.md#prior-year-pr-8)). **404.** **422:** any of the above broken.

### `POST /financial-reports/{id}/prior-year/statements-file` (PR 17; PDF)

A PDF (≤ 20 MB, ≤ 150 pages) is kept and its statements' pages looked for by the AI in the background: **202**, `comparative.upload` with `kind: pdf`, `status` (`locating` → `pages`, or `failed` with `error`), `pages_total`, `pages` and `found` (statement → `{from, to}`), `cost_usd`; poll `GET /financial-reports/{id}`. Then `PUT …/statements-file/pages` `{pages: {financial_position: {from, to}, …}}` with the pages as confirmed: **202**, `reading` → `ready` with `blocks` as below, each row with `page` and `check` (a total that doesn't equal the rows it adds up: `[{column, sum, printed}]`); **409** while the file is being worked on or when no PDF waits; **422** pages outside the file, last before first, or no statement that is read. `GET …/statements-file` returns the PDF. An Excel file:
- **Body** (multipart): `file`, `.xlsx` / `.xls`, ≤ 10 MB: last year's issued statements.
- **200:** the state, with the file read in `comparative.upload` (`in_use: false`): per statement its sheet, year column (`column`, `column_certain`) and rows (`label`, `values` by column: `{amount, raw, status: ok | unread | empty}`, the proposed `match` and its `certainty`: `exact | probable | none`). Nothing changes until `PUT …/prior-year` with `source: statements`. **404.** **422:** not Excel, unreadable, or no statement found (a title row «قائمة …» above each). No auth yet.

### `POST /financial-reports/{id}/suggestions`
- **Body:** `{decisions: [{key, action: confirm | edit | reject | reset, amount?}]}`, applied in order. Keys: movement-schedule cells, `soce.<row>` (changes in equity, amounts positive) and `cf.<line>` (cash flow, as displayed: outflows −). `confirm` / `reject` need a suggestion; `edit` takes `amount` (any movement cell, e.g. those listed in `missing` without movement columns); `reset` drops the decision.
- **200:** the state. **404.** **422:** a key that isn't a movement of the report, `confirm` / `reject` with no suggestion, `edit` without an amount, an amount with another action.

### `PATCH /financial-reports/{id}/notes`
- **Body:** `{visibility?: {key: on | off | auto}, texts?: {paragraph: text | null}}`, at least one; only the keys sent change. `visibility`: a note with a condition (`ppe`, `inventory`, `going_concern`, …) or a policy (`policy_ppe`, `policy_subsidiaries`, …); `auto` drops the choice. Notes shown always (incorporation, basis, policies, capital, financial risk, subsequent events, approval) can't be hidden. `texts`: a shown paragraph's key (`notes[].blocks[].key`, e.g. `approval.1`) → the user's wording (3–5000 characters), `null` back to the template's.
- **200:** the state; notes renumbered, the statements' note column with them ([docs/14](14-financial-statements.md#notes-and-narrative-pr-11)). **404.** **422:** neither field; an unknown note or policy, or one shown always; a choice other than on / off / auto; an unknown paragraph, one not shown now, a blank or too short text.

### `PUT /financial-reports/{id}/acknowledgements`
- **Body:** `{acknowledgements: [{key, note?}]}`, replacing the list (`[]` withdraws all). `key`: a warning's `key` in `checks` now; `note` up to 500 characters. Acknowledging `run_changed` takes the run as it is now instead of being stored. A key kept from before keeps its `acknowledged_at`.
- **200:** the state. **404.** **422:** no list; a key that isn't a warning of the report now (an error, info, unknown, or a warning whose amounts changed); a note too long.

### `POST /financial-reports/{id}/versions`
- **Body:** `{kind: draft | final}`.
- **201:** the version `{report_id, version, kind, superseded_by, created_at, inputs_hash, run_result_hash, content_hash, payload}`; `payload` is the state when made, with `report_id`, `version`, `kind` (no `versions` / `changed_since_final`). A final sets the report's `status: final` and `finalized_at`; a later final supersedes it. **404.** **422:** a final blocked: `{message, checks, blocking}` (`checks`: the errors and unacknowledged warnings); or a bad `kind` (validation error).

### `GET /financial-reports/{id}/versions`
- **200:** `{data: [version without payload]}`, newest first; `superseded_by` on a final superseded by a later final. **404.**

### `GET /financial-reports/{id}/versions/{version}`
- **200:** the version with its payload, exactly as made. **404:** `الإصدار ده مش موجود.` (or the report's 404).

### `/statement-synonyms` (PR 17)
- **GET:** `{data: [{id, statement, line_key, line_label_ar, label, updated_at}], lines: {statement: [{key, type: line | total, label_ar}]}}`.
- **POST** / **PUT `/{id}`:** `{line_key, label}`: a line or total of the financial position, comprehensive income or cash flows, and the wording in Arabic. **201** / **200:** the synonym. **422:** unknown line, no Arabic, a line's own label, or a wording the statement already has. **404** (PUT): unknown synonym.
- **DELETE `/{id}`:** **204**; **404.** A file already confirmed keeps its matches. No auth yet.

### `GET /financial-reports/{id}/versions/{version}/export.xlsx` (PR 14)
- **200:** the xlsx, made from the version's stored payload (the same version gives the same bytes). `Content-Disposition`: `filename*` «القوائم المالية - <company> - <period end> - نسخة <n> (<kind>).xlsx», ASCII fallback `financial-statements-<period end>-v<n>-<kind>.xlsx`. Contents in [docs/14](14-financial-statements.md#excel-export-pr-14). **404:** `الإصدار ده مش موجود.` (or the report's 404). No auth yet.

### `GET /financial-reports/{id}/versions/{version}/export.docx` (PR 15)
- **200:** the docx of the notes, made from the version's stored payload (the same version gives the same bytes). `Content-Disposition`: `filename*` «الإيضاحات المتممة للقوائم المالية - <company> - <period end> - نسخة <n> (<kind>).docx», ASCII fallback `financial-statements-notes-<period end>-v<n>-<kind>.docx`. Contents in [docs/14](14-financial-statements.md#word-export-pr-15). **404:** `الإصدار ده مش موجود.` (or the report's 404). No auth yet.

### `GET /financial-reports/{id}/versions/{version}/export.pdf` (PR 16)
- **200:** the PDF of the statements and notes, made from the version's stored payload (the same version gives the same bytes). `Content-Disposition`: `filename*` «القوائم المالية والإيضاحات المتممة لها - <company> - <period end> - نسخة <n> (<kind>).pdf», ASCII fallback `financial-statements-<period end>-v<n>-<kind>.pdf`. Contents in [docs/14](14-financial-statements.md#pdf-export-pr-16). **404:** `الإصدار ده مش موجود.` (or the report's 404). No auth yet.
