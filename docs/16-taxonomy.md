# 16. The taxonomy and its page

The taxonomy (statement → main class → sub class → normal side) is what the AI chooses from, the classification memory stores and the statements are built on. It is edited at `/taxonomy`.

## Where it lives

| Piece | Where |
|---|---|
| Main classes | `taxonomy_main_classes`: `statement` (one of the two, Arabic), `name` (unique), `family` (`assets`, `liabilities`, `equity` for «قائمة المركز المالي»; `revenue`, `cost`, `expenses` for «قائمة الدخل»), `sort`, `origin_name` |
| Sub classes | `taxonomy_sub_classes`: `main_class_id`, `name` (unique), `normal_side` (`debit` / `credit`), `line_key` (the statement line; only for sub classes added in the app), `sort`, `origin_name` |
| Former names | `taxonomy_aliases`: `kind` (`main` / `sub`), `name` (unique per kind), the category it now is (deleted with it) |
| Reading | `TaxonomyRepository` (scoped: read once per request or queued job; `flush()` after a change). `familyOf()` gives the stored family's word (`أصول`…). Adds `resolveSub()` / `resolveMain()` (current or former name → current name), `lineKeyOf()`, `renamedOrigins()`, `addedSubLines()` |
| Seed and fallback | Migration `2026_10_18_000004` seeds the tables from `config('classification.taxonomy')` and `taxonomy_families` (`origin_name` = the config name). Without the tables (tests without a database), the repository reads that config list |
| Changes | `App\Services\TaxonomyManager` (one transaction each), `TaxonomyController`, view `taxonomy.blade.php`, words in `lang/{ar,en}/taxonomy_page.php` |
| Permissions | `taxonomy.view` (the page), `taxonomy.manage` (every change) |

The statements are fixed (`TaxonomyRepository::STATEMENTS`). Category names are Arabic data: never translated. Names are unique per kind, current and former alike (the AI and the memory know a sub class by its name alone, and an old name must point to one category); a main class and a sub class may share a name. `route` is reserved (import mappings).

## Rename

A new name is used everywhere at once:
- **Stored for reuse:** `classification_cache` (`main_class` / `sub_class`), `classification_name_layer`, `classification_import_mappings` (`main_class`, `parent`, `sub_class`, `function_sub_class`) and `classification_import_rows` and the accountant's rules (`classification_rules`: `debit_class`, `credit_class`, `ai_classes`) are updated in the same transaction.
- **Rules:** the config rules are written with the config names. `App\Support\Taxonomy\TaxonomyRules::PATHS` lists every place that names a category (statement line rules, side rules, movement schedules, disposal results, periodic cost, foreign currency, related parties, side pairs, import overrides and side rules, import policies), each as main or sub. `apply()` rewrites each place from the config name to the current one. It runs when the app boots for a page or API call, when a command starts (not the framework's own: migrations, caches…), before every queued job (`Queue::before`), and after every change. A no-op while nothing is renamed or added.
- **The AI's hints:** `AccountClassifierRepository::classifyRules()` gives `CLASSIFY_RULES` with the quoted sub-class names renamed; `TaxonomyMapperRepository::EXAMPLE_SUBS` likewise. The `enum`s come from the repository.
- **Old runs:** a stored run keeps the names it was classified with (a record). `ReportSource::result()` resolves each row's sub class through the former names (its main class follows the sub class), so building statements from an old run works. `CacheTransfer` resolves former names in imported exports too.

Not followed: the `label` texts of `side_pairs`, the prose of `confirm` / `reason` texts and of `lang/*/report_messages.php`, and the sample data of `classifier:probe` (dev only). They are wording, not rules.

## Delete

Refused, with where it is used (`TaxonomyManager::uses`), while:
- a main class has sub classes;
- the classification memory, the name layer or an accountant import (mapping or row) holds it;
- a rule names it (`TaxonomyRules::usesOf`: the config places above, and the AI's hints);
- a classification run's result has a row with it, under its current or a former name.

A deleted category's former names go with it.

## Adding

- **Main class:** its statement and family (from the statement's families). It has no line rule: its sub classes carry theirs.
- **Sub class:** its name, normal side and statement line, chosen from the current template's lines of its statement (`sofp.*` for «قائمة المركز المالي», `is.*` for «قائمة الدخل»), shown by their Arabic labels. `TaxonomyRules::apply()` puts it in `sub_classes` of every template that has the line, so `LineMapper`, the notes' breakdowns (by sub class) and the checks see it like a built-in one. Side rules of its main class still apply (a new sub class under cash with a credit balance is an overdraft).
- Built-in sub classes keep their rules (the page says «حسب القواعد المضمّنة»); their line can't be changed here. A built-in main class keeps its family.

## Caches

- The classification memory's key has no taxonomy in it; renamed entries keep working. `CLASSIFIER_TAXONOMY_VERSION` needs no bump: a rename moves the AI entries along, and a new category invalidates nothing.
- Prompt caching (Anthropic, Gemini implicit) is keyed on the prompt text: the first file after a change writes a new prefix; nothing to do.
- The repository is not cached across requests (three small queries per request or job), so every worker sees a change at its next job.

## The income statement's main classes are the functions (2026-11-19)

The accountant's tabs: **تكلفة الإيرادات**, **المصروفات الإدارية والعمومية**, **المصروفات البيعية والتوزيعية**, **استهلاك ممتلكات وآلات ومعدات**, **تكاليف التمويل**, **مصروفات أخرى** (and, with their own statement line: مصروف الزكاة الشرعية, مصروف ضريبة الدخل, ضريبة الدخل المؤجلة, إطفاء الأصول غير الملموسة, خسائر وانخفاض قيمة). Before, every nature (rent, electricity, advertising…) was a main class of its own with one sub class of the same name, so the classifier's two columns repeated each other. Now the natures are the **sub classes**, under the function their old main class already had in `financial_statements.templates.*.main_classes`, so no statement figure moved.
- **Config:** `classification.taxonomy` (seed and fallback), `taxonomy_families` (`cost` → تكلفة الإيرادات), the statement rules `main_classes` (each new main class carries its function: `general_admin`, `selling`, `other`, `finance`, `depreciation`), the PPE schedule's `charge.main_classes`, the import policy's `function_main`, the budget overrides.
- **Notes:** the expense notes (cost of revenue, selling, general and administrative, finance cost) list natures, so they are grouped by **sub class** (`'group' => 'sub_class'`), not by main class as before. A nature with several sub classes (salaries: أجور ورواتب / رواتب الإدارة / مصروف مكافأة نهاية الخدمة; rent: إيجارات / إيجار المقر الإداري) now shows one line per sub class.
- **Existing database:** migration `2026_11_19_000001_group_income_statement_expenses` moves each sub class to its function's main class, renames the cost main class (it keeps its subs), keeps every old main name as a former name (alias) of the main class it went to, and moves what the classification memory and the accountant imports hold under the old names. A database seeded from the current config already has the structure (the migration does nothing). Not reversible; a backup of the three tables was written before it ran on the development database.
- **Still main = sub:** zakat, income tax, deferred tax, amortisation (each its own statement line), «إيرادات أخرى». On the classifier page and in its CSV they show a group instead (`classification.display_groups`, display only).
- Old runs and files: a stored run keeps the main class it was classified with; the statements resolve the main class from the sub class, so they build as before. An uploaded file's own «التصنيف الرئيسي» (e.g. «إيجارات») is mapped by the AI to the nearest current main class.
