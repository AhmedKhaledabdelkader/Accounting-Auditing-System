# 7. Frontend

Three pages with their own inline CSS and JS, in the frame every page shares (below):

| Page | View | Purpose |
|---|---|---|
| `/classifier` | `classifier.blade.php` | Upload a trial balance and see the results (below) |
| `/classification-cache` | `classification-cache.blade.php` | Upload an accountant-classified file (Arabic column errors at once, duplicate-file warning), progress (reading → mapping الميزانية → mapping الإيضاحات → ready), previous imports, export (Excel / JSON with source and date filters), import of a JSON export |
| `/classification-cache/imports/{id}/review` | `classification-import-review.blade.php` | Two tabs (الميزانية, الإيضاحات) sorted by rows: confidence, sample names, flags; approve / change / skip, bulk approve (picked, or «اعتماد الكل» by the current filters); «تطبيق» writes the approved rows ([12](12-accountant-imports.md#review-and-تطبيق)) |

The rest of this page describes `/classifier`.

- Arabic, RTL (`<html lang="ar" dir="rtl">`), IBM Plex Sans Arabic from Google Fonts.
- Inline CSS and inline vanilla JS. **Does not use Vite, Tailwind or `resources/js`.**

## Shared look

Every page (these three and the financial-report pages, [14](14-financial-statements.md)) uses the same light teal look and frame:

- `public/css/theme.css`: the colours as CSS variables in `:root` (`--brand`, `--panel`, `--ok`, `--warn`, `--bad`, their `-soft` backgrounds, …), the font, the side menu, the top bar and the page heading. Loaded by `partials/theme-head.blade.php`.
- `components/shell.blade.php` (`<x-shell title subtitle>`): the side menu (on the right; a menu button opens it below 1000px wide), the top bar and the page heading; the page's content goes in its slot. Its words are in `lang/ar/financial_reports.php` (`app`, `nav.*`).
- A page's own CSS only uses the variables, so a colour changes in one place.
- Every dropdown can be searched (`public/js/select-search.js`, loaded by `theme-head`): a click or a key on a `<select>` opens a panel with a search box (alef forms, ة/ه and ى/ي match each other) instead of the browser's list. The `<select>` stays the field: picking sets its value and fires `input` and `change`. A list box (`multiple`, `size` > 1) or a select with `data-native-select` keeps the browser's own list. Words: `auth_pages.select_search`.
- Amounts follow one setting (`FS_NEGATIVES`, `App\Support\Amounts`): `window.AmountsConfig` gives the screens the same negatives as the files; zero is «-».

## API URL

Blade writes the page's configuration into a hidden element, outside the script, so editors parse the script as plain JavaScript:

```blade
<div id="app-config" data-submit-url="{{ route('classify.jobs.submit', [], false) }}" hidden></div>
<script>
const SUBMIT_URL = document.getElementById('app-config').dataset.submitUrl;
```

`SUBMIT_URL` is `"/api/v1/classify/jobs"`: a same-origin relative URL generated from the named route, so the page works on any host or port (including tunnels) with no config. The other URLs (`status_url`, `cancel_url`, `confirm_url`) come from the API's answers, already versioned.

The import review page does the same: `#app-config` holds `data-api` (`route('classification-cache.imports.show')`), `data-review-url` (`…/imports/mappings/`), and `data-taxonomy` / `data-policies` as JSON, escaped **once** by `{{ }}` (no extra `htmlspecialchars`: the browser decodes the attribute once, then `JSON.parse` reads it). `tests/Support/PageConfig.php` reads these attributes like a browser does. The cache page (`classification-cache.blade.php`) still uses an inline `const API = '/api/v1/classification-cache'`.

## Flow

1. **Select file**: click or drag-and-drop onto `#drop`. Shows file name/size chip, enables the **ابدأ التصنيف** button.
2. **Submit**: `POST` `FormData(file)` to `SUBMIT_URL` (queued job, [05](05-api.md#background-jobs-used-by-the-page)), then poll the returned `status_url` every 2 s (`POLL_MS`). `?run=<id>` in the page URL opens an existing run.
3. **Loader**: the real `stage` (`STAGES`: queued → reading → structure → classifying → checks), a progress bar (batches done / total during `classifying`, never going backwards), elapsed time and an estimated time left from the batch rate. A **إلغاء** button while `cancel_url` is set. A hint after 15 s in the queue to check the queue worker.
4. **Confirmation panel** (`needs_confirmation`): the problems, every column with its header, samples and a field dropdown (number + name in one cell is one option), header rows and first data row, the first rows. «أكّد وكمّل التصنيف» sends the mapping to `confirm_url`; a `422` with `needs_acknowledgement` shows the doubts and a «أكّد برضه» button (`acknowledge_doubts`); other `422`s list the problems. «إلغاء» cancels the run (its file is deleted).
5. **Errors**: non-2xx, `failed` or `expired`: shows `errors[first][0]` (Laravel validation format), `message` or the run's `error`.
6. **Results**
   - Red **partial** banner (`meta.unclassified`): count, row ranges, causes, and a link that shows only the rows needing review.
   - Warnings banner: `meta.warnings` (⛔ lines in red); info banner: `meta.info`. Hidden when empty.
   - Cards: total accounts, needs review, average confidence (from `meta`), the run's real time, its cost (`cost.cost_usd`, AI requests), rows from the cache vs sent to the AI, and the estimated savings (`cache`).
   - Table: `5 + groups × 2 + 4` columns: number, name, statement pill, main, sub, a debit/credit pair per balance group (adjustments only when the file has them), confidence bar, needs-review tag, note (high-severity flags first, in red), audit requests (`طلبات المراجع`). Rows needing review have a red background.
   - Confidence bar colors: ≥ 85 green, ≥ 70 amber, else red.
   - Statement pill: purple if the statement contains `الدخل`, blue otherwise, grey `غير مصنف` if null.
7. **Search**: filters by account number, name, sub class, main class or audit request (substring, case-insensitive).
8. **المراجعة فقط** checkbox: only rows with `needs_review`.
9. **CSV export**: all rows (ignores filters), same columns plus the file's note number (`رقم الإيضاح`), UTF-8 with BOM so Excel shows Arabic correctly; file `classified-trial-balance.csv`.
10. **ملف جديد**: resets state.

**Row corrections.** Clicking a row's sub-class opens a list of every sub-class grouped by main class (`TAXONOMY`, from `data-taxonomy` in `#app-config`, escaped once). The change updates the main class and statement at once and marks the row (orange); choosing the original class again undoes it. Edits live **on the page only** until «💾 حفظ التعديلات»: the bar shows how many there are, the CSV download includes them (column «تعديل يدوي»: «لسه ما اتحفظش» / «محفوظ»), and «↩ إلغاء التعديلات» drops them. Saving asks in the page (`partials/ask-dialog`), then sends them all in one `POST /api/v1/classify/jobs/{id}/corrections`: the run keeps them and the classification cache remembers them as manual corrections, so the same accounts come back with these classes in later files. The message says how many were remembered and which rows (no account name or known parent) changed in this run only. A failed save keeps the edits on the page.

## The API's messages

The pages send `Accept-Language` (their language). Messages the classification API stores in Arabic come back beside it in that language as `*_text` (`note_text`, `data_flags[].message_text`, `meta.warnings_text` / `info_text`, `error_text`, `confirmation.issues[].user_text`, `fields_text`, `problems_text`, `doubts_text`; on the import pages `reason_text`, `flag_text`, `switch_text`, `warnings_text`). Each page shows the `*_text` field when present, else the Arabic one (a run or import stored before them). Data (classes, account names, file values, the AI's reason) is shown as it is.

## Helpers

| Function | Purpose |
|---|---|
| `esc(s)` | HTML-escape via a DOM text node (all dynamic values go through it) |
| `money(n)` | `en-US` format, 2 decimals |
| `pill(s)`, `confBar(c)` | Cell renderers |
| `render()` | Rebuilds `#tbody` from `rows` + filters |
| `renderHead()`, `columnCount()` | Header for the groups present (`meta.groups`) |
| `noteHtml(r)` | Note with high-severity flag messages first, in red |
| `showPartial(u)`, `showBanner(id, title, list)` | Partial / warnings / info banners |
