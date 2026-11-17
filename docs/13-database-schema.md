# 13. Database schema

SQLite (`database/database.sqlite`). Application tables for classification, the financial statements, access, and the taxonomy (`taxonomy_main_classes`, `taxonomy_sub_classes`, `taxonomy_aliases`, [16](16-taxonomy.md)). Other tables store main and sub-classes by name (no foreign key): a rename on the taxonomy page updates the memory and import tables and records the old name as an alias; classification runs keep their names.

```mermaid
erDiagram
    classification_imports ||--o{ classification_import_mappings : "import_id (FK, cascade)"
    classification_imports ||--o{ classification_import_rows : "import_id (FK, cascade)"
    classification_import_mappings ||--o{ classification_import_rows : "budget_mapping_id / note_mapping_id"
    classification_imports |o--o{ classification_cache : "import_id (imported entries)"
    classification_import_rows }o--o{ classification_name_layer : "rebuilt from applied rows"
    classification_runs }o--o{ classification_cache : "lookup and store by key"
    companies ||--o{ financial_reports : "company_id (FK, cascade, nullable)"
    classification_runs ||--o{ financial_reports : "classification_run_id (FK)"
    financial_reports |o--o{ financial_reports : "prior_report_id (FK, null on delete)"
    taxonomy_main_classes ||--o{ taxonomy_sub_classes : "main_class_id (FK, restrict)"
    taxonomy_main_classes ||--o{ taxonomy_aliases : "main_class_id (FK, cascade)"
    taxonomy_sub_classes ||--o{ taxonomy_aliases : "sub_class_id (FK, cascade)"

    taxonomy_main_classes {
        int id PK
        string statement "قائمة المركز المالي | قائمة الدخل"
        string name UK "100"
        string family "assets|liabilities|equity|revenue|cost|expenses"
        int sort
        string origin_name UK "config name; null when added in the app"
    }
    taxonomy_sub_classes {
        int id PK
        int main_class_id FK
        string name UK "100"
        string normal_side "debit|credit"
        string line_key "nullable: the statement line of one added in the app"
        int sort
        string origin_name UK "nullable"
    }
    taxonomy_aliases {
        int id PK
        string kind "main|sub (UK with name)"
        string name "a former name"
        int main_class_id FK "nullable"
        int sub_class_id FK "nullable"
    }

    classification_imports {
        int id PK
        string file_name
        string kind "file | export"
        string status "queued|running|pending_review|applied|failed"
        string stage "reading|budget|notes"
        int progress_done
        int progress_total
        text error
        json error_phrase "nullable: error in any language"
        string file_path
        string file_hash "sha256"
        json report
        string provider "nullable: AI provider the mapping asked"
        string model "nullable"
        datetime applied_at
    }
    classification_import_mappings {
        int id PK
        int import_id FK
        string kind "budget | note (20)"
        string value
        string normalized
        string parent "note: main class or route (100)"
        string decision "map|route|skip"
        string main_class
        string sub_class "by nature"
        string function_sub_class "policy: by function"
        string policy
        string method "override|taxonomy|reused|side_rule|llm|manual"
        int confidence
        text reason
        json reason_phrase "nullable"
        text flag
        json flag_phrase "nullable"
        bool reviewed
        int rows
    }
    classification_import_rows {
        int id PK
        int import_id FK
        int row
        string company
        string account_code
        string account_name
        string budget_value
        string note_value
        string side "closing"
        string opening_side
        string movement_side
        int budget_mapping_id
        int note_mapping_id
        string sub_class
        int confidence
        string skip_reason
        bool approved
        bool applied
    }
    classification_cache {
        int id PK
        string key_hash UK
        string base_hash "sha1 code+name, indexed, nullable"
        string name_hash
        text normalized_key
        string original_name
        string account_code
        string budget_item
        string row_type
        string main_class
        string sub_class
        string source "ai|manual_correction|imported"
        int confidence
        text reason
        string taxonomy_version
        int hit_count
        int import_id
        string side
        bool side_locked
    }
    classification_name_layer {
        int id PK
        string name_hash "UK with side"
        string side "debit|credit"
        string normalized_name
        string original_name
        string sub_class
        int companies
        int share
        int hit_count
        string origin "rows|export"
    }
    classification_runs {
        string id PK "uuid"
        string status
        string stage
        string original_name
        string file_path
        json result
        text error
        json error_phrase "nullable: error in any language"
        json structure
        json confirmation
        string lease
        datetime heartbeat_at
        int rows_from_cache
        int rows_sent_to_llm
        decimal cost_usd
        decimal estimated_savings_usd
        string provider "nullable: provider the run (last) started with"
        string model "nullable"
        datetime started_at "nullable: first start, the price date"
    }
    companies {
        string id PK "uuid"
        string name_ar
        string name_en "nullable"
        string legal_form "llc"
        string country "SA"
        string framework "ifrs_for_smes"
        string cr_number
        string cr_date_hijri "as entered"
        date cr_date
        string unified_number
        string city
        string head_office
        text activity
        json branches
        int fiscal_year_end_month
        int fiscal_year_end_day
        string currency "SAR"
        json profile
    }
    financial_reports {
        string id PK "uuid"
        string company_id FK "nullable"
        string classification_run_id FK
        string prior_report_id FK "nullable"
        string prior_run_id "nullable"
        string status "draft|final"
        date period_start
        date period_end
        date comparative_start "nullable"
        date comparative_end "nullable"
        string template_version
        json inputs
        json account_overrides
        json adjustments
        json movement_decisions
        json prior_figures
        string run_result_hash "sha1"
        datetime last_computed_at
        datetime finalized_at
    }
```

## Tables

| Table | Role | Why it matters |
|---|---|---|
| `classification_runs` | One trial balance being classified: job state, detected structure and the user's confirmation, the full result, tokens and cost | The page polls it. `lease` / `heartbeat_at` stop two workers processing the same run. Each result row carries its `cache_key`. `provider` / `model` (set on every start, so a resumed run shows those it resumed with) and `started_at` (first start only: the day `cost_usd` is priced at) come from migration `2026_10_08_000001`; all nullable, `null` for older runs |
| `classification_cache` | The classifier's memory: one entry per account key ([11](11-classification-cache.md)) | Every run reads it first, so a known account costs no AI call. Priority manual > ai > imported is enforced on write. Side-pair sub-classes are stored as their base class. `base_hash` (sha1 of `code:…\|name:…`, string 40, nullable, indexed; migration `2026_10_06_000001`, filled by every writer) finds imported entries by code + name when a client row has no budget item |
| `classification_name_layer` | Answers by account name and balance side when the key misses | Codes differ per company, so a new company rarely matches by key. Only names most companies agree on (≥ 2 companies, ≥ 80%) |
| `classification_imports` | One uploaded accountant file, or one imported JSON export (`kind`) | Job status and progress for the page, the report, and the hash behind the duplicate warning. `provider` / `model` (nullable, migration `2026_10_08_000001`): set at the import's first AI call; `null` when no value needed the AI |
| *(phrases)* | `classification_runs.error_phrase`, `classification_imports.error_phrase`, `classification_import_mappings.reason_phrase` / `flag_phrase` (migration `2026_10_18_000003`) | The stored Arabic message as a phrase (lang key + params, `App\Support\Phrase`), so the API gives it in the reader's language (`*_text`). `null`: an older record, or text that is data (an AI's reason, a config text) |
| `classification_import_mappings` | One decision per distinct value: الميزانية → main class, (main class, الإيضاحات) → sub-class | Where the accountant reviews: about 5,000 rows become about 1,000 decisions. Approved mappings are reused by later uploads. Unique on (`import_id`, `kind`, `parent`, `normalized`), index `cim_import_kind_parent_normalized_unique` (explicit short name and `kind` 20 / `parent` 100 characters, for MySQL's identifier and key-length limits) |
| `classification_import_rows` | Every line of the file, with its mappings and resolved sub-class | Traceability and skip reasons. Only rows whose mappings are all approved (`approved`) reach the cache |
| `companies` | A company profile for the financial statements ([14](14-financial-statements.md)), reused across years | `cr_date_hijri` is text, stored as entered. `branches` and `profile` are JSON in `text` columns. No owner column yet (local-only). Migration `2026_10_13_000001` |
| `taxonomy_main_classes`, `taxonomy_sub_classes`, `taxonomy_aliases` | The taxonomy, edited at `/taxonomy`, seeded from config by migration `2026_10_18_000004` ([16](16-taxonomy.md)) | The AI's allowed list, the families and normal sides the checks use, the line of a sub class added in the app. Aliases (every former name) let old runs build statements; a name is unique per kind among current and former names |
| `financial_reports` | One set of statements built from a finished run | `run_result_hash` (sha1 of the run's `result` at the last compute) gives `run_changed` when a row is corrected later. `inputs`, `account_overrides`, `adjustments`, `prior_figures` are JSON, filled by later steps. Same migration; `movement_decisions` (JSON, the decisions on the movement suggestions) from `2026_10_14_000001` |

## Relations

| From → to | Type | Why it matters |
|---|---|---|
| imports → import_mappings | Foreign key, cascade delete | Each decision belongs to one import; deleting an import removes them. Later imports search all mappings to reuse decisions |
| imports → import_rows | Foreign key, cascade delete | Keeps every source line with its import for the report and the review page |
| import_rows → import_mappings | `budget_mapping_id`, `note_mapping_id` (no constraint) | Changing one mapping re-resolves every row that uses it: one review click fixes hundreds of rows |
| imports → classification_cache | `import_id`, set on `imported` entries only (no constraint) | A repeated «تطبيق» removes only the entries that import wrote and no longer produces. AI and manual entries are never touched |
| import_rows → classification_name_layer | Derived, no column | Rebuilt from `applied` rows of every import; it can be recomputed whenever the thresholds change |
| classification_runs ↔ classification_cache | By key hash, no column | A run looks entries up and stores good AI answers. A row correction uses the row's `cache_key` to save a manual entry |
| companies → financial_reports | Foreign key, nullable, cascade delete | A report may have no company; deleting a company removes its reports |
| classification_runs → financial_reports | Foreign key (no cascade) | A report reads its run every time; runs are never deleted (only their files) |
| financial_reports → financial_reports | `prior_report_id`, foreign key, null on delete | The prior year's report, for the comparatives (a later step) |

Laravel's own tables (`users`, `sessions`, `password_reset_tokens`, `personal_access_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`) hold no classification data. `jobs` carries the queued classification runs and imports.
