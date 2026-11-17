<?php

namespace App\Repositories\Contracts;

use Closure;
use Throwable;

interface AccountClassifierRepositoryInterface
{
    /**
     * Identify the trial balance layout from a preview of every sheet.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $preview  sheet => row => letter => value
     * @param  list<string>  $previousErrors  validation errors from a previous attempt, if retrying
     * @param  array<int, string>  $sheetNames  sheet number => title
     * @param  list<string>  $letters  every column letter used anywhere in the file (allowed values)
     * @return array raw tool input: {sheet, header_rows, first_data_row, columns: field => letter, other_sheets}
     */
    public function detectStructure(array $preview, array $previousErrors = [], array $sheetNames = [], array $letters = []): array;

    /**
     * Writes the prompt cache (rules + file block, identical for every request of a file) with a minimal call,
     * so requests sent together all read it instead of each writing it.
     *
     * @param  array{chart?: list<array>, adjustments?: list<array>, existing_values?: list<array>}  $fileContext  see classifyRowsRolling
     */
    public function warmCache(array $structure, array $headerRows, array $fileContext): void;

    /**
     * Labels batches of rows (account / parent / total / section / other) and classifies account rows, and/or
     * matches adjustment lines and existing classification values, keeping at most $concurrency requests in flight:
     * as soon as one finishes, the next one starts, in key order.
     * Must not return amounts — Laravel reads those from the file.
     *
     * Each batch argument list is: structure (AccountExtractor::normalize), header rows (row => cells),
     * rows to label (row => cells), context rows (row => cells, shown only), fileContext (identical for every
     * request, sent as a cached prefix: chart, adjustments, existing_values), and the matches to ask for:
     * null for a batch, or {adjustments: ids, existing: ids} for the matching call (no rows to label).
     *
     * Transient failures (timeouts, connection errors, 429, 5xx) are retried by the driver, up to 2 times.
     * $onAnswer(key, answer, isRetry) receives the parsed answer
     * {rows, adjustment_matches, existing_class_matches} or the final error (a ProviderException). On a first answer it may return
     * the arguments of one follow-up request (the missing-only retry), which is sent in the same slot and whose
     * answer comes back with isRetry = true. An exception thrown by $onAnswer stops the run and is rethrown.
     *
     * @param  array<int|string, array>  $batches  key => arguments, in order
     * @param  Closure(int|string, array|Throwable, bool): ?array  $onAnswer
     */
    public function classifyRowsRolling(array $batches, int $concurrency, Closure $onAnswer): void;
}
