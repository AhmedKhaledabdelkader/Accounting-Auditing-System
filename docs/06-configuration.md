# 6. Configuration

## Environment variables (`.env`)

| Variable | Default | Used in |
|---|---|---|
| `CLASSIFIER_PROVIDER` | `anthropic` | `anthropic` or `gemini`. See [Switching the AI provider](#switching-the-ai-provider) |
| `ANTHROPIC_API_KEY` | — (required for `anthropic`) | Anthropic key. Empty or `<need-to-change>` = missing |
| `CLASSIFIER_ANTHROPIC_MODEL` | `claude-sonnet-5` | Anthropic model. See [valid Anthropic models](#valid-anthropic-models) |
| `CLASSIFIER_ANTHROPIC_BASE_URL` | empty (official API) | Another endpoint (proxy, fake server); `/messages` is appended |
| `GEMINI_API_KEY` | — (required for `gemini`) | Gemini key. Empty or `<need-to-change>` = missing |
| `CLASSIFIER_GEMINI_MODEL` | `gemini-3.8-flash` | Gemini model |
| `CLASSIFIER_GEMINI_THINKING_LEVEL` | `low` | `low`, `medium` or `high`; empty = the model's default. Thinking tokens count against the output limit and are billed as output |
| `CLASSIFIER_GEMINI_BASE_URL` | empty (official API) | Another endpoint (proxy, fake server); `/models/<model>:generateContent` is appended |
| `CLASSIFIER_BATCH_SIZE` | `100` | Data rows per classification call |
| `CLASSIFIER_LOG_DAYS` | `30` | Days the daily `classification` log (structure check issues) is kept |
| `CLASSIFIER_CONCURRENCY` | `4` | Classification requests in flight at once, per worker. Not an integer ≥ 1 → 4. Each queue worker has its own limit, so N workers send up to N × this against the same provider rate limit |
| `SERVER_PORT` | `8000` | Default port of `php artisan serve` |
| `APP_URL` | `http://127.0.0.1:${SERVER_PORT}` | URLs generated outside a request |
| `QUEUE_CONNECTION` | `database` | Queue for classification jobs (`jobs` table) |
| `CLASSIFIER_CACHE` | `false` | Turns the classification cache on ([11](11-classification-cache.md)). Needs the migrations |
| `CLASSIFIER_CACHE_MIN_CONFIDENCE` | `90` | AI answers below this are never stored or reused |
| `CLASSIFIER_TAXONOMY_VERSION` | `3` | Bump when the taxonomy or prompt changes: older AI answers are ignored (manual and imported entries stay) |
| `CLASSIFIER_IMPORT_COST_OF_REVENUE` | `function` | Accountant imports: cost-of-revenue rows by function (`function`) or by nature (`nature`) ([12](12-accountant-imports.md)) |
| `CLASSIFIER_NAME_LAYER_MIN_SHARE` / `_MIN_COMPANIES` | `80` / `2` | Name-only layer thresholds; run `classification:import --rebuild-name-layer` after changing them |
| `FS_TEMPLATE_VERSION` | `sa-ifrs-smes-3` | Financial statements generator: the template and line catalogue version (`config/financial_statements.php`, [14](14-financial-statements.md)) |
| `QPDF_BINARY` | `qpdf` | The qpdf command that cuts a PDF's pages (last year's statements as a PDF, [14](14-financial-statements.md)); a full path when it isn't on the `PATH` |
| `PRIOR_PDF_MAX_KB` / `PRIOR_PDF_MAX_PAGES` | `20480` / `150` | The largest PDF of last year's statements accepted; a larger one is refused before anything is sent to the AI |
| `FS_ROUNDING_LIMIT` | `5` | The most a statement total's whole-riyal rounding difference may be to go on a line under it; above it, check `rounding_over_limit` (error) ([14](14-financial-statements.md)) |
| `FS_NEGATIVES` | `parentheses` | How a negative amount is written on every screen and in the Excel, Word and PDF files: `parentheses` (1,234) or `minus` -1,234. Zero is always «-» (`App\Support\Amounts`) |
| `CLASSIFIER_REJECT_UNBALANCED_FROM` | `1.00` | A trial balance (full or partial) off by this or more in opening, movement or closing is refused |
| `DB_QUEUE_RETRY_AFTER` | `1900` | Must stay above the job timeout (1800 s): otherwise a second worker picks up a run still in progress and fails it. The default is now 1900 even when the variable is missing, and a worker **refuses to start** if it is not above 1800 |

Other Laravel defaults: `APP_KEY`, `DB_CONNECTION=sqlite`, `SESSION_DRIVER=database`, `CACHE_STORE=database`. `CLASSIFIER_PROVIDER`, `ANTHROPIC_API_KEY`, `CLASSIFIER_ANTHROPIC_MODEL`, `GEMINI_API_KEY`, `CLASSIFIER_GEMINI_MODEL`, `CLASSIFIER_GEMINI_THINKING_LEVEL`, `CLASSIFIER_BATCH_SIZE`, `CLASSIFIER_CONCURRENCY`, `CLASSIFIER_LOG_DAYS`, `CLASSIFIER_CACHE`, `CLASSIFIER_CACHE_MIN_CONFIDENCE`, `CLASSIFIER_TAXONOMY_VERSION`, `CLASSIFIER_IMPORT_COST_OF_REVENUE`, `CLASSIFIER_REJECT_UNBALANCED_FROM`, `FS_TEMPLATE_VERSION`, `FS_ROUNDING_LIMIT`, `FS_NEGATIVES`, `QPDF_BINARY`, `PRIOR_PDF_MAX_KB` and `PRIOR_PDF_MAX_PAGES` are in `.env.example`; fill in the key of the provider you use.

**After changing `.env`, run `php artisan queue:restart`** (and `php artisan config:clear` if config is cached): a running worker keeps its old settings.

## Switching the AI provider

Change `CLASSIFIER_PROVIDER` only, then `php artisan queue:restart`. Each provider keeps its own key and model; a key is never sent to the other provider.

| Provider | `CLASSIFIER_PROVIDER` | Key | Model (default) |
|---|---|---|---|
| Anthropic (Claude) | `anthropic` | `ANTHROPIC_API_KEY` | `CLASSIFIER_ANTHROPIC_MODEL` (`claude-sonnet-5`) |
| Google Gemini | `gemini` | `GEMINI_API_KEY` | `CLASSIFIER_GEMINI_MODEL` (`gemini-3.8-flash`) |

- A key that is empty or `<need-to-change>` is missing: the run fails before any AI call with `<Provider> API key is missing. Set <VAR> in .env, then run php artisan queue:restart.` (sync endpoint 502, queued run `failed`).
- An unknown `CLASSIFIER_PROVIDER` fails with 500 `Unknown CLASSIFIER_PROVIDER [x]. Use one of: anthropic, gemini.`
- The model must support **forced tool / function calling** with JSON Schema (both calls rely on it), a context large enough for the full chart of accounts, and good Arabic.
- Prompts, checks and amounts do not change: every amount is still read from the file, never from the AI.
- Prompt caching: Anthropic writes the cache explicitly (1-token warm-up call); Gemini caches repeated prefixes implicitly, so no warm-up is sent.
- Gemini thinking level (`CLASSIFIER_GEMINI_THINKING_LEVEL`, default `low`): thinking shares `maxOutputTokens` (16,000) with the answer; `low` keeps a batch's answer from being cut off.
- Check a provider before relying on it: `php artisan classifier:probe [--provider=gemini]` (one real call of each kind) and `php artisan classifier:compare <file>` (both providers on one file). See [08 § Live provider checks](08-setup-and-operations.md#live-provider-checks).

### Valid Anthropic models

Both AI steps force the model to call one tool (`tool_choice: {type: "tool"}`). Prices per million tokens: input / cached read / output (cache writes 1.25× input).

| Model | `CLASSIFIER_ANTHROPIC_MODEL` | Price | Status |
|---|---|---|---|
| Claude Haiku 4.5 | `claude-haiku-4-5` | $1 / $0.10 / $5 | Run on real files. 200K context |
| Claude Sonnet 5 | `claude-sonnet-5` | $2 / $0.20 / $10 | Default. Run on real files |
| Claude Opus 5 | `claude-opus-5` | $5 / $0.50 / $25 | Valid, not run yet. No entry in config `prices`, so its runs report `cost_usd: null` |
| Claude Sonnet 5.5, Opus 5.5, Fable 5.1 | `claude-sonnet-5-5`, `claude-opus-5-5`, `claude-fable-5-1` | — | **Not usable as is:** they reject a forced tool call (HTTP 400). Needs `tool_choice: auto` with a strict schema |

Each result's `meta.provider`, `meta.model` and `meta.usage` (and the `classification_summary` log line) say which model ran and the tokens it used.

- Only Anthropic has been run on real files (`LiveBalanceXlsxTest`). **Gemini has not been run against the real API yet** (no key yet): it is covered by faked tests (`GeminiDriverTest`, `ClassifierDriverContractTest`, the whole suite under `CLASSIFIER_PROVIDER=gemini`). Run `classifier:probe --provider=gemini` and one real file before relying on it.

## `config/classification.php`

| Key | Value | Effect |
|---|---|---|
| `provider` | `env('CLASSIFIER_PROVIDER', 'anthropic')` | Picks the driver in `AppServiceProvider` (the `ClassifierDriver` binding); an unknown one fails with a clear 500 |
| `providers.anthropic` | `api_key`, `model`, `base_url` | `ANTHROPIC_API_KEY`, `CLASSIFIER_ANTHROPIC_MODEL`, `CLASSIFIER_ANTHROPIC_BASE_URL`. Passed to `AnthropicDriver` only |
| `providers.gemini` | `api_key`, `model`, `base_url`, `thinking_level` | `GEMINI_API_KEY`, `CLASSIFIER_GEMINI_MODEL`, `CLASSIFIER_GEMINI_BASE_URL`, `CLASSIFIER_GEMINI_THINKING_LEVEL`. Passed to `GeminiDriver` only |
| `batch_size` | `env('CLASSIFIER_BATCH_SIZE', 100)` | Data rows per classification call. Bigger means fewer calls (the full chart is re-sent with each one), but larger answers per call |
| `concurrency` | `env('CLASSIFIER_CONCURRENCY', 4)`, validated | Requests in flight at once (batches + the matching call), in a rolling pool: a finished request frees its slot for the next. Run `php artisan config:clear` / `config:cache` after changing it |
| `review_threshold` | `70` | `classification_confidence` below this → `needs_review` (and the reason is shown) |
| `prices` | model → list of `{from, input, cache_write, cache_read, output}` (USD per million tokens; `from` = first UTC day `YYYY-MM-DD`, `null` = from the start) | Run cost (`LlmCost`). A run is priced at the entry in effect on the UTC day it **first** started (`classification_runs.started_at`). Has `claude-sonnet-5`, `claude-sonnet-5-5`, `claude-haiku-4-5`, `claude-opus-5-5` (Anthropic pricing, checked 2026-10-02) and `gemini-3.8-flash` (below). A model missing here gets `cost_usd: null`, never a guess |
| `savings_recent_runs` | `20` | Runs of the same model averaged for the cost per row when a run sent no rows to the AI (estimated cache savings) |
| `confidence_caps` | `abnormal_balance` 60, `prefix_mismatch` 50 | Highest confidence of a row with `abnormal_opening` / `abnormal_closing`, or `prefix_mismatch` (`ClassificationService::capConfidence`). AI and cache answers; rows from a manual correction or an exact-key imported entry keep theirs |
| `prefix_check` | `min_accounts` 5, `min_share` 0.8, `min_length` 2, `statement_min_share` 0.95 | Account-number check (`flagNumberPrefixes`), always without the tested account. Family: a first digit sets the file's scheme when at least `min_accounts` *other* classified accounts with numeric numbers of at least `min_length` digits start with it and `min_share` of them share one family. Statement: the first digits split into a balance-sheet and an income-statement range when `statement_min_share` of the accounts agree and each range has `min_accounts` |
| `materiality_threshold` | `1.00` | Abnormal balances, and debit ≠ credit total differences, at or below this are rounding: not flagged, or reported as `فرق تقريب` info |
| `reconciliation_tolerance` | `0.01` | Allowed `\|closing − (opening + movement)\|`; also the tolerance for matching adjustments |
| `max_sheets` | `10` | Sheets in the structure preview |
| `structure_preview_rows` | `30` | Non-empty rows per sheet sent for structure detection |
| `context_rows` | `3` | Neighbouring rows sent before/after each batch |
| `structure_checks` | see the file | Thresholds of each structure check (`error` / `doubt` shares, minimum sample sizes). See [03 § structure checks](03-classification-pipeline.md#step-3-structure-detection-call-1-once-per-file) |
| `structure_checks.pause_on` | 8 doubt checks | Doubts that make a queued run wait for the user's confirmation (errors always do). Others stay warnings |
| `stale_minutes` | `10` | A `running` run whose heartbeat is older than this has no live worker: `classification:prune` fails it |
| `confirmation_hours` | `24` | How long a run waits for confirmation before `classification:prune` deletes its file and preview |
| `log_channel` | `env('CLASSIFIER_LOG_CHANNEL', 'classification')` | Channel for structure check issues; tests use `null` |
| `balance_tolerance` | `0.01` | Debit/credit totals, total-row and parent checks |
| `reject_unbalanced_from` | `env('CLASSIFIER_REJECT_UNBALANCED_FROM', 1.00)` | A trial balance (full or partial) whose opening, period or closing debit − credit is this or more is refused ([03 § step 7](03-classification-pipeline.md#step-7-file-level-checks-filechecks)). Pending the accountant's confirmation |
| `cache` | `enabled`, `min_confidence`, `taxonomy_version` | The classification cache (env above) |
| `side_pairs` | `employees`, `vat`, `customers`, `suppliers`, `employee_accounts`, `expenses`, and the accountant's of 2026-10-20: `sundry`, `other_balances`, `revenue`, `supplier_advances`, `taxes`, `deposits`, `salaries` ([17](17-classification-rules.md)). A class in two pairs flips by the first | Sub-classes re-decided by the row's balance side on every run (AI and cache answers); each has `enabled`, `flag`, `base`, `debit`, `credit`, optional `by_name` and `only_names`. `base` is the side whose member the cache stores (employees `debit`, vat `credit`, customers `debit`, suppliers `credit`, expenses `debit`; where the sides differ in size, the one with more members, so nothing is lost) ([11 § side pairs](11-classification-cache.md#side-pairs-classes-that-depend-on-the-balance-side)) |
| `import.budget_overrides` | value → `main` / `sub` / `skip` / `policy` / `flag` | Fixed decisions on الميزانية values, applied before the taxonomy and the LLM |
| `import.side_rules` | notes → `debit` / `credit` sub-class | Notes decided per row by balance side (employee advances) |
| `import.policies` | `cost_of_revenue` | Open accounting decisions answered both ways; `mode` picks one |
| `import.name_layer` | `min_share`, `min_companies`, `generic_names` | Name-only layer rules |
| `import.llm_batch_size` | `150` | (main class, الإيضاحات) pairs per mapping call |
| `taxonomy` | nested array | The sub_class mapping table (below) |

### `prices`: Gemini 3.8 Flash

Source: https://ai.google.dev/gemini-api/docs/pricing, paid tier (page updated 2026-10-07, checked 2026-10-08). `cache_write` 0: Gemini caches implicitly.

| `from` | Input | Cache read | Output |
|---|---|---|---|
| `null` (until 2026-12-31) | $0.75 | $0.075 | $3.75 |
| `2027-01-01` | $1.50 | $0.15 | $7.50 |

### `taxonomy`: the sub_class mapping table (seed)

**Since the taxonomy page the taxonomy lives in the `taxonomy_*` tables and is edited at `/taxonomy` ([16](16-taxonomy.md)).** This list (with `taxonomy_families`: main class → family; an income-statement main class not listed is an expense) only seeds the tables (migration `2026_10_18_000004`) and is read when the tables don't exist (tests without a database). Editing it changes nothing on a database that is already seeded. The rules below (`side_pairs`, `import.*`) and those of `config/financial_statements.php` keep the config names; `TaxonomyRules` rewrites them to the current names.

`financial_statement => main_class => [sub_class => normal_side]`: 2 statements, 71 main classes, **154 sub_classes**. Taxonomy version 3 added five classes the financial statements present on their own lines, under existing main classes: `استثمارات عقارية` and `استثمارات في شركات زميلة` (investments, debit), `توزيعات أرباح مستحقة` and `إيرادات مؤجلة` (accruals and other payables, credit), `رد خسائر الانخفاض` (other income, credit). A bank overdraft has no class: the statements move a bank in credit to the overdraft line per year ([docs/14](14-financial-statements.md)). Version 2 added `مخزون أول المدة` (debit) and `مخزون آخر المدة` (credit, contra) under cost of sales for periodic inventory, and the liability main class `مخصصات أخرى` (`مخصص ضمان`, `مخصصات أخرى`). Also added earlier: `مصروف مكافأة نهاية الخدمة` (under salaries), `مصروفات تعبئة وتغليف`, `إهلاك الأصول الثابتة` (general), `أدوات سلامة ودفاع مدني`, `غرامات ومخالفات`, `سكن العاملين`, all expense/debit. `TaxonomyRepository::entries()` returns it as flat rows `{sub_class, main_class, statement, normal_side}`.

- `normal_side` (`debit` | `credit`) drives the abnormal-balance check. Contra accounts carry the opposite side of their group: `مجمع إهلاك الأصول الثابتة`, `مجمع إهلاك الأصول غير الملموسة`, `مخصص ديون مشكوك في تحصيلها`, `مخصص هبوط أسعار المخزون` (credit, under their asset); `مردودات ومسموحات المبيعات`, `خصم مسموح به للعملاء` (debit, under revenue); `خصم مكتسب`, `مردودات المشتريات` (credit, under cost).
- Audit changes:
  - `أوراق قبض` moved to trade receivables
  - `أوراق دفع` moved to trade payables
  - `استثمارات قصيرة الأجل` moved to investments
  - `خصم مسموح به للعملاء` and `خصم مكتسب` now have the correct side
  - added `عُهد العاملين` (separate from `سلف العاملين`), `مخصص هبوط أسعار المخزون`, and `بطاقات ائتمان دائنة`
- Sub_class names must be unique. `familyOf()` reads the stored family (seeded from `taxonomy_families`). `tests/Unit/TaxonomyRepositoryTest.php` audits every entry of the seed; `tests/Feature/TaxonomyPageTest.php` checks the tables hold it.
- The old `number_prefix_map`, `contra_sub_classes` and `*_nature_classes` keys are gone.
- Run `php artisan config:clear` after edits if config is cached.

## `config/financial_statements.php`

The financial statements generator's line catalogue and mapping rules, keyed by template version: statement lines with their normal side, rules per main and sub-class, side rules, expense functions and the function words read from a file's own classification column, the partners' current account choice, and the classifier flags that make a mapping pending. Described in [14](14-financial-statements.md). Every taxonomy sub-class must resolve to a rule and every line must exist in the template (`tests/Unit/Statements/LineCatalogueTest.php`); a sub class added on the taxonomy page gets the line chosen with it ([16](16-taxonomy.md)). `periodic_cost.cost_of_goods_sold` (the sub class naming the note's combined row) and `related_parties` (the classes of check 26e) were PHP literals before.
