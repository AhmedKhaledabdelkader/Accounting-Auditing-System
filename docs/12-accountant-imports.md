# 12. Accountant imports and cache transfer

Trial balances already classified by accountants are imported into the [classification cache](11-classification-cache.md), so future uploads of those accounts skip the AI: by the full key, or, when the client file has no budget item, by code + name ([11 § lookup](11-classification-cache.md#lookup-per-row-classificationcachelookup)). The accountant reviews every mapping first; nothing reaches the cache before «تطبيق». The page is `/classification-cache` ([07](07-frontend.md)).

Code: `app/Services/CacheImport.php` (read, map, review, apply, name layer), `app/Jobs/ProcessClassificationImport.php` (queue), `app/Repositories/Eloquents/TaxonomyMapperRepository.php` (LLM mapping), `app/Services/CacheTransfer.php` (export / import), controllers `ClassificationImportController` and `ClassificationTransferController`. Tables: [13 § schema](13-database-schema.md).

## Input file

First sheet, a header row with at least «اسم الحساب» and «الميزانية» («بنود الميزانية» / «مستوى القوائم» also count). Recommended: «رقم الحساب», «الإيضاحات», «أول/أخر مدين», «أول/أخر دائن», «حركة مدينة/دائنة», «اسم الشركة». Headers are matched after normalization, in any column order.

- **Checked at upload, before anything is queued:** a missing required column or no header row gives a 422 with an Arabic message. A missing recommended column gives a warning.
- **A header repeated inside the data** sets the layout from that row on. One company's block can have shifted columns; the company column carries over.
- **Skipped while reading:** repeated header rows, empty name, empty الميزانية, and a الميزانية with no Arabic letter (an account name in a shifted column).
- **The same file again** (sha256 `file_hash`) gives a 409 with the earlier import, unless `force=1`. Failed imports don't count.

## Lifecycle

`queued` → `running` (stages `reading` → `budget` → `notes`, progress by LLM batch) → `pending_review` → `applied` (after the first «تطبيق»), or `failed` with an Arabic `error`. A running import whose `updated_at` is older than 15 minutes shows as failed (dead worker). The upload file is deleted when the job ends.

## Mapping: each distinct value once

**Step A: الميزانية → main class** (kind `budget`), by normalized value (`ImportNormalizer`: key normalization plus punctuation, «و», «ال» and trailing «بالصافي» removed, so «الذمم الدائنة التجارية» = «ذمم دائنة تجارية»):

1. `override`: config `import.budget_overrides` (the accountant's fixed decisions: map, skip, policy, flag).
2. `reused`: the same value in an earlier import or an imported export, approved ones first, then the newest. An approved copy is marked `reused` and stays approved. An unapproved one is copied with its own method and stays unreviewed. Either way, no LLM call.
3. `taxonomy`: equals a main class.
4. `llm`: one call for everything left. The answer is `map` (a main class), `route` (a group by function such as G&A: the sub-class comes from الإيضاحات) or `skip`.

A main class with a single sub-class needs no step B.

**Step B: (main class or route, الإيضاحات) → sub-class** (kind `note`). The note is the account name when الإيضاحات is empty:

1. `side_rule`: config `import.side_rules` (employee advances): the sub-class is decided per row by side. The side is closing, then opening, then net movement; all zero → `no_side`, not imported.
2. `reused`: from an earlier import.
3. `taxonomy`: the note is a sub-class name.
4. `llm`: batches of `import.llm_batch_size` (150). The whole taxonomy goes in the prompt with الميزانية's main class as a hint, and `sub_class` is an enum.

A note mapped outside الميزانية's main class gets a review `flag`.

**Policies** (config `import.policies`): open accounting decisions answered both ways once. `cost_of_revenue` (تكاليف المبيعات / تكلفة الإيرادات) stores `sub_class` by nature and `function_sub_class` by function (goods / services). `mode` picks which one is used. To switch: set `CLASSIFIER_IMPORT_COST_OF_REVENUE=nature`, then «تطبيق» again. No LLM call.

**Messages in two languages.** Upload errors and refusals come in the request's language. What is stored stays Arabic (a mapping's `reason` / `flag`, the import's `error`, the report's labels), with the phrase beside it (`reason_phrase`, `flag_phrase`, `error_phrase`); the API adds `reason_text`, `flag_text`, `error_text`, the upload's `warnings_text` and, in the report, `skipped.*.label_text`, `flag_text`, `open_decisions[].switch_text`. The AI's reasons and the config's override reasons and flags are data (as written). The Excel export keeps its Arabic columns.

## Review and «تطبيق»

- **Approved without review:** `override`, `taxonomy` and `side_rule` mappings, unless flagged, plus reused approved ones. Every LLM mapping needs the accountant.
- **The review page** (`/classification-cache/imports/{id}/review`) has two tabs (الميزانية, الإيضاحات), sorted by row count. It shows confidence, sample account names and flags, and offers approve / change / skip, one at a time or in bulk: the picked rows, or «اعتماد الكل» = every not-yet-reviewed mapping of the tab matching the current search and filters, on every page, after an in-page confirmation (`partials/ask-dialog`, no browser popup) with the count and how many are flagged. The button shows how many it would approve; when the tab has none left, a button points to the other tab's pending count (`POST …/mappings/approve` with `all: true` and the list's filters). Approving as is sets confidence 100.
- **«تطبيق»** (`POST …/apply`) writes only rows whose mappings are all approved. The rest stays pending, so it can run again as more get approved.
- **Counters** (`report.rows`): `ready` = approved, not written yet (the card «معتمدة ومستنية «تطبيق»»; 0 after an apply); `written` = approved and written; `cache_entries` = entries this import wrote (rows sharing a key share one); `kept_existing` = rows kept out (manual/AI entry, or a key conflict). `approved` is cumulative. A row whose class or approval changes after an apply goes back to `ready`. Reports saved before these fields are rebuilt once when read.
  - Each write is an `imported` entry with confidence 100, `import_id` and `base_hash` (code + name, for the fallback lookup). A side-pair sub-class is stored as its base class (`ClassificationCache::baseClass`).
  - It never replaces a `manual_correction` or `ai` entry (`cache_has_entry`), and skips a key that got two sub-classes in the file (`key_conflict`). Sub-classes are compared as base classes, so one account on both sides of a pair (a side rule) is one kind, not a conflict. When one of the key's rows already sits on the pair's base side (e.g. «سلف موظفين» → سلف العاملين on a debit row), its member is used for the other side's rows too, instead of the name rule.
  - A repeated run removes only this import's own entries that no approved row produces any more.
- **The name layer** is rebuilt from rows with `applied = true` of every import:
  - Grouped by name and closing side.
  - A company that used two sub-classes for a name agrees with neither.
  - Entries brought by an export are kept.

## Export and import of the cache

| Method | Path | Result |
|---|---|---|
| GET | `/api/v1/classification-cache/export.xlsx` | RTL sheet for the accountant (Arabic columns; codes kept as text), plus a name-layer sheet |
| GET | `/api/v1/classification-cache/export.json` | `{format: "classification-cache-export", format_version: 1, taxonomy_version, taxonomy_subclasses, cache_entries, budget_mappings, note_mappings, name_layer}`; each cache entry includes `side` and `side_locked` |
| POST | `/api/v1/classification-cache/import-export` | Imports that JSON. No LLM |

- **Filters:** `?source=` and `?from=` / `?to=` (Y-m-d, on the last update) apply to cache entries. Mappings are always exported, one per value (approved first, then the newest).
- **Import rules:**
  - A manual correction here is never replaced. Otherwise the higher priority wins (manual > ai > imported); on equal priority, the newer entry wins.
  - Entries and mappings whose sub-class is not in this taxonomy are skipped and listed.
  - A `side_locked` entry is kept as is; an unlocked one is converted to its base class (an older export may hold the side-resolved one). Exports without `side` / `side_locked` count as unlocked.
  - `base_hash` is computed on import; the main class comes from this taxonomy.
  - AI entries from another `taxonomy_version` are stored but unused.
  - Mappings are stored as an import of `kind = export`, so later uploads reuse them.

## Command line

```bash
php artisan classification:import file.xlsx --report=report.json   # read + map synchronously, never applies
php artisan classification:import --reapply=<id>                     # «تطبيق» again (e.g. after switching a policy)
php artisan classification:import --rebuild-name-layer               # after changing name_layer thresholds
```
