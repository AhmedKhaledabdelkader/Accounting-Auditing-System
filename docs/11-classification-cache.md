# 11. Classification cache

Rows seen before are labelled from the database instead of the AI. A known account costs no AI call and gets the same class every time. Code: `app/Services/ClassificationCache.php`, key rules in `app/Support/ClassificationKey.php`, table `classification_cache` ([13 § schema](13-database-schema.md)).

Turn it on with `CLASSIFIER_CACHE=true` (off by default: with the migration missing, every run would fail).

## The key

`code:<code>|name:<name>|budget:<budget item>` after normalization. A row with neither a code nor a budget item (a generic name like «أخرى») uses `name:<name>|parent:<nearest parent or section above it>` instead, so «أخرى» under expenses and under revenue never share one answer.

Normalization, in order (`ClassificationKey::RULES`): diacritics removed, tatweel removed, أ إ آ → ا, ة → ه, ى → ي, spaces trimmed and collapsed. Codes: Arabic digits → ASCII, spaces removed. The rules apply to every key, the base key included (`tests/Unit/ClassificationKeyTest.php`). `key_hash` = sha1 of the readable key (unique).

**Base key** (`ClassificationKey::base`): the key without its budget item, `code:<code>|name:<name>`; `base_hash` = its sha1. Only rows with a code have one (not generic keys). Accountants' files always have الميزانية, clients' trial balances usually don't, so an imported entry's full key rarely matches a client row: the base key bridges that.

## Lookup, per row (`ClassificationCache::lookup`)

1. **The entry for the key.** There is one entry per key, and its source decides whether it is used:
   - `manual_correction`: always, while its sub-class exists in the taxonomy (survives a taxonomy version bump).
   - `imported`: like manual, survives a taxonomy version bump while its sub-class exists, but needs confidence ≥ the minimum (an accountant's label, see [12](12-accountant-imports.md); «تطبيق» writes 100).
   - `ai`: only from the current `taxonomy_version` and with confidence ≥ `CLASSIFIER_CACHE_MIN_CONFIDENCE` (90).
2. **Code + name fallback** (`importedByCode`), when the full key gave nothing usable and the row has a code: `imported` entries (never `ai` or manual) with the same `base_hash`, used **only when all usable ones agree on one sub-class**. The row's source is `imported_code`, its reason starts with `ClassificationCache::IMPORTED_CODE_PREFIX` (`من بيانات مصنفة يدويًا اتستوردت (نفس الكود والاسم، من غير بند الميزانية): `), its confidence is the entry's, and the entry's `hit_count` goes up.
3. **The name-only layer** (`classification_name_layer`), when the key missed: the account name plus the closing-balance side, built from accountants' imported files. Used only for names at least 2 companies used with at least 80% agreement (config `import.name_layer`). Generic names (`import.name_layer.generic_names`) are never stored.
4. **Otherwise the AI**, with the cached neighbouring rows as context.

The explain API's `steps` list the same order (`ClassificationCache::STEPS`, with an `imported_code` step). `capConfidence` caps `imported_code` and name-layer rows like AI rows; `manual_correction` and exact-key `imported` rows keep their confidence ([03 § Step 6](03-classification-pipeline.md#step-6-data-checks-classificationservice-php-only)).

All numeric checks run on every row, cached or not.

## Side pairs: classes that depend on the balance side

Some sub-classes are the same account on two sides of the balance sheet. Whatever the AI or the cache answers (any source, the name layer too), the row's own side re-decides within the pair (config `side_pairs`, `ClassificationCache::applyFlips`):

| Pair | Debit | Credit | Review flag | Base (stored) side |
|---|---|---|---|---|
| `employees` | عُهد العاملين, سلف العاملين | أمانات العاملين | no | debit |
| `vat` | حسابات مدينة لدى الجهات الحكومية | ضريبة القيمة المضافة, ضريبة القيمة المضافة المستحقة | no | credit |
| `customers` | عملاء | دفعات مقدمة من العملاء | yes | debit |
| `suppliers` | دفعات مقدمة للموردين | موردون | yes | credit |
| `expenses` | مصروفات مدفوعة مقدماً | مصروفات مستحقة | no | debit |

- **Side** (`ClassificationCache::rowSide`): the closing balance; when zero, the opening balance; then the period's net movement. All zero: the cached sub-class stays.
- **Target member:** the first member of the other side, unless `by_name` says otherwise (employees: a name with «سلف» → سلف العاملين).
- **`only_names`** (VAT): only accounts whose name has one of the words flip, matched as whole words after normalization («ق.م» = «ق م», never inside «قسم مبيعات»). Other government receivables with a credit balance keep their class and get a `side_contradiction` review flag.
- **Flags on the row:** `side_flip` (severity `info`, unless the pair has `flag: true` and the flip goes away from its base side — a supplier in debit, a customer in credit —, then it needs review; an AI answer put back on the base side is not reviewed) or `side_contradiction` (always needs review). A rule that decides an account replaces the pair's flip with its own review on the same terms (`ClassificationCache::reversed`), whatever the AI had answered; such rows count in the rule's summary, not in `side_flips`. Labels follow taxonomy renames (`pairLabel`).
- **The cache stores the base class** (`ClassificationCache::baseClass`, config `side_pairs.*.base`): the member the account resolves to on the pair's base side, by the same rules as a flip (`by_name`, `only_names`: a non-VAT government receivable stays as is). So an entry holds the account's kind, not the side its balance once had; the lookup still re-decides by each row's side. AI answers (`store`), manual corrections that are not `side_locked` (`correct`), imported entries («تطبيق») and JSON-transfer imports all store it.
- **Consequence:** a supplier stored as موردون with a credit balance is not a flip (no `side_flip`, no review); only a supplier with a debit balance flips (to دفعات مقدمة للموردين) and is flagged.
- The name layer is unchanged: it is keyed by side and stores that side's class.
- **Run summary:** `meta.side_flips` = `[{pair, label, rows, flagged, contradictions}]`, plus an info line on the page.
- Each pair can be switched off (`enabled`) or made to flag (`flag`) in config.

## Storing AI answers (`ClassificationCache::store`)

After a run, AI answers are stored, as the base class of a side pair, with their `base_hash`, unless (`ClassificationCache::NOT_CACHED`): confidence below the minimum; the row carries a `ClassificationCache::NOT_STORED_FLAGS` flag (`statement_mismatch`, `classification_mismatch`, `inconsistent_classification`, `abnormal_opening`, `abnormal_closing`, `possible_parent`, `prefix_mismatch`: a review comes first, or the answer is probably wrong); the same key got two answers in the file; the AI did not classify it; it has no name, or is generic with an unknown parent; it is a SUBTOTAL row. An AI answer never replaces a manual correction.

## Manual corrections

`PATCH /api/v1/classify/jobs/{id}/rows/{row}` with `{sub_class}` corrects a `done` run's result (confidence 100) and saves a `manual_correction` entry for the row's `cache_key`, which wins over every other source from then on. `POST /api/v1/classify/jobs/{id}/corrections` with `{corrections: [{row, sub_class}]}` does the same for several rows in one transaction (an unknown row saves nothing). The classifier page uses it: rows are edited on the page and saved together with «حفظ التعديلات»; unsaved edits never reach the cache (they only change the page and its CSV).

The row's balance side is stored with it (`side`). For a side-pair class:
- **Picked the member that matches the side:** a choice of account kind, stored as the base class. Later rows flip by their own side.
- **Picked the member against the side:** a deliberate override. `side_locked = true`, never flipped.

A row answered by the cache gets its reason worded by the cache (`classification_messages.cache_reasons`: the `من ذاكرة التصنيف…` / `من بيانات مصنفة يدويًا اتستوردت…` prefixes, the flip suffix, the name layer): Arabic in `classification_reason` as always, the request's language in `classification_reason_text`; the entry's own stored reason inside it is data. `GET …/explain` stays Arabic.

## The memory page (`/classification-memory`)

Lists the entries (search, source, sub-class; a side-pair member says it flips by the balance), adds one by hand (`POST entries`: code, name, budget item, the parent for a generic name; a manual correction, replacing the account if stored), and changes or removes them one by one or several at once (`POST entries/reclassify`, `cache.review`; `POST entries/delete`, `cache.delete`). A changed entry becomes a manual correction (`ClassificationCache::reclassify`: no row, so no side; a side-pair member is stored as its base class).

On the classifier page, rows can be ticked and given one class, or their current class confirmed («✓ صح، احفظه» on a row the memory won't keep by itself: an AI answer under the floor, a rule's, one needing review). Both are saved as corrections with «حفظ التعديلات». Classes are chosen with a search box (`public/js/class-picker.js`: Arabic letter forms count as one).

## APIs

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/classification-cache/explain` | How the cache works, in Arabic, built from the code and config |
| GET | `/api/v1/classification-cache/entries` | Paginated entries; `?source=ai\|manual_correction\|imported`, `?q=` |
| DELETE | `/api/v1/classification-cache/entries/{id}` | Remove a wrong entry: the row goes back to the AI |

Run stats on each job: `rows_from_cache`, `rows_sent_to_llm`, `estimated_savings_usd`. The run's `classification_summary` log line has the `cache` counts by source: `manual`, `ai`, `imported`, `imported_code`, `name_layer`, plus `side_flips`.

## One-time cleanup (`CacheCleanup`)

Entries stored before these rules are brought in line once by migration `2026_10_06_000002` (after `2026_10_06_000001` added `base_hash`); `php artisan classification:cache-cleanup [--dry-run]` repeats it. It:
- deletes `ai` entries whose `cache_key` carried a `NOT_STORED_FLAGS` flag in any finished (`done`) run's result, so those rows go back to the AI;
- converts side-pair entries to their base class (not `side_locked` ones), with the main class from the taxonomy;
- fills a missing `base_hash`.

`updated_at` stays (it is the last edit by a person or the AI). Idempotent.
