# Trial Balance Classifier (مصنّف ميزان المراجعة)

A Laravel app that takes a trial balance (ميزان المراجعة) as an Excel/CSV file, **in any layout or language**, and uses an LLM (Claude by default, or Gemini, via `CLASSIFIER_PROVIDER`) to classify every account into a fixed chart of financial-statement categories:

**financial statement → main class → sub class**, with a confidence score (0–100) and a "needs review" flag.

How it works:
- **Claude reads the structure** (which sheet, header rows, which column holds what) and **labels every row** (account, parent, total, section) with its category and a short reason in Egyptian Arabic.
- **Laravel reads every amount from the original cells**, so the AI never writes numbers.
- **PHP checks the numbers; Claude never does**:
  - each account's closing balance against opening + movement
  - balances on the wrong side for the account type (above a materiality threshold)
  - matching differences to an adjustments sheet
  - debits = credits, but only on a full trial balance
  - totals against the file's total row
  - probable parent accounts
  - account numbers whose first digit belongs to another family in the file's own numbering
  - wrong-side balances cap confidence at 60, account-number mismatches at 50
- **Every result keeps AI and PHP apart**: `classification_confidence` (Claude), `data_flags` (PHP) and `source_note` (the file's remarks). `needs_review` is derived from those three.

- **A classification cache remembers answers**: accounts seen before are labelled from the database (AI answers, the accountant's manual corrections, and imported accountant files), so they cost no AI call. Imported labels are also found by account code + name when the client's file has no الميزانية column. Answers that look wrong (wrong-side balance, possible parent, account-number mismatch) are never stored. Sub-classes that depend on the balance side (employee advances, VAT, customer and supplier advances) are re-decided by each row's side; the cache stores the account's base class. See [docs/11](docs/11-classification-cache.md).
- **Accountant-classified files can be imported** into the cache from the site: each distinct الميزانية / الإيضاحات value is mapped to the taxonomy once, the accountant reviews the mappings, and «تطبيق» writes the approved rows. The cache can be exported (Excel, JSON) and imported into another instance. See [docs/12](docs/12-accountant-imports.md).

Pages (RTL, Arabic):
- `/classifier`: upload a trial balance, see results with search, a review filter, summary cards and CSV export. Click any row's class to change it: edits stay on the page (and in the CSV) until «حفظ التعديلات» saves them, and saved ones come back in later files.
- `/classification-cache`: import accountant files, previous imports, export / import of the cache.
- `/classification-cache/imports/{id}/review`: review and apply one import.

The API is versioned: every endpoint is under `/api/v1` (Swagger UI: `/api/docs`).

Database schema with diagram: [docs/13-database-schema.md](docs/13-database-schema.md).

Full documentation: [docs/](docs/README.md). Every API endpoint for frontend developers: [docs/api-reference.md](docs/api-reference.md); Swagger UI at `/api/docs`.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2–8.4, Laravel 12 |
| Excel parsing | PhpSpreadsheet 1.30 (read-only, uses the formula values saved in the file) |
| AI | Anthropic Messages API (`claude-sonnet-5` default) or Google Gemini API (`gemini-3.8-flash` default; not yet run against the real API), picked by `CLASSIFIER_PROVIDER`, with forced tool use for structured output |
| HTTP client | Laravel `Http` facade (Guzzle) |
| Database | SQLite or MySQL: classification runs, the classification cache, accountant imports ([schema](docs/13-database-schema.md)), plus Laravel sessions/jobs |
| Frontend | Blade pages in one shared frame (`<x-shell>`, `public/css/theme.css`), vanilla JS, IBM Plex Sans Arabic |
| Build tooling | Vite 7, Tailwind CSS 4 (used only by the default welcome page) |
| Tests | PHPUnit 11 |

## Requirements

- PHP **8.2–8.4** (not 8.5 — the locked PhpSpreadsheet version requires `<8.5`), with extensions: `zip`, `gd`, `xml`, `mbstring`, `pdo_sqlite`, `fileinfo`, `iconv`
- Composer 2
- Node.js 20+ and npm
- An API key for the configured provider (Anthropic by default)

macOS (Homebrew):

```bash
brew install php@8.4 composer node
brew link --force --overwrite php@8.4
```

## Setup

```bash
cd Accounting-System/accounting-import
composer install
npm install
cp .env.example .env          # skip if .env already exists
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
```

Add to `.env`:

```dotenv
CLASSIFIER_PROVIDER=anthropic                 # or gemini
ANTHROPIC_API_KEY=sk-ant-...
CLASSIFIER_ANTHROPIC_MODEL=claude-sonnet-5     # optional; valid models: docs/06-configuration.md
GEMINI_API_KEY=...                            # only for CLASSIFIER_PROVIDER=gemini
```

Switching provider = changing `CLASSIFIER_PROVIDER` only; each provider keeps its own key and model. A key left empty or `<need-to-change>` fails every run with a clear message before any AI call. **After changing `.env`, run `php artisan queue:restart`**: a running worker keeps its old settings. See [docs/06](docs/06-configuration.md#switching-the-ai-provider).

## Run

The port is set in one place — `SERVER_PORT` in `.env` (default `8000`). `APP_URL` is derived from it, and the page calls the API with a relative URL, so changing `SERVER_PORT` is all that's needed.

Classification and accountant-file imports run as **background jobs**, so the app needs a worker beside the server. One command starts both (plus the log tail) in one terminal:

```bash
composer run dev      # server + queue worker + logs; Ctrl+C stops all
```

It also raises PHP's upload limits to 25 MB (`config/php/uploads.ini`; PHP's own default is 2 MB per file, 8 MB per request), so a PDF of last year's statements fits. The worker there (`queue:listen`) loads the code afresh for every job, so a code update needs no restart; after changing `.env`, Ctrl+C and run it again.

Without the worker, uploads stay "في الطابور" (the page shows a hint after 15 s). Running them by hand instead: `php artisan serve` and `php artisan queue:work --timeout=1800` in two terminals; that worker keeps the old code, so restart it after every update.

Turn the cache on in `.env` with `CLASSIFIER_CACHE=true`, and run `php artisan migrate` after pulling (a one-time migration also cleans entries stored before the current rules; `php artisan classification:cache-cleanup --dry-run` shows what it would change).

Open http://127.0.0.1:8000/classifier (use your `SERVER_PORT`), upload a trial balance, click **ابدأ التصنيف**. The «🧠 ذاكرة التصنيف» button opens the cache page.

API directly:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/classify/trial-balance \
  -H "Accept: application/json" \
  -F "file=@trial-balance.xlsx"
```

## Tests

```bash
php artisan test                              # Anthropic wire format
CLASSIFIER_PROVIDER=gemini php artisan test   # the same suite, Gemini wire format
```

CI (GitHub Actions) runs the suite on SQLite, MySQL 8 and the Gemini wire format for every pull request ([docs/08](docs/08-setup-and-operations.md#test)).

914 tests (plus an opt-in live test): number parsing, sheet reading, structure validation, the sub_class table audit, numeric checks (reconciliation, abnormal balances, partial trial balance), adjustments matching, remarks, retries, API errors, the classification cache (code + name fallback, base classes, confidence caps, cleanup) and side pairs, API versioning, accountant imports (queue, review, apply, reuse), export / import, `balance.xlsx` end to end, and an anonymised real client trial balance with recorded AI answers (note-number column, periodic stock caught by the statement numbering scheme), the financial statements templates (clean packages, formulas that tie out, well-formed placeholders and blocks), and the statements generator's line mapping (layouts, rules, side rules, functions, periodic-inventory pairs, aggregation), companies and financial reports (create from a finished run, state, `run_changed`), the current-year balance sheet and income statement with their checks and preview pages, the report's inputs (zakat, OCI, partners), account overrides and adjusting entries, the prior-year comparatives (previous report, prior trial balance, manual, first year; opening vs prior closing; period length, gap and approval date), the notes' movement schedules (suggestions to confirm, asset notes by category, provision roll-forwards, a reversal is not a payment, disposal proceeds, the loans note and its current portion), the changes in equity and the cash flow (reaching the balance sheet's cash, partners' current accounts, overdraft), and Arabic-only pages (no code shown). The AI is faked, so no API calls are made. `RUN_LIVE_TESTS=1 [CLASSIFIER_PROVIDER=gemini] php artisan test --filter=LiveBalanceXlsxTest` runs `balance.xlsx` against the real API of the provider in use. `php artisan classifier:probe [--provider=gemini]` and `php artisan classifier:compare <file>` check a provider live (real calls, real money; [docs/08](docs/08-setup-and-operations.md#live-provider-checks)).

## Security

**No authentication yet.** Every API endpoint, including the cache import, export and correction endpoints, which read and write the whole cache, and the company and financial report endpoints, is open. Fine on a local machine; protect them before any deployment.

## Input file format

No fixed layout: any sheet, any header wording or language, multi-row headers, columns in any order, debit/credit pairs or signed/Dr-Cr balances, number and name in one cell, parent and total rows. See [docs/05-api.md](docs/05-api.md#input-file-format).

## How background work runs today, and what's next

Today:
- **Short polling.** The page asks for the job's state every 2 s (`POLL_MS` in `classifier.blade.php`); the prior-year PDF step every 2.5 s.
- **One queue, one worker, one file at a time.** Every job (classification, accountant import, prior-year PDF) goes to the `default` queue on the `database` driver, and `composer run dev` starts a single worker, so files wait their turn.
- **Inside one file**, up to `CLASSIFIER_CONCURRENCY` (4) AI requests run at once. A 429 is retried after its `Retry-After`, up to 3 attempts per request.

Not tried yet:
- **Several users and several queues/workers at once**, each sending chunks: does the provider start returning 429? Total requests in flight = workers × `CLASSIFIER_CONCURRENCY`, and nothing caps that across workers today.
- **Short vs long polling vs sockets.** Short polling was kept because it is simple. If it proves too heavy under load, try long polling; if that's not good enough, try WebSockets (e.g. Laravel Reverb). This needs measuring, so no choice was made without trying.

When usage grows:
- Run files in parallel (several workers / queues), with several files in progress at once, capped so the AI never returns 429: a shared limit on AI requests across all workers (e.g. a Redis rate limiter or semaphore), with `CLASSIFIER_CONCURRENCY` per file under it.
