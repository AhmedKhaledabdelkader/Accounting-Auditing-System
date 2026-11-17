# 9. Known issues

Verified against the current code. Not fixed unless marked resolved.

## Deferred on purpose

1. ~~Accounts starting with 8 or 9 flagged~~: resolved. The first-digit rule was removed from both the prompt and PHP.
2. **No authentication or rate limiting** on any endpoint: anyone reaching the server can spend AI credits, and read, overwrite or export the whole classification cache (`/api/v1/classification-cache/*`: import, apply, export.json, import-export; and the row corrections `PATCH /api/v1/classify/jobs/{id}/rows/{row}` / `POST /api/v1/classify/jobs/{id}/corrections`). **Must be protected before any deployment.**

## Limitations

3. **Row labels can vary between runs.** Whether a row is an account, parent or total is Claude's decision. Parent detection, the debit/credit check and the total-row check limit the impact but don't make it deterministic.
4. **Warnings don't pinpoint rows.** A debit/credit or total-row mismatch is reported file-wide in `meta.warnings`; the affected rows are unknown.
4a. **Partial detection depends on classification.** Whether a trial balance counts as "full" is decided from the families of the classified accounts, so a misclassified account can turn the imbalance info into a warning, or the reverse.
4b. **Adjustments matching by name depends on Claude.** Numbered adjustment lines are matched in PHP; name-only lines (and numbers that repeat) rely on Claude's `adjustment_matches`, which return the trial-balance row (`account_row`), validated against the chart's rows (`validMatches`), with one retry.
5. **Parent detection needs hierarchical numbers.** A parent the AI labels as an account is caught only when its number is a prefix of its children's numbers and the balances sum exactly.
6. **Structure preview is limited.** The header must be within the first 30 non-empty rows of one of the first 10 sheets. A trial balance split across several sheets isn't combined.
7. **Group fallback copies values.** When the file has no opening (or closing/period) columns, those fields are copied from another group rather than left empty, to preserve the earlier behaviour.
8. ~~Timeouts on large files~~: resolved. The page uses queued jobs (1800 s job timeout); batches run in a rolling pool (`CLASSIFIER_CONCURRENCY`, default 4), with `timeout(120)` per call and up to 2 HTTP retries. `set_time_limit(300)` applies only to the sync endpoint.
9. **Egyptian dialect isn't verified.** The code checks the reason contains Arabic letters; whether it's Egyptian rather than Modern Standard Arabic depends on the model following the prompt.
10. **Ambiguous number formats.** `1.234` is read as 1.234 (decimal), `1,234` as 1234 (thousands).

11. **Side pairs pick the first member.** Flipping back from أمانات العاملين goes to عُهد العاملين unless the name has «سلف»; the VAT pair flips only VAT-named accounts. The same rule converts what the cache stores to the base class (`baseClass`): the first member of the base side unless `by_name` says otherwise. On import apply, a row of the same key already on the base side decides instead. A manual correction fixes a wrong pick and locks it when made against the side.
12. **Accountant imports: zero-balance side-rule rows are not imported** (no side to decide by), and LLM mappings need the accountant's approval, so a fresh import writes only its auto-approved rows on the first «تطبيق».
13. **The code + name fallback needs a code.** A client row without an account code can only reach an imported entry through its full key (with the budget item) or the name layer. The fallback uses imported entries only, and only when all of them agree on one sub class.
14. **The account-number check needs enough accounts.** The statement split needs at least 5 accounts on each side and 95% agreement; a first digit sets a family only with at least 5 *other* classified accounts under it (`prefix_check.min_accounts`) and 80% in one family. Small files or mixed numbering get no `prefix_mismatch`.
15. **Gemini has not been run against the real API yet** (no key yet). The driver, its schema conversion (`null` inside an `enum` → `anyOf`; type arrays with `null`, integer enums, `minimum` / `maximum` and the 145-item enum sent as is, documented as supported by Google) and its error handling are covered only by faked tests. Run `php artisan classifier:probe --provider=gemini`, then `classifier:compare` on a real file, before relying on it.

## Dead / misleading code

- `Repositories/Eloquents/` contains no Eloquent code.
- `AccountClassifierRepository` depends on the concrete `TaxonomyRepository`, bypassing the interface binding.
- Sanctum, the `User` model, the welcome page and the Vite/Tailwind assets are scaffolding.

## Security / ops

- Account data, plus every other non-empty row of the chosen sheet and the preview rows of the other sheets, is sent to the configured AI provider (`CLASSIFIER_PROVIDER`, Anthropic by default).
- `.env` holds the real API key; keep it out of version control (`.gitignore` excludes it).
- **Access is by run id only.** There is no authentication: anyone who has a run's UUID can read its result, its column preview, confirm its mapping or cancel it. Fine for a single user or team; with several clients, runs need an owner and every jobs endpoint an authorization check.
- A run waiting for confirmation keeps the uploaded file and a column preview for up to 24 h. They are deleted only if the scheduler runs `classification:prune` (see [08 § scheduler](08-setup-and-operations.md#scheduler-required-for-deleting-abandoned-uploads)).

## Test coverage

See [08-setup-and-operations.md § Test](08-setup-and-operations.md#test). Real calls are covered only by the opt-in `LiveBalanceXlsxTest` (provider in use, `RUN_LIVE_TESTS=1`; so far run with Anthropic only) and the manual `classifier:probe` / `classifier:compare`; Gemini only by faked tests (the whole suite also runs with `CLASSIFIER_PROVIDER=gemini`). Not covered: `.xls` files, time-limit behaviour on large files.
