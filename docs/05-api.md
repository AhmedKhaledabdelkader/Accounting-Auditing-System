# 5. API reference

Base URL (local): `http://127.0.0.1:<SERVER_PORT>` (default `8000`, set in `.env`). No authentication.

Every API route is versioned under **`/api/v1`** (`apiPrefix` in `bootstrap/app.php`); the old unversioned `/api/...` paths return `404 Route not found`. A breaking change would get `/api/v2` next to it. Only the Swagger UI page stays at `/api/docs`. The URLs the API returns (`status_url`, `cancel_url`, `confirm_url`) are versioned too.

## Background jobs (used by the page)

Large files take minutes, so the page uses a queued job and polls it. Needs a running `php artisan queue:work`.

**`POST /api/v1/classify/jobs`**: same `file` field and validation as below, and an optional `company_id` (the company the trial balance belongs to: its code groups apply, the run's `company_id` returns it, and a report made from the run starts with it; the sync endpoint takes it too). `meta.code_group_proposals` lists code groups worth marking ([17](17-classification-rules.md#code-groups)). Returns **202**:

```json
{ "id": "01a0…", "status": "queued", "stage": null, "progress": {"done": 0, "total": 0},
  "error": null, "provider": null, "model": null, "status_url": "/api/v1/classify/jobs/01a0…", "cancel_url": "/api/v1/classify/jobs/01a0…/cancel",
  "result": null, "cache": null, "cost": null, "confirmation": null }
```

**`GET /api/v1/classify/jobs/{id}`**: the same shape.
- `status`: `queued` → `running` → `done` | `failed`, or `running` → **`needs_confirmation`** → (confirm) `queued` → `running` → `done`. Ends also as `cancelled` or `expired`.
- `stage` is `reading`, `structure`, `classifying` or `checks`. During `classifying`, `progress` counts batches. A run waiting at `needs_confirmation` keeps `stage: "structure"`; a `failed` run keeps the last stage it reached; `done`, `cancelled` and a confirmed (re-queued) run have `null`.
- When `done`, `result` is exactly the `{data, meta}` body of the sync endpoint. When `failed` or `expired`, `error` holds the message (e.g. `No account rows found in the file.`, `AI provider error, please retry.`, `Gemini API key is missing. Set GEMINI_API_KEY in .env, then run php artisan queue:restart.`).
- `provider`, `model` (nullable): the AI provider and model the run started with (e.g. `anthropic`, `claude-haiku-4-5`); a run resumed after confirmation shows the ones it resumed with. `null` while `queued` before its first start, and for runs from before these fields. Additive; `result.meta.provider` / `meta.model` are unchanged.
- `cache` (only when `done`, `null` for runs before the cache): `{rows_from_cache, rows_sent_to_llm, estimated_savings_usd, savings_is_estimate: true, savings_basis}`; `savings_basis` is `this_run`, `recent_runs`, `none` (nothing from the cache) or `unknown`. The savings are an **estimate** (cost per row sent × rows from the cache), never an amount billed.
- `cost` (when `done` or `failed`): `{cost_usd, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, llm_requests}`, from the provider's usage and config `prices` in effect on the UTC day the run first started; `cost_usd` is `null` when the model has no price there (never a guess). Failed attempts count in `llm_requests`.
- `cancel_url` is set only while the run can be cancelled (`queued`, `needs_confirmation`), otherwise `null`.
- An unknown id returns 404 `{"message": "Classification job not found."}`. The uploaded file is deleted when the run ends.

**Waiting for confirmation.** A queued run stops at `needs_confirmation` when the column mapping has an error left after the AI retry, or a doubt listed in `structure_checks.pause_on` (see [03 § structure checks](03-classification-pipeline.md#step-3-structure-detection-call-1-once-per-file)). `confirmation` then holds:

```json
{ "structure": {"sheet": 1, "header_rows": [1], "first_data_row": 2, "columns": {"account_name": "B", "closing_debit": "G", "…": null}},
  "issues": [{"check": "rows_skipped", "severity": "doubt", "field": null, "column": null, "user": "الصفوف 2، 3 في الملف قبل أول صف بيانات…"}],
  "sheet": "ميزان المراجعه",
  "columns": [{"letter": "B", "header": "اسم الحساب", "samples": ["الصندوق", "البنك", "عملاء"], "fields": ["account_name"]}],
  "rows": [{"row": 1, "cells": {"A": "رقم الحساب", "B": "اسم الحساب"}}],
  "fields": {"account_number": "رقم الحساب", "…": "…"},
  "expires_at": "2026-10-01T12:00:00+00:00",
  "confirm_url": "/api/v1/classify/jobs/01a0…/structure" }
```

`issues` items carry only `{check, severity, field, column, user}` (`ClassifyTrialBalance::shown`). `columns` covers every column of the sheet (up to 60, 3 sample values each), and `rows` the first 12 rows. This is client data: it is cleared, with the file, as soon as the run is confirmed, ends, is cancelled or expires. The file and the preview wait at most `classification.confirmation_hours` (24) — see `classification:prune`.

**`POST /api/v1/classify/jobs/{id}/structure`**: the confirmed or corrected mapping. The sheet and the other sheets stay as detected.

```json
{ "header_rows": [1], "first_data_row": 2, "columns": {"account_number": "A", "account_name": "B", "closing_debit": "G", "closing_credit": "H"} }
```

- `columns`: field → column letter (or `null`); fields from `AccountExtractor::FIELDS`, letters `A`–`ZZZ`. Number and name in one cell: give both the same letter.
- **202**: accepted; `status` is `queued` again and the run continues from classification with this mapping. Its remaining doubts become warnings in the result.
- **422** `{message, problems}`: the mapping has an error the user can fix in the panel and the extraction can't work around (`sheet_exists`, `required_fields`, `column_exists`, `duplicate_mapping`, `first_data_row`, `header_rows`); the run keeps waiting. `acknowledge_doubts` doesn't override these. **422** `{message, errors}`: invalid body.
- **422** `{message, doubts, needs_acknowledgement: true}`: the mapping raises **pausing doubts the user wasn't shown** (e.g. a first data row that now skips accounts), or **measurement errors** that never block a mapping the user confirmed (`ClassificationService::ACKNOWLEDGEABLE`: `amount_numeric`, `name_fill`, `name_text`, `side_values`, `header_contradiction`, `groups_swapped`, `sides_swapped`, `first_row_is_header`, `other_sheets`). The run keeps waiting; the page shows them with a "confirm anyway" button, which sends the same body with `"acknowledge_doubts": true`. Doubts already shown never ask again; measurement errors ask every time. Both are logged with the confirmation (`doubts_acknowledged`, `errors_overridden`).
- Overridden errors come back in the result's `meta.warnings`: `groups_swapped` / `sides_swapped` first, as ⛔ (every balance would be wrong if the check is right), the others as normal warnings. An overridden `other_sheets` error leaves that extra sheet out of the run (the panel can't edit it).
- **409**: the run is not waiting (already confirmed, cancelled, expired…). **404**: unknown id.

**`POST /api/v1/classify/jobs/{id}/cancel`**: cancels a `queued` or `needs_confirmation` run; the file and the preview are deleted at once. **200** with `status: "cancelled"`; **409** if the run is already processing or finished; **404** if unknown.

## `POST /api/v1/classify/trial-balance` (synchronous)

Classify every account in a trial balance file of any layout, in the request itself. Fine for small files; large files can hit the 300 s request limit, so use the jobs endpoints for those.

**Request**: `multipart/form-data`

| Field | Type | Rules |
|---|---|---|
| `file` | file | required; `xlsx`, `xls` or `csv`; ≤ 10 MB |

```bash
curl -X POST http://127.0.0.1:8000/api/v1/classify/trial-balance \
  -H "Accept: application/json" \
  -F "file=@trial-balance.xlsx"
```

**Response 200**

```json
{
  "data": [
    {
      "account_number": "110101010",
      "account_name": "النقد في الصندوق",
      "financial_statement": "قائمة المركز المالي",
      "main_class": "النقد ومافي حكمه",
      "sub_class": "نقدية بالصندوق",
      "opening_debit": 10340,
      "opening_credit": 0,
      "period_debit": 411756.57,
      "period_credit": 423270.574839,
      "closing_debit": 0,
      "closing_credit": 0,
      "classification_confidence": 98,
      "classification_reason": "اسمه صريح إنه نقدية في الصندوق",
      "data_flags": [
        {
          "type": "reconciliation",
          "difference": 1174,
          "message": "الفرق ده مطابق لقيد تسوية بمبلغ 1,174.00 (شيت قيود التسويات)",
          "matched_adjustment": { "sheet": "قيود التسويات", "amount": 1174 }
        }
      ],
      "source_note": null,
      "needs_review": true,
      "confidence": 98,
      "confidence_label": "98%",
      "note": "الفرق ده مطابق لقيد تسوية بمبلغ 1,174.00 (شيت قيود التسويات)"
    }
  ],
  "meta": {
    "total": 19,
    "needs_review": 8,
    "avg_confidence": 93.2,
    "skipped_rows": 1,
    "partial": false,
    "unclassified": { "rows": 0, "ranges": [], "failed_batches": 0, "missed_by_ai": 0 },
    "warnings": [],
    "info": ["الملف ده ميزان جزئي (فيه أصول بس)، فعدم التوازن متوقع"],
    "other_sheets": [{ "sheet": 2, "name": "قيود التسويات", "type": "adjustments", "description": "..." }],
    "adjustments": [
      { "sheet": "قيود التسويات", "row": 5, "account_name": "الصندوق", "debit": 1174, "credit": 0, "account_number": "110101010" },
      { "sheet": "قيود التسويات", "row": 6, "account_name": "جاري الشريك", "debit": 0, "credit": 1174, "account_number": null }
    ]
  }
}
```

| Field | Source | Notes |
|---|---|---|
| `row` | file | Excel row in the source sheet; rows are identified by this, since account numbers can repeat |
| `account_number`, `account_name` | file | Exactly as uploaded; `account_number` is `""` when the file has none |
| `financial_statement`, `main_class`, `sub_class` | Claude + table | Arabic values from the mapping table; `null` when unclassified |
| balance fields | file | Floats read from the cells, never from AI output |
| `classification_confidence` | Claude or cache | 0–100 per the rubric; 0 when unclassified, or when the AI gave no score (the note says so). Capped by config `confidence_caps`: at most 60 with `abnormal_opening` / `abnormal_closing`, at most 50 with `prefix_mismatch` (not for rows from a manual correction or an exact-key imported entry). Rows unified by `inconsistent_classification` carry the winning row's value; a manual correction sets 100 |
| `classification_reason` | Claude | Short Egyptian-Arabic reason (fixed fallback if not Arabic); `null` when unclassified |
| `data_flags` | PHP | `[{type, difference, message, severity?}]`, where `type` ∈ `reconciliation`, `abnormal_opening`, `abnormal_closing`, `invalid_number`, `possible_parent`, `prefix_mismatch`, `classification_mismatch`, `statement_mismatch`, `existing_classification_conflict`, `duplicate_account_number`, `inconsistent_classification`, `side_flip`, `side_contradiction`. `severity`: `"info"` (on `duplicate_account_number`, and on `side_flip` of a pair without `flag`) = low priority, doesn't set `needs_review`; `"high"` (on `statement_mismatch`: the account moved between the income statement and the balance sheet) = its message leads `note`. Signed `difference` (see [pipeline step 6](03-classification-pipeline.md#step-6-data-checks-classificationservice-php-only)). Matched reconciliations also carry `matched_adjustment` |
| `source_note` | file | Text of the remarks column (a problem with the row), or `null` |
| `existing_classification`, `existing_note_classification` | file | The file's own classification columns (e.g. `بنود الميزانية`, `إيضاحات`), or `null` |
| `existing_main_classes` | Claude | Main classes the file's value was mapped to (the comparison basis for `classification_mismatch`), or `null` |
| `note_reference` | file | The file's note number for the account (a `إيضاح` column, e.g. `5`, `15/أ`), or `null`. A reference, not a remark: **never sets `source_note` or `needs_review`** |
| `audit_request` | file | Auditor request / PBC item for the row (e.g. `عينة من اضافات الاصول الثابتة`), or `null`. **Never affects `needs_review`** |
| `adjustment_debit`, `adjustment_credit` | file | In-sheet adjustment columns (`تسوية مدين/دائن`); `0` when the file has none |
| `cache_key` | PHP | The row's classification-cache key (`code:…\|name:…\|budget:…`, or `name:…\|parent:…`); `null` when the row can't be cached. Used by the row correction ([11](11-classification-cache.md#the-key)) |
| `needs_review` | PHP | `classification_confidence < 70` OR a `data_flags` entry without `severity: "info"` OR `source_note` set |
| `confidence`, `confidence_label` | — | Kept for existing consumers; the same value as `classification_confidence` |
| `note` | — | Display text: reason (if confidence < 70) or `لم يتم تصنيف الحساب`, flag messages, `ملاحظة في الملف: …`, joined by ` \| ` |
| `meta.avg_confidence` | Claude | Average `classification_confidence` |
| `meta.groups` | file | Amount groups present: `opening`, `period`, `adjustment`, `closing` |
| `meta.skipped_rows` | — | Data rows not returned (parent, total, section, other, empty unlabelled) |
| `meta.partial` | — | `true` when some account rows came back **unclassified** (not a complete result). The run is still `done`; the page shows a red banner above the warnings |
| `meta.unclassified` | — | `{rows, ranges, failed_batches, missed_by_ai}`: how many account rows are unclassified, their row ranges (`"R104–R153"`), and how many of them by cause, in rows: their batch still failed after the retries, or the AI left them out even after the missing-only retry. These rows have `sub_class: null`, `needs_review: true` and the note `لم يتم تصنيف الحساب`. Batch failures are reported here only, not in `meta.warnings` |
| `meta.warnings` | PHP | Debit ≠ credit on a **full** trial balance, total-row mismatch, unmatched differences when an adjustments sheet exists, remarks on skipped rows |
| `meta.info` | PHP | Partial trial balance, where the imbalance is expected; rounding differences; repeated numbers with the same name; audit requests on skipped rows; side-flip counts |
| `meta.side_flips` | cache | Per side pair: `[{pair, label, rows, flagged, contradictions}]` ([11](11-classification-cache.md#side-pairs-classes-that-depend-on-the-balance-side)) |
| `meta.other_sheets` | Claude | Non-trial-balance sheets with name and type |
| `meta.provider`, `meta.model` | config | The AI provider and model that produced this result (e.g. `anthropic`, `claude-haiku-4-5`; `gemini`, `gemini-3.8-flash`) |
| `meta.usage` | provider | Tokens of every successful AI response for this file: `{requests, input, cache_write, cached, output}`. `input` excludes cache writes and reads; `cache_write` is Anthropic only (Gemini caches implicitly: `cached` only); `output` includes thinking (Gemini `thoughtsTokenCount`). A run that paused for confirmation includes its detection calls |
| `meta.adjustments` | file + matching | Adjustment lines: `account_number` (as written on the line, or `null`) and `account_row`, the trial-balance row it maps to (`null` = not in the trial balance) |

Arabic text in the raw response is `\uXXXX`-escaped (Laravel default JSON encoding).

**Errors**

| Status | Body | Cause |
|---|---|---|
| 422 | `{"message": "...", "errors": {"file": ["..."]}}` | Validation: file missing, not a file, wrong type, > 10 MB |
| 422 | `{"message": "No account rows found in the file."}` | Empty file, no data rows, or no row identified as an account |
| 422 | `{"message": "ما قدرناش نقرا أعمدة الميزان صح:\n- …", "problems": ["…"], "structure": {…}}` | The AI's column mapping still fails the structure checks after the retry (`StructureNotDetected`): one Arabic line per problem, plus the rejected mapping |
| 502 | `{"message": "AI provider error, please retry."}` | Provider 5xx/429/timeouts still failing after retries |
| 502 | `"<Provider> API key is missing. Set <VAR> in .env, then run php artisan queue:restart."` | The active provider's key is empty or `<need-to-change>`; nothing sent |
| 502 | The driver's message, else `"AI provider rejected the request: …"`. Anthropic: `"Anthropic API credit balance is too low. Add credits, then retry."`, `"Anthropic API key is invalid. Check ANTHROPIC_API_KEY."`. Gemini: `"Gemini API key is invalid. Check GEMINI_API_KEY."`, `"Gemini daily quota for <model> is used up. It resets at midnight Pacific time; retry then, or raise the quota in Google AI Studio."`, `"Gemini prepaid credit is used up. Add credit in Google AI Studio, then retry."` | Fatal provider errors: every 4xx except 408/409/429, and Gemini's used-up daily quota (not retried) |
| 405 | Laravel default | Wrong HTTP method |
| 500 | `{"status":"error","message": "..."}` | Unexpected exception (e.g. `Unknown CLASSIFIER_PROVIDER [x]. Use one of: anthropic, gemini.`) |

Validation errors are always JSON for `/api/*`.

Every endpoint, with request and response shapes, is in the OpenAPI spec at `/api/docs`. **None has authentication yet**; the cache import, export and correction endpoints must be protected before any deployment.

## Clients: follow-up by email, prepared papers, the mailbox (2026-11-19)

Documented in `public/docs/openapi.yaml` (tags *Client follow-up*, *Working papers*, *Mailbox*) and checked by `ApiContractTest`. Each needs a signed-in user (session or API key) with `clients.view` (reading) or `clients.manage` (everything else); a client of another office is a 404.

| Method | Path | What |
|---|---|---|
| GET | `/api/v1/clients/{id}/follow-up?year=` | What is missing, whether an email can go, the emails sent and received |
| POST | `/api/v1/clients/{id}/follow-up/draft` | The AI's email for the missing documents (nothing is sent; `draft` null when nothing is missing; 502 when the AI fails) |
| POST | `/api/v1/clients/{id}/follow-up/send` | Sends it from the user's own mailbox (`[REF]` added to the subject); 422 no address / no mailbox, 502 the mail server refused (in words) |
| POST | `/api/v1/clients/{id}/follow-up/replies` | A reply entered by hand (multipart, `files[]`): read by the AI, files filed in the requests |
| GET | `/api/v1/clients/{id}/prepared-papers?year=&kind=` | The 21 working papers / recalculations and where each one's preparation is |
| POST | `/api/v1/clients/{id}/prepared-papers` | Starts the AI on one paper (`key`) or all of a `kind` (202, queued) |
| GET | `/api/v1/clients/{id}/prepared-papers/{paper}/download` | The prepared workbook |
| GET / PUT / DELETE | `/api/v1/account/mail` | The user's mailbox (the password written, never read back); PUT saves and tests (SMTP, then IMAP) |
| POST | `/api/v1/account/mail/test` | A test message to the mailbox's own address |
| POST | `/api/v1/account/mail/check` | Reads the clients' replies now |

Also changed in existing responses: the opening-balance comparison (`opening_check`, `approved_opening`) calls a difference under `financial_statements.opening_tolerance` (3 riyals) `rounding` = accepted, and `opening_vs_prior_closing` is raised only from 3 riyals. The income statement's main classes are the functions (docs/16): a row's `main_class` is e.g. «المصروفات الإدارية والعمومية», its nature the `sub_class`.

## Other routes

| Method | Path | Returns |
|---|---|---|
| GET | `/api/docs` | Swagger UI (spec: `public/docs/openapi.yaml`; update it with any API change) |
| GET | `/classifier` | Upload UI (HTML) |
| GET | `/classification-cache` | Accountant imports, previous imports, export / import (HTML) |
| GET | `/classification-cache/imports/{id}/review` | Review page of one import (HTML) |
| GET | `/financial-statements/preview/{run}`, `/financial-reports/{id}/preview` | The old previews: redirect to the report's review step (`#review`; for a run, its latest report's, or a new report from it) ([14](14-financial-statements.md#pages-pr-13)) |
| PATCH | `/api/v1/classify/jobs/{id}/rows/{row}` | Manual correction of one result row of a `done` run, saved to the cache ([11](11-classification-cache.md#manual-corrections)) |
| POST / PUT / DELETE | `/api/v1/classify/jobs/{id}/rows`, `…/rows/{row}/values`, `…/rows/{row}` | Rows of a `done` run changed by hand: an account added (number, name, amounts by group, optional class; `added`), a row's number / name / amounts edited (`edited`), a row removed (`meta.removed_rows`). Each answers `{row, meta: {total, needs_review, avg_confidence, balance}}`; `balance` = debit − credit per group after the change (`ClassificationRowController`) |
| POST | `/api/v1/classify/jobs/{id}/corrections` | Several rows at once, all or nothing: `{corrections: [{row, sub_class}]}` → `{rows, corrected, remembered, not_remembered}` (the classifier page's «حفظ التعديلات») |
| GET | `/api/v1/classification-cache/explain`, `/entries`; DELETE `/entries/{id}` | The cache ([11](11-classification-cache.md#apis)) |
| GET / POST / PATCH | `/api/v1/classification-cache/import`, `/imports…` | Accountant imports: upload, status, mappings, review, approve, apply ([12](12-accountant-imports.md)). The import summary (list, upload, show) has nullable `provider` / `model`: those the mapping asked; `null` when no value needed the AI |
| GET / POST | `/api/v1/classification-cache/export.xlsx`, `/export.json`, `/import-export` | Cache transfer ([12](12-accountant-imports.md#export-and-import-of-the-cache)) |
| GET / POST / PATCH | `/api/v1/companies`, `/companies/{id}` | Company profiles for the financial statements ([api-reference](api-reference.md#companies)) |
| GET / POST / PATCH / PUT | `/api/v1/financial-reports`, `/financial-reports/{id}`, `…/intake`, `…/inputs`, `…/account-overrides`, `…/adjustments`, `…/prior-year`, `…/suggestions` | Financial reports from a finished run: create, list, state, the user's inputs, overrides, adjusting entries, prior-year source and movement decisions ([14](14-financial-statements.md#companies-and-reports-api)) |
| GET | `/` | Default Laravel welcome page |
| GET | `/up` | Health check |
| any | unknown | `404 {"status":"error","message":"Route not found"}` |

## Input file format

No fixed layout. The AI finds the structure; Laravel validates it and reads the values.

Supported:

- Any sheet in the workbook (the first 10 are previewed). `.xlsx`, `.xls`, `.csv` (delimiter auto-detected).
- Header anywhere in the first 30 non-empty rows, in any language and wording; multi-row / grouped headers; title rows above it; repeated page headers.
- Columns in any order; extra columns are ignored.
- Per group (opening / period / closing): separate debit + credit columns, **or** one signed balance column, **or** a balance column plus a debit/credit indicator column (`مدين/دائن`, `Dr/Cr`).
- Any subset of groups. Missing groups fall back to period, then closing, then opening.
- Account number and name in separate cells or in one (`1101 - الصندوق`); no account number at all.
- Parent/group accounts, section titles, subtotal and total rows (excluded from `data`, used for checks).
- Numbers: `1,234.56`, `1.234,56`, Arabic-Indic digits, `(500)`, `500-`, currency tokens, `Dr`/`Cr` suffixes, formulas (the value saved in the file; recalculated only when none was saved). A cell of only dashes (`-`, `—`, `–`, `ـ`, `--`, `(-)`) is zero, like an empty cell: no `invalid_number` flag, and not counted against an amount column.

Not supported: header deeper than the preview (30 non-empty rows), trial balances spread over several sheets, images/PDF scans.
