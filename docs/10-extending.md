# 10. Extending

## Add or rename a category

On the taxonomy page (`/taxonomy`, permission `taxonomy.manage`; [16](16-taxonomy.md)): a main class with its family, a sub class with its normal side and statement line; a rename reaches the memory, the import mappings and every rule. The AI picks it up automatically: the tool schema `enum` is built from the taxonomy on every call.

In code:
- A new rule that names a category: write it in config with the category's config name and add its place to `TaxonomyRules::PATHS` (main or sub), so renames reach it and deletes see it. Never a PHP literal.
- A contra account (normal balance opposite to its family) gets the opposite `normal_side`. If it belongs to a side pair, add it to `side_pairs`.

## Tune scoring

- `review_threshold`, `batch_size` in `config/classification.php`.
- Confidence caps (60 for an abnormal balance, 50 for `prefix_mismatch`): config `confidence_caps`, applied by `ClassificationService::capConfidence`.
- New rule: add a `flagXxx(array $rows): array` method that appends `{type, difference, message, severity?}` to each row's `data_flags`, and call it in `classifyDetected` before `capConfidence`. To cap the confidence, add the type to `capConfidence` and a value to `confidence_caps`; if the flag means the answer is probably wrong, add the type to `ClassificationCache::NOT_STORED_FLAGS` (and a line to `NOT_CACHED`). `needs_review` follows from any flag without `severity: "info"`.

## Change the AI

- Model: `CLASSIFIER_ANTHROPIC_MODEL` or `CLASSIFIER_GEMINI_MODEL` in `.env`, then `php artisan queue:restart`. Add its price to `classification.prices`, or its runs report `cost_usd: null`.
- Prompts / tool schemas / limits: `AccountClassifierRepository::detectStructure` and `classifyPrompt` (used by `warmCache` and `classifyRowsRolling`).
- Switch provider: `CLASSIFIER_PROVIDER` (`anthropic` | `gemini`; see [06 § Switching the AI provider](06-configuration.md#switching-the-ai-provider)).
- Add a provider:
  1. A class implementing `App\Support\Llm\ClassifierDriver`: `classify(ToolCall): PromiseInterface<ToolAnswer>`. It owns everything provider-specific: wire format, schema conversion, retry delays and fatal errors (the `retryDelay` closure passed to `LlmTransport::post`; `null` = fatal), the messages (`ProviderException::from`), answers without a tool call (`ToolAnswer` with `noToolCall`), warm-up (send it, or `ToolAnswer::notSent()`), and a missing key (`ProviderException::missingKey`). Send through `LlmTransport`.
  2. One line in the `ClassifierDriver` binding (`match`) in `AppServiceProvider`, and its name in the unknown-provider message.
  3. Its block in `classification.providers` (`api_key`, `model`, `base_url`, …) and its env vars in `.env.example`.
  4. Its prices in `classification.prices`.
  5. Its wire format in `tests/Support/FakeWire.php` and an entry in `ClassifierDriverContractTest::providers()` (provider-specific answers there too).

  Prompts, schemas, the missing-only retry and the rolling pool stay in the repositories, unchanged.
- Faster large files: raise `CLASSIFIER_CONCURRENCY` (watch the provider's rate limits), or run more queue workers for several files at once.

## Support another file layout

Layouts are detected by the AI; no header dictionary exists. If a layout needs a new kind of column (e.g. a separate currency column):

1. Add the field to `AccountExtractor::FIELDS`, a description to `AccountClassifierRepository::FIELD_LABELS`, and its Arabic label to `StructureChecks::FIELD_LABELS` (the confirmation panel).
2. Handle it in `AccountExtractor::extract` (and `validate` if it has constraints).
3. Add a `FileStructuresTest` case with a real workbook and the expected structure.

Number formats go in `AmountParser::parse` + `AmountParserTest`.

## Writing tests

Existing tests live in `tests/Unit` and `tests/Feature`; reuse `Tests\Support\TrialBalanceFiles`. Fakes answer in the active provider's wire format, so a test runs under both `php artisan test` and `CLASSIFIER_PROVIDER=gemini php artisan test`; a test of one provider's own behaviour calls `$this->useProvider('gemini')` first. Pattern:

```php
use Tests\Support\TrialBalanceFiles;

$this->fakeLlm($this->standardStructure(), [          // structure answer, row labels
    $this->account(2, 'نقدية بالصندوق'),                 // row 2 = first data row
    $this->rowOfType(3, 'total'),
]);

$file = $this->trialBalance([
    ['110101', 'الصندوق', 0, 0, 100, 0, 100, 0],
    ['', 'الإجمالي', 0, 0, 100, 0, 100, 0],
]);

$this->postJson('/api/v1/classify/trial-balance', ['file' => $file])
     ->assertOk()
     ->assertJsonPath('data.0.main_class', 'النقد ومافي حكمه')
     ->assertJsonPath('meta.skipped_rows', 1);
```

Pass closures to `fakeLlm` to vary answers per attempt (retry tests).

Any test that hits the endpoint must fake the AI provider; the suites call `Http::preventStrayRequests()` so a missing fake fails loudly instead of spending credits.

## Conventions

- Arabic strings in config and notes are matched exactly — copy/paste, don't retype.
- Keep controller thin; business rules go in `ClassificationService`; external I/O behind a repository interface.
