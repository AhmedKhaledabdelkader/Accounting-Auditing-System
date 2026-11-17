# Documentation

Documentation for the Trial Balance Classifier. Written for developers joining the project and for AI assistants that need full project context.

| # | Document | Contents |
|---|---|---|
| 1 | [Overview](01-overview.md) | What the system does, domain glossary (Arabic ↔ English) |
| 2 | [Architecture](02-architecture.md) | Layers, patterns, dependency graph, directory map |
| 3 | [Classification pipeline](03-classification-pipeline.md) | Step-by-step flow from upload to response, scoring rules |
| 4 | [Components reference](04-components.md) | Every application class: purpose, methods, behavior |
| 5 | [API reference](05-api.md) | Endpoints, request/response, errors, input file format |
| — | [API reference for the frontend](api-reference.md) | Every endpoint in one place: what it does, what to send, what comes back, and the typical flows. Full schemas: Swagger UI at `/api/docs` |
| 6 | [Configuration](06-configuration.md) | Environment variables, `config/classification.php`, the taxonomy's seed |
| 7 | [Frontend](07-frontend.md) | The `/classifier` page |
| 8 | [Setup & operations](08-setup-and-operations.md) | Install, run, test, troubleshoot |
| 9 | [Known issues](09-known-issues.md) | Bugs, dead code, risks |
| 10 | [Extending](10-extending.md) | How to add categories, change the AI, add tests |
| 11 | [Classification cache](11-classification-cache.md) | Key, lookup order, side pairs, manual corrections, what is stored |
| 12 | [Accountant imports & transfer](12-accountant-imports.md) | Importing accountant-classified files, review, «تطبيق», export / import of the cache |
| 13 | [Database schema](13-database-schema.md) | ERD, every table and relation and why it matters |
| 14 | [Financial statements generator](14-financial-statements.md) | Line catalogue and mapping rules, layout profile, line mapper, periodic-inventory pairs, aggregation, figures |
| 15 | [Users, roles and permissions](15-access.md) | Sign-in, sign-up with the admin's approval, roles made from the permission list, API keys |
| 16 | [The taxonomy and its page](16-taxonomy.md) | Where the categories live, renames (aliases, rules that follow), delete rules, a new sub class's line |
| 17 | [Classification rules](17-classification-rules.md) | The accountant's rules for balance-sheet accounts, companies' code groups, proposals, the preview |
| 18 | [Offices and the client portal](18-offices-and-client-portal.md) | Offices with their code, each office's data its own, the main portal, clients' logins, the audit checklist, uploads and chat |
| — | [Guide for the accountant](accountant-guide.md) | Plain-language summary: what goes in and out, the 9 steps, every accounting and presentation rule (numbered), how to request changes |
| — | [Statement templates](../resources/templates/sa-ifrs-smes-3/README.md) | Financial statements and notes templates (`sa-ifrs-smes-3`): placeholders, blocks, line keys, changes from the references. Used by the statements generator ([plan](plans/financial-statements-plan.md)) |

## Quick context for AI assistants

- **Stack:** Laravel 12, PHP 8.2–8.4, PhpSpreadsheet (read-only, saved formula values), AI via a `ClassifierDriver` (`AnthropicDriver` default, or `GeminiDriver`) over a shared `LlmTransport` (Laravel `Http`), picked by `CLASSIFIER_PROVIDER` in `AppServiceProvider` ([06](06-configuration.md#switching-the-ai-provider)). After changing `.env`: `php artisan queue:restart`. SQLite holds classification runs, the classification cache and accountant imports ([13](13-database-schema.md)).
- **Classification cache** (`ClassificationCache`, [11](11-classification-cache.md)): known rows skip the AI; imported entries are also found by code + name when the client file has no budget item; side-pair sub-classes are stored as their base class and re-decided by each row's balance side (`config side_pairs`). **Accountant imports** (`CacheImport`, [12](12-accountant-imports.md)): `/classification-cache` page, queued, reviewed, applied in stages.
- **Sign-in on everything:** every page and endpoint needs an active user and its permission (`config/permissions.php`, roles made by the admin) — [15](15-access.md).
- **App root:** `Accounting-System/accounting-import/`. All paths below are relative to it.
- **API is versioned:** every API route is under `/api/v1` (`apiPrefix` in `bootstrap/app.php`); unversioned `/api/...` paths return 404. Only the Swagger UI stays at `/api/docs` (`routes/web.php`).
- **Single feature:** `POST /api/v1/classify/jobs` (queued, polled) or `POST /api/v1/classify/trial-balance` (sync) (multipart `file`, any layout) → read raw cells → the AI detects structure (validated, 1 retry) → classification cache lookup → the AI labels + classifies the remaining rows in batches of 100 (`CLASSIFIER_BATCH_SIZE`) (validated, 1 retry) → Laravel reads exact amounts from cells → rules + integrity checks → JSON.
- **Core invariant:** the AI never returns amounts; all values come from the file's cells.
- **Entry chain:** `routes/api.php` → `routes/apis/classify.php` → `ClassificationController::{submit → ClassifyTrialBalance job, classify}` → `ClassificationService::{detect, classifyDetected}` (`classifyFile` = both) → `SpreadsheetReader` + `StructureChecks` + `ClassificationCache::{lookup, store}` + `AccountClassifierRepository::{detectStructure, warmCache, classifyRowsRolling}` (→ `ClassifierDriver` → `LlmTransport`) + `AccountExtractor` + `TaxonomyRepository`.
- **Taxonomy (the allowed categories) lives in the `taxonomy_*` tables**, edited at `/taxonomy` ([16](16-taxonomy.md)); seeded from `config/classification.php` (2 statements, 71 main classes, 154 sub classes, all Arabic strings), which is also the fallback without a database. The AI can only return a sub class from it (JSON-schema `enum`). Config rules name categories by their config names; `TaxonomyRules` rewrites them after a rename.
- **UI:** `resources/views/classifier.blade.php` at `/classifier`, submits a job to a same-origin relative URL (`route('classify.jobs.submit')` = `/api/v1/classify/jobs`) and polls its `status_url`.
- **Port:** single setting `SERVER_PORT` in `.env`; `php artisan serve` and `APP_URL` read it.
- **Tests:** `php artisan test` (and `CLASSIFIER_PROVIDER=gemini php artisan test`) — `tests/Unit`, `tests/Feature`, helpers in `tests/Support/TrialBalanceFiles.php` and `FakeWire.php`. The AI provider is faked with `Http::fake`, in the active provider's wire format.
- **Language:** category values and system notes are fixed Arabic strings; only the AI `reason` is generated, in Egyptian Arabic. Account names are returned as uploaded.
- **All domain strings are Arabic;** exact string equality is used for matching taxonomy entries — don't reformat them.
- Before changing behavior, read [09-known-issues.md](09-known-issues.md).
