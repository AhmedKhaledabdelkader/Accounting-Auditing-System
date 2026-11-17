<?php

namespace App\Services;

use App\Exceptions\LocalizedException;
use App\Exceptions\StructureNotDetected;
use App\Services\Statements\AccountNames;
use App\Support\ClassificationKey;
use App\Support\FileRows;
use App\Support\Phrase;
use App\Repositories\Contracts\AccountClassifierRepositoryInterface;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Support\Llm\UsageMeter;
use App\Support\Llm\Provider;
use App\Support\Llm\ProviderException;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ClassificationService
{
    private const REASON_FALLBACK = 'classification_messages.reasons.fallback';

    private const INCOME_STATEMENT = 'قائمة الدخل';

    /** An expense by its name ("مصروف …", "مصاريف …")… */
    private const EXPENSE_NAME = '/^\s*(ال)?(مصروفات|مصروف|مصاريف)(?!\p{L})/u';
    /** …unless it says prepaid, accrued or deferred, which are balance-sheet accounts. */
    private const PREPAID_OR_ACCRUED = '/مقدم|مستحق|مؤجل/u';

    private const GROUP_LABELS = TrialBalanceTotals::GROUP_LABELS;

    /** Account family (TaxonomyRepository::familyOf) → major trial-balance group. */
    /** The family check's group of liabilities and equity (flagNumberPrefixes). */
    private const CREDITS = 'التزامات وحقوق الملكية';

    private const MAJOR_GROUPS = [
        'أصول'         => 'أصول',
        'التزامات'     => 'التزامات',
        'حقوق الملكية' => 'حقوق الملكية',
        'إيرادات'      => 'إيرادات',
        'تكلفة'        => 'مصروفات',
        'مصروفات'      => 'مصروفات',
    ];

    public function __construct(
        private SpreadsheetReader $reader,
        private AccountExtractor $extractor,
        private AccountClassifierRepositoryInterface $classifier,
        private TaxonomyRepositoryInterface $taxonomy,
        private StructureChecks $checks,
        private ClassificationCache $cache,
    ) {}

    /**
     * Detects the structure and classifies in one go (sync endpoint): errors left after the retry abort, doubts become warnings.
     *
     * @param  (callable(string $stage, int $done, int $total): void)|null  $progress
     * @return array{rows: list<array>, skipped_rows: int, unclassified: array, groups: list<string>, warnings: list<string>, info: list<string>, other_sheets: list<array>, adjustments: list<array>}
     *
     * @throws StructureNotDetected listing the problems (and the detected mapping) when the retry still fails
     */
    public function classifyFile(UploadedFile $file, ?callable $progress = null, ?string $companyId = null): array
    {
        $detection = $this->detect($file, $progress);
        if ($problems = self::problems($detection['issues'])) {
            throw new StructureNotDetected($problems, $detection['structure'], self::problemPhrases($detection['issues']));
        }

        return $this->classifyDetected($file, $detection, $progress, $companyId);
    }

    /**
     * Reads the file and asks the AI for its structure (one retry with the failed checks).
     * The detection must be classified in the same process: the reader keeps the file's state.
     *
     * @return array{sheets: array, names: array, file_warnings: list<string>, structure: array, issues: list<array>}
     */
    public function detect(UploadedFile $file, ?callable $progress = null): array
    {
        $progress ??= fn () => null;
        app(UsageMeter::class)->reset();   // a new file: its usage starts here (a resumed run is seeded by the job)
        $progress('reading', 0, 0);
        [$sheets, $names, $fileWarnings] = $this->read($file);

        $progress('structure', 0, 0);
        [$structure, $issues] = $this->detectStructure($sheets, $names);

        return ['sheets' => $sheets, 'names' => $names, 'file_warnings' => $fileWarnings, 'structure' => $structure, 'issues' => $issues];
    }

    /**
     * Errors the user can't be held to on a mapping they confirmed after seeing the samples: they measure the values,
     * and a real file can defeat the measure. They need an explicit "confirm anyway" instead of blocking. Every other
     * error (a missing field or column, one column for two fields, rows outside the sheet) is fixable in the panel
     * and would break the extraction, so it keeps blocking.
     */
    public const ACKNOWLEDGEABLE = ['amount_numeric', 'name_fill', 'name_text', 'side_values', 'header_contradiction',
        'groups_swapped', 'sides_swapped', 'first_row_is_header', 'other_sheets'];

    /** Overriding these makes every balance wrong: shown as ⛔ warnings, like statement_mismatch. */
    public const SEVERE_OVERRIDES = ['groups_swapped', 'sides_swapped'];

    public static function issueKey(array $issue): string
    {
        return $issue['check'].'|'.($issue['field'] ?? '').'|'.($issue['column'] ?? '');
    }

    /**
     * A mapping confirmed (or edited) by the user instead of the AI's, checked the same way.
     * Errors the user acknowledged ({check, field, column, user}) become doubts marked `acknowledged`, so they
     * no longer block; the list is kept in the detection for the result's warnings.
     */
    public function withStructure(UploadedFile $file, array $structure, bool $log = true, array $acknowledged = []): array
    {
        [$sheets, $names, $fileWarnings] = $this->read($file);
        $structure = $this->extractor->normalize($structure);
        $issues    = $this->checks->check($sheets, $structure);
        if ($log) {
            $this->logIssues('confirmed', null, $issues);
        }
        $keys = array_flip(array_map([self::class, 'issueKey'], $acknowledged));
        foreach ($issues as &$issue) {
            if ($issue['severity'] === 'error' && isset($keys[self::issueKey($issue)])) {
                $issue = ['severity' => 'doubt', 'acknowledged' => true] + $issue;
            }
        }
        unset($issue);

        return ['sheets' => $sheets, 'names' => $names, 'file_warnings' => $fileWarnings, 'structure' => $structure,
            'issues' => $issues, 'acknowledged' => $acknowledged];
    }

    /** The structure without the other sheets the checks reject (the user can't edit them in the panel). */
    public function withoutInvalidOtherSheets(array $detection): array
    {
        $structure = $detection['structure'];
        $structure['other_sheets'] = array_values(array_filter($structure['other_sheets'] ?? [], fn ($other) => ! array_filter(
            $this->checks->check($detection['sheets'], ['other_sheets' => [$other]] + $structure),
            fn ($i) => $i['check'] === 'other_sheets' && $i['severity'] === 'error'
        )));

        return $structure;
    }

    /** @return list<string> user-facing messages of the errors (empty = the mapping can be used) */
    public static function problems(array $issues): array
    {
        return array_values(array_unique(array_column(array_filter($issues, fn ($i) => $i['severity'] === 'error'), 'user')));
    }

    /** @return list<?array> the stored phrases of problems(), in the same order */
    public static function problemPhrases(array $issues): array
    {
        $byText = [];
        foreach (array_filter($issues, fn ($i) => $i['severity'] === 'error') as $i) {
            $byText[$i['user']] ??= $i['user_phrase'] ?? null;
        }

        return array_values(array_map(fn ($text) => $byText[$text] ?? null, self::problems($issues)));
    }

    /** An issue's message as a phrase (an issue stored before phrases: its Arabic as it is). */
    public static function issuePhrase(array $issue): Phrase
    {
        return is_array($issue['user_phrase'] ?? null) ? Phrase::fromArray($issue['user_phrase']) : Phrase::raw((string) ($issue['user'] ?? ''));
    }

    /** A message of classification_messages. */
    private static function m(string $key, array $params = []): Phrase
    {
        return Phrase::make('classification_messages.'.$key, $params);
    }

    /** A data flag: its Arabic message (as always) and the same message for any language. */
    private static function flag(array $base, Phrase $message): array
    {
        return $base + ['message' => $message->ar(), 'message_phrase' => $message->toArray()];
    }

    /** A list shown up to its first items, then «and N more». */
    private static function shortList(array $shown, int $more): Phrase|array
    {
        return $more > 0 ? self::m('list_more', ['list' => $shown, 'more' => $more]) : $shown;
    }

    /** A family name (MAJOR_GROUPS value) in any language. */
    private static function family(string $family): Phrase
    {
        $key = ['أصول' => 'assets', 'التزامات' => 'liabilities', 'حقوق الملكية' => 'equity', self::CREDITS => 'liabilities_equity',
            'إيرادات' => 'revenue', 'مصروفات' => 'expenses'][$family] ?? null;

        return $key ? Phrase::make('report_messages.families.'.$key) : Phrase::raw($family);
    }

    /** Items joined by a word («أو», «و»): the first as it is, each next one through the key («أو :text»). */
    private static function joined(array $items, string $nextKey): Phrase
    {
        return Phrase::join(array_map(fn ($item, $i) => $i === 0 ? $item : self::m($nextKey, ['text' => $item]), array_values($items), array_keys(array_values($items))), '');
    }

    /**
     * What the user needs to confirm a mapping without the file: every column of the sheet with its header and a few
     * values, and the first rows. Client data: cleared from the run as soon as it ends, is cancelled or expires.
     */
    public function columnPreview(array $detection): array
    {
        $structure = $detection['structure'];
        $sheet     = $detection['sheets'][$structure['sheet']] ?? [];
        $cut       = fn ($v) => mb_substr(is_float($v) || is_int($v) ? $this->extractor->cellText($v) : trim((string) $v), 0, 60);

        $letters = [];
        foreach ($sheet as $cells) {
            $letters += array_flip(array_keys($cells));
        }
        $letters = array_keys($letters);
        usort($letters, fn ($a, $b) => Coordinate::columnIndexFromString($a) <=> Coordinate::columnIndexFromString($b));
        $letters = array_slice($letters, 0, 60);

        $data = $structure['first_data_row'] >= 1 ? array_slice($this->extractor->dataRows($sheet, $structure), 0, 50, true) : [];
        $columns = [];
        foreach ($letters as $letter) {
            $header  = array_filter(array_map(fn ($h) => $cut($sheet[$h][$letter] ?? ''), $structure['header_rows']), fn ($v) => $v !== '');
            $samples = array_slice(array_values(array_filter(array_map(fn ($cells) => $cut($cells[$letter] ?? ''), $data), fn ($v) => $v !== '')), 0, 3);
            $columns[] = [
                'letter'  => $letter,
                'header'  => implode(' / ', $header),
                'samples' => $samples,
                'fields'  => array_keys(array_filter($structure['columns'], fn ($l) => $l === $letter)),
            ];
        }

        $rows = [];
        foreach (array_slice($sheet, 0, 12, true) as $n => $cells) {
            $rows[] = ['row' => $n, 'cells' => array_map($cut, array_intersect_key($cells, array_flip($letters)))];
        }

        return [
            'sheet'   => $detection['names'][$structure['sheet']] ?? "Sheet {$structure['sheet']}",
            'columns' => $columns,
            'rows'    => $rows,
        ];
    }

    /** Everything after the structure: extraction, AI labelling, checks. $companyId: the run's company (its code groups). */
    public function classifyDetected(UploadedFile $file, array $detection, ?callable $progress = null, ?string $companyId = null): array
    {
        $progress ??= fn () => null;
        ['sheets' => $sheets, 'names' => $names, 'file_warnings' => $fileWarnings, 'structure' => $structure] = $detection;
        $structureDoubts = array_values(array_filter($detection['issues'], fn ($i) => $i['severity'] === 'doubt' && ! ($i['acknowledged'] ?? false)));
        // Errors the user confirmed anyway: a swap is ⛔ (every balance would be wrong), the rest a normal warning.
        $overrides = ['severe' => [], 'other' => []];
        foreach ($detection['acknowledged'] ?? [] as $a) {
            in_array($a['check'], self::SEVERE_OVERRIDES, true)
                ? $overrides['severe'][] = self::m('warnings.override_severe', ['issue' => self::issuePhrase($a)])
                : $overrides['other'][] = self::m('warnings.override', ['issue' => self::issuePhrase($a)]);
        }

        $sheet     = $sheets[$structure['sheet']];
        $structure = $this->extractor->withInferredNotes($sheet, $structure);
        $dataRows  = $this->extractor->dataRows($sheet, $structure);
        if (! $dataRows) {
            throw self::noRows();
        }

        $headerRows = array_intersect_key($sheet, array_flip($structure['header_rows']));
        $groups     = $this->extractor->presentGroups($structure);
        $extracted  = array_map(fn ($cells) => $this->extractor->extract($cells, $structure), $dataRows);

        // Everything below is keyed by row number: account numbers can repeat (branches stacked in one sheet).
        $chart = [];
        foreach ($extracted as $n => $e) {
            if ($e['account']['account_number'] !== '' || $e['account']['account_name'] !== '') {
                $chart[] = [
                    'row'            => $n,
                    'account_number' => $e['account']['account_number'],
                    'account_name'   => $e['account']['account_name'],
                    'existing'       => $e['existing'] ?? $e['existing_note'],
                ];
            }
        }

        $adjustments = $this->adjustmentLines($sheets, $structure, $names, $chart);
        $pending     = array_values(array_filter($adjustments, fn ($a) => $a['needs_ai_match']));

        // Zero in every real group, to the halala: not an account to classify. Never sent to the AI (still a neighbour for
        // context), left out of the results. An adjusting entry on it keeps it.
        $zero = array_diff_key(array_filter($extracted, fn ($e) => ! $this->hasBalances($e['account'], $groups)),
            array_flip(array_filter(array_column($adjustments, 'account_row'), fn ($r) => $r !== null)));

        // Off however the rows are read: refused before any AI call (nothing paid for).
        if ($early = $this->clearlyUnbalanced($extracted, $this->reader->subtotalRows($structure['sheet']), $groups)) {
            throw $this->unbalanced($early);
        }

        // Distinct values of the file's own classification (column D, or E when there's no D), mapped once.
        $existingValues = [];
        foreach ($chart as $c) {
            $v = self::normText($c['existing'] ?? '');
            if ($v !== '' && ! isset($existingValues[$v])) {
                $existingValues[$v] = ['id' => count($existingValues) + 1, 'value' => $v];
            }
        }

        $fileContext = [
            'chart'           => $chart,
            'adjustments'     => $pending,
            'existing_values' => array_values($existingValues),
            'account_rows'    => array_column($chart, 'row'),
        ];

        // Rows seen before are labelled from the cache; only the rest goes to the AI.
        ['labels' => $cached, 'sources' => $cacheSources, 'flips' => $flips] = $this->cache->lookup($extracted);
        [$aiLabels, $matches, $existingMap, $batchWarnings, $failedRows] = $this->labelRows($structure, $headerRows, $dataRows, $fileContext, $progress, $cached, $zero);
        // Rows with no code whose parent was unknown before the AI answered: the memory looked up again, and it wins.
        ['labels' => $late, 'sources' => $lateSources, 'flips' => $lateFlips] = $this->cache->lookupGeneric($extracted, $aiLabels + $cached, array_keys($aiLabels));
        $aiLabels     = array_diff_key($aiLabels, $late);
        $cached       = $late + $cached;
        $cacheSources = $lateSources + $cacheSources;
        $flips       += $lateFlips;
        // The balance side re-decides a side-pair member the AI gave, as it does a cached one.
        [$aiLabels, $aiFlips] = ClassificationCache::applyFlips($aiLabels, $extracted);
        $flips += $aiFlips;
        $progress('checks', 0, 0);

        // SUBTOTAL/AGGREGATE rows are totals whatever the AI said: never counted as accounts.
        $subtotalRows = $this->reader->subtotalRows($structure['sheet']);
        [$labels, $unified] = $this->unifyLabels(array_diff_key($aiLabels + $cached, $subtotalRows), $extracted);

        foreach ($adjustments as &$line) {
            if ($line['needs_ai_match']) {
                $line['account_row'] = $matches[$line['id']] ?? null;
            }
        }
        unset($line);

        // Zero rows an adjusting entry was matched to by the AI are accounts after all.
        $zero = array_diff_key($zero, array_flip(array_filter(array_column($adjustments, 'account_row'), fn ($r) => $r !== null)));

        // A row the AI called a parent or section that the file balances only with is an account after all: counted (unlabelled,
        // so it is classified by hand and its label is never stored).
        [$labels, $rescued] = $this->rescueParents($labels, $extracted, $dataRows, $subtotalRows, $zero, $groups);

        $results      = [];
        $totalRows    = [];
        $skipped      = 0;
        $skippedNotes = [];
        $skippedInfo  = [];
        $dropped      = [];   // row => closing [debit, credit] of non-account rows that carry balances
        $unclassified = [];   // row => 'failed' (its batch failed) | 'missed' (the AI left it out, even after the retry)
        $accountRows  = [];   // row => true for the rows kept as accounts
        $heading      = null; // the heading above the next accounts: the last section, parent or named zero row with no number, until a total

        foreach ($dataRows as $n => $cells) {
            $label = $labels[$n] ?? null;
            ['account' => $account, 'problems' => $problems, 'note' => $note, 'request' => $request,
             'existing' => $existing, 'existing_note' => $existingNote, 'note_reference' => $noteReference] = $extracted[$n];

            if (isset($subtotalRows[$n])) {
                $label = ['row' => $n, 'row_type' => 'total'];
            } elseif (isset($zero[$n])) {
                $label = ['row' => $n, 'row_type' => 'zero'];
            }

            $isAccount = $label === null
                // Still unlabelled after the retry: keep it for review if it looks like an account.
                ? ($account['account_name'] !== '' || $account['account_number'] !== '')
                : $label['row_type'] === 'account';

            if (! $isAccount) {
                if (($label['row_type'] ?? null) === 'total') {
                    $totalRows[$n] = $account;
                    $heading = null;
                } elseif (in_array($label['row_type'] ?? null, ['section', 'parent', 'zero'], true) && trim($account['account_name'], " .\u{00A0}") !== ''
                    // A zero row with a number is an account with no balance (a supplier), not a heading.
                    && ($label['row_type'] !== 'zero' || trim($account['account_number']) === '')) {
                    $heading = trim($account['account_name'], " .\u{00A0}");
                }
                // Totals and parents are left out on purpose (their accounts are counted); anything else with balances
                // silently leaves every total, so the reviewer must see it.
                if (! in_array($label['row_type'] ?? null, ['total', 'parent'], true) && $this->hasBalances($account, $groups)) {
                    $dropped[$n] = [$account['closing_debit'], $account['closing_credit']];
                }
                if ($note !== null) {
                    // A remark on a non-account row must still reach the user.
                    $skippedNotes[] = self::m('warnings.skipped_note', ['row' => $n, 'note' => $note]);
                }
                if ($request !== null) {
                    $skippedInfo[] = self::m('info.skipped_request', ['row' => $n, 'request' => $request]);
                }
                $skipped++;
                continue;
            }

            if ($label === null && ! isset($rescued[$n])) {
                $unclassified[$n] = isset($failedRows[$n]) ? 'failed' : 'missed';
            }
            $accountRows[$n] = true;
            $row = $this->buildRow($account, $label, $extracted[$n]['problem_columns'] ?? [], $groups, $note) + [
                'row'                          => $n,
                'audit_request'                => $request,
                'existing_classification'      => $existing,
                'existing_note_classification' => $existingNote,
                'note_reference'               => $noteReference,
                'heading'                      => $heading,
                // Who gave the class: ai, cache (an earlier AI answer), a cache source (manual_correction, imported…), or
                // rule (applyRules); null: unclassified.
                'classification_source'        => $label === null ? null : match ($cacheSources[$n] ?? null) {
                    null => 'ai', ClassificationCache::AI => 'cache', default => $cacheSources[$n],
                },
            ];
            if (isset($unified[$n])) {
                $row['data_flags'][] = $unified[$n];
            }
            if ($f = $flips[$n] ?? null) {
                // A flagged pair (customers, suppliers) needs a review; the others are only noted. A side that contradicts a
                // class the pair may not flip (a non-VAT government receivable) always needs a review.
                $side = self::m('sides.'.($f['side'] === 'debit' ? 'debit' : 'credit'));
                $row['data_flags'][] = $f['to'] === null
                    ? self::flag(['type' => 'side_contradiction', 'difference' => null],
                        self::m('flags.side_contradiction', ['side' => $side, 'from' => $f['from'], 'label' => $f['label']]))
                    : self::flag(['type' => 'side_flip', 'difference' => null],
                        self::m($f['flag'] ? 'flags.side_flip_review' : 'flags.side_flip', ['from' => $f['from'], 'to' => $f['to']]))
                        + ($f['flag'] ? [] : ['severity' => 'info']);
            }
            $results[] = $row;
        }

        if (! $results) {
            throw self::noRows();
        }

        [$parentRows, $levels] = $this->parentInfo($file, $structure, $dataRows, $extracted, $headerRows, $accountRows, $groups);
        $results = $this->flagLevelParents($results, $parentRows, $levels);
        // A parent by the file's levels is the heading of the accounts under it (the innermost one wins).
        $byRow = array_flip(array_column($results, 'row'));
        foreach ($results as $r) {
            foreach (collect($r['data_flags'])->firstWhere('type', ParentRows::LEVEL)['rows'] ?? [] as $child) {
                if (isset($byRow[$child])) {
                    $results[$byRow[$child]]['heading'] = trim($r['account_name'], " .\u{00A0}");
                }
            }
        }
        $results = $this->applyRules($results, ClassificationRules::load($companyId), $groups,
            fn (array $r) => $this->existingMains($r, $existingValues, $existingMap));
        foreach ($results as $r) {
            if ($r['classification_source'] === 'rule') {
                unset($flips[$r['row']]);   // the rule decided by the balance: its summary says so, not the pair's flip
            }
        }
        [$results, $missingNumbers] = $this->flagMissingNumbers($results);
        foreach ($results as &$r) {
            if (isset($rescued[$r['row']])) {
                $r['data_flags'][] = self::flag(['type' => 'parent_rescued', 'difference' => null], self::m('flags.parent_rescued'));
            }
        }
        unset($r);
        $results = $this->flagParentAccounts($results);
        $results = $this->flagProbableParents($results, $parentRows, $levels, $totalRows, $groups);
        $results = $this->flagNumberPrefixes($results);
        $results = $this->flagExistingClassification($results, $existingValues, $existingMap);
        $results = $this->flagExpenseNames($results);
        [$results, $duplicateInfo, $duplicateWarnings] = $this->flagDuplicateNumbers($results);
        [$results, $adjustmentWarnings] = $this->applyAdjustments($results, $adjustments, $groups);
        [$results, $accountWarnings] = $this->flagAccountMisuse($results);
        $checks  = $this->fileChecks($results, $totalRows, $groups);
        $results = $this->capConfidence($results, $cacheSources);

        $lateDoubts = $this->checks->afterClassification($results);
        $this->logIssues('after_classification', null, $lateDoubts);
        $structureWarnings = array_map(fn ($i) => self::m('warnings.structure_doubt', ['issue' => self::issuePhrase($i)]), array_merge($structureDoubts, $lateDoubts));

        $statementRows = array_values(array_filter($results, fn ($r) => array_filter($r['data_flags'], fn ($f) => $f['type'] === 'statement_mismatch'
            || ($f['type'] === 'prefix_mismatch' && ($f['severity'] ?? null) === 'high'))));
        $statementWarning = $statementRows ? [self::m('warnings.statement', ['count' => count($statementRows), 'accounts' => self::shortList(
            array_map(fn ($r) => self::m('quoted_at', ['value' => $r['account_name'], 'rows' => FileRows::phrase([$r['row']])]), array_slice($statementRows, 0, 10)),
            count($statementRows) - 10)])] : [];

        // Good AI answers are stored for next time; rows flagged for a classification problem wait for a review.
        $excluded = [];
        foreach ($results as $r) {
            if (array_intersect(array_column($r['data_flags'], 'type'), ClassificationCache::NOT_STORED_FLAGS)) {
                $excluded[$r['row']] = true;
            }
        }
        $keys    = $this->cache->store($extracted, $labels, array_fill_keys(array_keys(array_diff_key($aiLabels, $subtotalRows)), true), $excluded);
        $results = array_map(fn ($r) => $r + ['cache_key' => $keys[$r['row']] ?? null], $results);
        // An unbalanced trial balance is refused, after the AI's answers are kept for the corrected file.
        if ($checks['reject']) {
            throw $this->unbalanced($checks['reject']);
        }
        $cacheStats = [
            'rows_from_cache'  => count($cached),
            'manual'           => count(array_keys($cacheSources, ClassificationCache::MANUAL, true)),
            'ai'               => count(array_keys($cacheSources, ClassificationCache::AI, true)),
            'imported'         => count(array_keys($cacheSources, ClassificationCache::IMPORTED, true)),
            'imported_code'    => count(array_keys($cacheSources, ClassificationCache::IMPORTED_CODE, true)),
            'name_layer'       => count(array_keys($cacheSources, ClassificationCache::NAME_LAYER, true)),
            'rows_sent_to_llm' => count(array_diff_key($dataRows, $cached, $zero)) + count($late),   // found late, but asked
            'side_flips'       => self::sideFlips($flips),
        ];

        $rows  = array_map([$this, 'finalizeRow'], $results);
        // Code groups the AI mostly put in one rule's kind: proposed for the user to mark (ClassificationRules).
        $proposals = array_map(fn ($p) => ['prefix' => $p['prefix'], 'rule_id' => $p['rule']->id, 'rule' => $p['rule']->name, 'rows' => $p['rows'], 'share' => $p['share']],
            ClassificationRules::load($companyId)->proposals($rows));
        $usage = app(UsageMeter::class)->totals();
        Log::channel(config('classification.log_channel', 'classification'))->info('classification_summary', [
            'provider'           => Provider::name(),
            'model'              => Provider::model(),
            'usage'              => $usage,
            'rows'               => count($rows),
            'needs_review'       => count(array_filter($rows, fn ($r) => $r['needs_review'])),
            'statement_mismatch' => count($statementRows),
            'unclassified'       => count($unclassified),
            'cache'              => $cacheStats,
        ]);

        $warnings = array_merge($overrides['severe'], $statementWarning, $overrides['other'], $fileWarnings, $structureWarnings, $checks['warnings'], $this->droppedWarning($dropped), $accountWarnings, $adjustmentWarnings, $batchWarnings, $duplicateWarnings, $skippedNotes);
        // No opening balances (no columns, or all zero): accepted (the accountant, 2026-10-20), said plainly.
        $noOpening = ! in_array('opening', $groups, true)
            || ! array_filter($rows, fn ($r) => abs((float) $r['opening_debit']) > 0.005 || abs((float) $r['opening_credit']) > 0.005);
        $zeroInfo = $noOpening ? [self::m('info.no_opening')] : [];
        $zeroInfo = array_merge($zeroInfo, $missingNumbers ? [self::m('info.missing_numbers', ['count' => count($missingNumbers), 'rows' => FileRows::phrase($missingNumbers)])] : []);
        $zeroInfo = array_merge($zeroInfo, $zero ? [self::m('info.zero_rows', ['count' => count($zero), 'rows' => FileRows::phrase(array_keys($zero))])] : []);
        $info     = array_merge($checks['info'], $zeroInfo, $duplicateInfo, $skippedInfo, array_merge(...array_map(fn ($f) => array_values(array_filter([
            $f['rows'] ? self::m($f['flagged'] ? 'info.side_flips_flagged' : 'info.side_flips', ['rows' => $f['rows'], 'label' => $f['label']]) : null,
            $f['contradictions'] ? self::m('info.side_contradictions', ['count' => $f['contradictions'], 'label' => $f['label']]) : null,
        ])), $cacheStats['side_flips']) ?: [[]]), self::ruleSummary($rows));

        return [
            'cache'        => $cacheStats,
            'side_flips'   => $cacheStats['side_flips'],
            'provider'     => Provider::name(),
            'model'        => Provider::model(),
            'usage'        => $usage,
            'rows'         => $rows,
            'skipped_rows' => $skipped,
            'unclassified' => self::unclassified($unclassified),
            'groups'       => $groups,
            'code_group_proposals' => $proposals,
            // Arabic as always; the same messages as stored phrases beside them, for any language (ClassificationResponse).
            'warnings'         => array_map(fn (Phrase $p) => $p->ar(), $warnings),
            'warnings_phrases' => array_map(fn (Phrase $p) => $p->toArray(), $warnings),
            'info'             => array_map(fn (Phrase $p) => $p->ar(), $info),
            'info_phrases'     => array_map(fn (Phrase $p) => $p->toArray(), $info),
            'other_sheets' => array_map(fn ($o) => [
                'sheet'       => $o['sheet'],
                'name'        => $names[$o['sheet']] ?? "Sheet {$o['sheet']}",
                'type'        => $o['type'],
                'description' => $o['description'],
            ], $structure['other_sheets']),
            'adjustments'  => array_map(fn ($a) => [
                'sheet'          => $a['sheet_name'],
                'row'            => $a['row'],
                'account_name'   => $a['account_name'],
                'debit'          => $a['debit'],
                'credit'         => $a['credit'],
                'account_number' => $a['account_number'] !== '' ? $a['account_number'] : null,
                'account_row'    => $a['account_row'],
            ], $adjustments),
        ];
    }

    /**
     * Lines from every adjustments sheet, matched to a trial-balance ROW. A line whose account number
     * appears on exactly one row is matched here; name-only lines, and numbers that repeat (ambiguous),
     * are matched by the AI; a number not in the trial balance maps to null.
     */
    private function adjustmentLines(array $sheets, array $structure, array $names, array $chart): array
    {
        $rowsByNumber = [];
        foreach ($chart as $c) {
            if ($c['account_number'] !== '') {
                $rowsByNumber[AmountParser::asciiDigits($c['account_number'])][] = $c['row'];
            }
        }

        $lines = [];
        foreach ($structure['other_sheets'] as $other) {
            if ($other['type'] !== 'adjustments' || ! isset($sheets[$other['sheet']])) {
                continue;
            }
            foreach ($this->extractor->adjustmentLines($sheets[$other['sheet']], $other) as $line) {
                $candidates = $line['account_number'] !== '' ? ($rowsByNumber[AmountParser::asciiDigits($line['account_number'])] ?? []) : null;
                $lines[] = $line + [
                    'id'             => count($lines) + 1,
                    'sheet'          => $other['sheet'],
                    'sheet_name'     => $names[$other['sheet']] ?? "Sheet {$other['sheet']}",
                    'account_row'    => $candidates !== null && count($candidates) === 1 ? $candidates[0] : null,
                    'needs_ai_match' => $candidates === null || count($candidates) > 1,
                ];
            }
        }
        return $lines;
    }

    /**
     * A reconciliation difference equal to the account's adjustments (debit − credit) is explained by them:
     * the message says so, but the flag and needs_review stay — a reviewer must confirm.
     *
     * @return array{0: list<array>, 1: list<string>}
     */
    private function applyAdjustments(array $rows, array $adjustments, array $groups): array
    {
        $inSheet = in_array('adjustment', $groups, true);
        if (! $adjustments) {
            return [$rows, []];
        }

        $tolerance = (float) config('classification.reconciliation_tolerance', 0.01);
        $sums      = [];
        $sheetsFor = [];
        foreach ($adjustments as $a) {
            if ($a['account_row'] === null) {
                continue;
            }
            $key              = $a['account_row'];
            $sums[$key]       = ($sums[$key] ?? 0.0) + $a['debit'] - $a['credit'];
            $sheetsFor[$key][$a['sheet_name']] = true;
        }

        $unmatched = false;
        foreach ($rows as &$row) {
            // In-sheet adjustment columns already explain this row's closing; the sheet matching doesn't apply.
            if ($inSheet && ($row['adjustment_debit'] != 0.0 || $row['adjustment_credit'] != 0.0)) {
                continue;
            }
            foreach ($row['data_flags'] as &$flag) {
                if ($flag['type'] !== 'reconciliation') {
                    continue;
                }
                $key = $row['row'];
                if (isset($sums[$key]) && abs($sums[$key] - $flag['difference']) <= $tolerance) {
                    $sheet   = implode('، ', array_keys($sheetsFor[$key]));
                    $amount  = self::money(abs($sums[$key]));
                    $flag    = self::flag(array_diff_key($flag, ['message' => 1, 'message_phrase' => 1]),
                        self::m('flags.reconciliation_matched', ['amount' => $amount, 'sheet' => array_keys($sheetsFor[$key])]));
                    $flag['matched_adjustment'] = ['sheet' => $sheet, 'amount' => round($sums[$key], 2)];
                } else {
                    $unmatched = true;
                }
            }
            unset($flag);
        }
        unset($row);

        return [$rows, $unmatched ? [self::m('warnings.adjustments_sheet')] : []];
    }

    /** @return array{0: array, 1: array, 2: list<string>} sheets, sheet names, file-level warnings */
    private function read(UploadedFile $file): array
    {
        $sheets = $this->reader->read($file);
        if (! $sheets) {
            throw self::noRows();
        }
        $fileWarnings = $this->reader->hasManualCalculation($file) ? [self::m('warnings.manual_calculation')] : [];

        return [$sheets, $this->reader->sheetNames($file, array_keys($sheets)), $fileWarnings];
    }

    /**
     * AI mapping → checks → one retry with the failed checks. Doubts don't retry.
     *
     * @return array{0: array, 1: list<array>} the last mapping and its issues (errors remain when the retry failed too)
     */
    private function detectStructure(array $sheets, array $names): array
    {
        $letters = [];
        foreach ($sheets as $rows) {
            foreach ($rows as $cells) {
                $letters += array_flip(array_keys($cells));
            }
        }
        $letters = array_keys($letters);

        $preview = array_map(
            fn ($rows) => array_slice($rows, 0, config('classification.structure_preview_rows', 30), true),
            array_slice($sheets, 0, config('classification.max_sheets', 10), true)
        );

        $errors = [];
        foreach ([1, 2] as $attempt) {
            $structure = $this->extractor->normalize($this->classifier->detectStructure($preview, $errors, $names, $letters));
            $structure = $this->extractor->withNoteReferences($sheets[$structure['sheet']] ?? [], $structure);
            $issues    = $this->checks->check($sheets, $structure);
            $this->logIssues('structure', $attempt, $issues);

            $failed = array_filter($issues, fn ($i) => $i['severity'] === 'error');
            if (! $failed) {
                break;
            }
            $errors = array_values(array_column($failed, 'ai'));   // the retry is told exactly which checks failed
        }

        return [$structure, $issues];
    }

    /** One JSON line per issue in the classification log (run id comes from the job's shared context). */
    private function logIssues(string $stage, ?int $attempt, array $issues): void
    {
        $log = Log::channel(config('classification.log_channel', 'classification'));
        foreach ($issues as $issue) {
            $log->info('structure_issue', ['stage' => $stage, 'attempt' => $attempt] + array_diff_key($issue, ['ai' => 0, 'user' => 0, 'user_phrase' => 0]));
        }
        $log->info('structure_summary', [
            'stage'   => $stage,
            'attempt' => $attempt,
            'errors'  => count(array_filter($issues, fn ($i) => $i['severity'] === 'error')),
            'doubts'  => count(array_filter($issues, fn ($i) => $i['severity'] === 'doubt')),
            'checks'  => array_values(array_unique(array_column($issues, 'check'))),
        ]);
    }

    /**
     * Labels every data row in batches, and matches the file-level lists (adjustment lines, existing classification
     * values) in one dedicated call when the file has any.
     *
     * - With more than one request, a minimal call first writes the prompt cache (rules + full chart) so every request reads it.
     * - The matching call, then the batches, run with at most `concurrency` requests in flight; a finished one frees its slot.
     * - Failed requests are retried by the repository (up to 2 times). An answer with missing/duplicate/invalid items is
     *   sent once more with only the gaps, in the same slot, and only the gaps are filled. Finished batches are always kept.
     * - A batch that still fails leaves its rows unlabelled (they come back unclassified, for review) and adds a warning;
     *   matches still missing add a warning that the check didn't run for them.
     * - A fatal provider error (no credit, bad key), or every batch failing, aborts the file.
     *
     * @return array{0: array<int, array>, 1: array<int, ?int>, 2: array<int, list<string>>, 3: list<string>, 4: array<int, true>}
     *         labels, adjustment id => account row, existing value id => main classes, warnings, rows of failed batches
     */
    private function labelRows(array $structure, array $headerRows, array $dataRows, array $fileContext, callable $progress, array $cached = [], array $skip = []): array
    {
        $size        = max(1, (int) config('classification.batch_size', 40));
        $context     = max(0, (int) config('classification.context_rows', 3));
        $concurrency = (int) config('classification.concurrency', 4);
        $concurrency = $concurrency >= 1 ? $concurrency : 4;
        $all         = array_keys($dataRows);
        $position    = array_flip($all);
        $numbers     = array_values(array_diff($all, array_keys($cached), array_keys($skip)));   // only the rows the cache didn't answer, zero rows left out

        // The nearest parent and section above each row, as far as the cache knows (a zero row, not classified, is taken
        // for a heading): context for the rows sent.
        $headers = [];
        $above   = ['parent' => null, 'section' => null];
        foreach ($all as $n) {
            $headers[$n] = array_filter($above);
            $type = $cached[$n]['row_type'] ?? (isset($skip[$n]) ? 'parent' : null);
            if ($type !== null && array_key_exists($type, $above)) {
                $above[$type] = $n;
                if ($type === 'section') {
                    $above['parent'] = null;   // a new section: the previous section's parent no longer applies
                }
            }
        }

        $batches = [];
        foreach (array_chunk($numbers, $size) as $i => $chunkNumbers) {
            // Neighbours in the file (cached or not), so a batch reads the same as without the cache.
            $first  = $position[$chunkNumbers[0]];
            $last   = $position[end($chunkNumbers)];
            $around = array_merge(
                array_slice($all, max(0, $first - $context), min($context, $first)),
                array_slice($all, $last + 1, $context),
                ...array_map(fn ($n) => array_values($headers[$n]), $chunkNumbers)
            );
            $batches[$i] = [
                'rows'    => array_intersect_key($dataRows, array_flip($chunkNumbers)),
                'context' => array_diff_key(array_intersect_key($dataRows, array_flip($around)), array_flip($chunkNumbers)),
            ];
        }

        $total    = count($batches);
        $done     = 0;
        $labels   = [];
        $warnings = [];
        $failed   = [];   // batch => its final error, for batches that got no labels at all
        $failedRows = []; // row => true: rows of batches that still failed (the rest of an unlabelled account was missed by the AI)
        $progress('classifying', 0, $total);

        $batchArgs  = fn (array $rows, array $context) => [$structure, $headerRows, $rows, $context, $fileContext, null];
        $matchArgs  = fn (array $adjust, array $exist) => [$structure, $headerRows, [], [], $fileContext,
            ['adjustments' => array_column($adjust, 'id'), 'existing' => array_column($exist, 'id')]];
        $adjust     = $fileContext['adjustments'];
        $exist      = $fileContext['existing_values'];
        $needsMatch = $adjust !== [] || $exist !== [];

        // The matching call goes first in line, so it never ends up last and extends the run.
        $requests = ($needsMatch ? ['matches' => $matchArgs($adjust, $exist)] : [])
            + array_map(fn ($b) => $batchArgs($b['rows'], $b['context']), $batches);

        if (count($requests) > 1) {
            try {
                $this->classifier->warmCache($structure, $headerRows, $fileContext);
            } catch (ProviderException $e) {
                if ($e->fatal) {
                    throw $e;
                }
                report($e);   // the requests still work, each writing the cache itself
            }
        }

        $matches  = [];
        $existing = [];
        $onMatches = function (array|Throwable $answer, bool $isRetry) use (&$matches, &$existing, $adjust, $exist, $matchArgs, $fileContext): ?array {
            if ($answer instanceof Throwable) {
                if ($answer instanceof ProviderException && $answer->fatal) {
                    throw $answer;
                }
                report($answer);   // what is still missing is reported after the run
                return null;
            }
            try {
                $matches  += $this->validMatches($adjust, $answer['adjustment_matches'] ?? [], $fileContext['account_rows']);
                $existing += $this->validExistingMatches($exist, $answer['existing_class_matches'] ?? []);
            } catch (Throwable $e) {
                report($e);   // a malformed answer never fails the file: what is missing is retried once, then warned about
            }

            // Gaps in the first answer: one retry of only what is missing.
            if (! $isRetry && (count($matches) < count($adjust) || count($existing) < count($exist))) {
                return $matchArgs(
                    array_values(array_filter($adjust, fn ($a) => ! array_key_exists($a['id'], $matches))),
                    array_values(array_filter($exist, fn ($e) => ! isset($existing[$e['id']])))
                );
            }
            return null;
        };

        $found   = array_fill_keys(array_keys($batches), []);   // valid labels so far, per batch
        $onBatch = function (int $i, array|Throwable $answer, bool $isRetry) use (&$found, &$labels, &$failedRows, &$failed, &$done, $batches, $total, $progress, $batchArgs): ?array {
            $b = $batches[$i];

            if ($answer instanceof Throwable) {
                if ($answer instanceof ProviderException && $answer->fatal) {
                    throw $answer;   // no credit, bad key…: stop the run
                }
                report($answer);
                if ($found[$i] === []) {
                    $failed[$i] = $answer;
                }
                $failedRows += array_fill_keys(array_keys(array_diff_key($b['rows'], $found[$i])), true);   // reported in meta.unclassified
            } else {
                $found[$i] += $this->validLabels($b['rows'], $answer['rows'] ?? []);

                // Gaps in the first answer: one retry of only the missing rows, the labelled ones shown as context.
                if (! $isRetry && count($found[$i]) < count($b['rows'])) {
                    $context = $b['context'] + array_intersect_key($b['rows'], $found[$i]);
                    ksort($context);

                    return $batchArgs(array_diff_key($b['rows'], $found[$i]), $context);
                }
            }

            $labels += $found[$i];
            $progress('classifying', ++$done, $total);

            return null;
        };

        if ($requests) {   // none when the cache answered every row and nothing needs matching
            $this->classifier->classifyRowsRolling($requests, $concurrency, fn ($key, $answer, bool $isRetry) => $key === 'matches'
                ? $onMatches($answer, $isRetry)
                : $onBatch($key, $answer, $isRetry));
        }

        // Nothing classified at all: a result of only unclassified rows is useless, report the provider error instead.
        if ($total > 0 && count($failed) === $total) {
            throw end($failed);
        }

        if ($missing = count($adjust) - count($matches)) {
            $warnings[] = self::m('warnings.adjustments_unmatched', ['count' => $missing]);
        }
        if ($missing = count($exist) - count($existing)) {
            Log::channel(config('classification.log_channel', 'classification'))->warning('existing_matching_incomplete', [
                'missing' => $missing, 'total' => count($exist),
            ]);
            $warnings[] = self::m('warnings.existing_unmatched', ['count' => $missing]);
        }

        return [$labels, $matches, $existing, $warnings, $failedRows];
    }

    /** Adjustment id => account row (null = not in the trial balance). Unknown ids, duplicates and unknown rows are rejected. */
    private function validMatches(array $adjustments, array $raw, array $accountRows): array
    {
        $ids   = array_flip(array_column($adjustments, 'id'));
        $rows  = array_flip($accountRows);
        $count = [];
        $byId  = [];
        foreach ($raw as $match) {
            $id = is_array($match) ? ($match['adjustment_row'] ?? null) : null;
            if (! is_int($id) || ! isset($ids[$id])) {
                continue;
            }
            $count[$id] = ($count[$id] ?? 0) + 1;
            $byId[$id]  = $match['account_row'] ?? null;
        }

        $valid = [];
        foreach ($byId as $id => $row) {
            if ($count[$id] > 1 || ($row !== null && (! is_int($row) || ! isset($rows[$row])))) {
                continue;
            }
            $valid[$id] = $row;
        }
        return $valid;
    }

    /** Existing value id => main classes it covers (only names from the table). */
    private function validExistingMatches(array $values, array $raw): array
    {
        $ids   = array_flip(array_column($values, 'id'));
        $mains = array_flip($this->taxonomy->mainClasses());
        $valid = [];
        $count = [];
        foreach ($raw as $match) {
            $id = is_array($match) ? ($match['existing_id'] ?? null) : null;
            if (! is_int($id) || ! isset($ids[$id]) || ! is_array($match['main_classes'] ?? null)) {
                continue;
            }
            $count[$id] = ($count[$id] ?? 0) + 1;
            $valid[$id] = array_values(array_filter($match['main_classes'], fn ($m) => is_string($m) && isset($mains[$m])));
        }
        return array_filter($valid, fn ($id) => $count[$id] === 1, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Compares each row with the file's own classification:
     * - classification_mismatch: our main class is not one the file's value covers (mapping done once per value);
     * - existing_classification_conflict: the same account number carries different file classifications.
     * A file value that covers only expenses (a function such as «تكلفة إيرادات» or «المصروفات العمومية والإدارية») does not
     * contradict an expense nature class (rent, salaries…): the two say different things about the same expense.
     */
    private function flagExistingClassification(array $rows, array $existingValues, array $existingMap): array
    {
        foreach ($rows as &$row) {
            $value = self::normText($row['existing_classification'] ?? $row['existing_note_classification'] ?? '');
            $mains = $this->existingMains($row, $existingValues, $existingMap);
            $row['existing_main_classes'] = $mains;

            $statements = $mains ? array_values(array_unique(array_filter(array_map(fn ($m) => $this->statementOf($m), $mains)))) : [];
            if ($statements && $row['financial_statement'] !== null && ! in_array($row['financial_statement'], $statements, true)) {
                // A different statement moves the account between profit and the balance sheet: worse than a class difference.
                $row['data_flags'][] = self::flag(['type' => 'statement_mismatch', 'severity' => 'high', 'difference' => null],
                    self::m('flags.statement_mismatch_file', ['value' => $value, 'statements' => self::joined($statements, 'or_next'),
                        'statement' => $row['financial_statement'], 'sub_class' => $row['sub_class']]));
            } elseif ($mains && $row['main_class'] !== null && ! in_array($row['main_class'], $mains, true) && ! $this->allExpenses([$row['main_class'], ...$mains])) {
                $row['data_flags'][] = self::flag(['type' => 'classification_mismatch', 'difference' => null],
                    self::m('flags.classification_mismatch', ['value' => $value, 'main_class' => $row['main_class']]));
            }
        }
        unset($row);

        foreach ($this->groupByNumber($rows) as $indexes) {
            $values = [];
            foreach ($indexes as $i) {
                $v = self::normText($rows[$i]['existing_classification'] ?? $rows[$i]['existing_note_classification'] ?? '');
                if ($v !== '') {
                    $values[$v][] = (int) $rows[$i]['row'];
                }
            }
            if (count($values) < 2) {
                continue;
            }
            $list = array_map(fn ($v, $r) => self::m('quoted_at', ['value' => $v, 'rows' => FileRows::phrase($r)]), array_keys($values), $values);
            foreach ($indexes as $i) {
                $rows[$i]['data_flags'][] = self::flag(['type' => 'existing_classification_conflict', 'difference' => null],
                    self::m('flags.existing_conflict', ['list' => $list]));
            }
        }

        return $rows;
    }

    /**
     * Safety net for files without their own classification: an account named as an expense ("مصروف …", "مصاريف …")
     * that is not prepaid, accrued or deferred belongs to the income statement. On the balance sheet it moves profit, so
     * it gets the same high-priority flag. Not re-classified: PHP can't tell which expense class is right.
     */
    private function flagExpenseNames(array $rows): array
    {
        foreach ($rows as &$row) {
            if ($row['financial_statement'] === null || $row['financial_statement'] === self::INCOME_STATEMENT
                || ! preg_match(self::EXPENSE_NAME, $row['account_name']) || preg_match(self::PREPAID_OR_ACCRUED, $row['account_name'])
                || in_array('statement_mismatch', array_column($row['data_flags'], 'type'), true)
                // The file's own classification puts it on this statement too (a prepaid expense named «مصاريف …»).
                || in_array($row['financial_statement'], array_map(fn ($m) => $this->statementOf($m), $row['existing_main_classes'] ?? []), true)) {
                continue;
            }
            $row['data_flags'][] = self::flag(['type' => 'statement_mismatch', 'severity' => 'high', 'difference' => null],
                self::m('flags.statement_mismatch_name', ['income' => self::INCOME_STATEMENT, 'statement' => $row['financial_statement'], 'sub_class' => $row['sub_class']]));
        }
        unset($row);

        return $rows;
    }

    /** Every main class is an expense or a cost (MAJOR_GROUPS folds cost into expenses). */
    private function allExpenses(array $mainClasses): bool
    {
        foreach ($mainClasses as $main) {
            $family = $this->taxonomy->familyOf($main);
            if ($family === null || self::MAJOR_GROUPS[$family] !== 'مصروفات') {
                return false;
            }
        }
        return true;
    }

    private function statementOf(string $mainClass): ?string
    {
        foreach ($this->taxonomy->map() as [$statement, $main]) {
            if ($main === $mainClass) {
                return $statement;
            }
        }
        return null;
    }

    /**
     * Same number + different names → one file-level warning, and a low-priority row flag (severity "info": shown in the
     * note, never sets needs_review on its own; in files with sub-accounts or branches it is normal and would drown the rest).
     * Same number + same name → one info message (typical of a consolidated TB with branches stacked).
     *
     * @return array{0: list<array>, 1: list<string>, 2: list<string>} rows, info, warnings
     */
    private function flagDuplicateNumbers(array $rows): array
    {
        $sameName       = [];
        $differentNames = [];
        foreach ($this->groupByNumber($rows) as $number => $indexes) {
            $names = [];
            foreach ($indexes as $i) {
                $names[self::normText($rows[$i]['account_name'])][] = (int) $rows[$i]['row'];
            }
            if (count($names) === 1) {
                $sameName[] = $rows[$indexes[0]]['account_number'].' ×'.count($indexes);
                continue;
            }
            $differentNames[] = self::m('number_names', ['number' => $rows[$indexes[0]]['account_number'], 'count' => count($names)]);
            $list = array_map(fn ($n, $r) => self::m('quoted_at', ['value' => $n, 'rows' => FileRows::phrase($r)]), array_keys($names), $names);
            foreach ($indexes as $i) {
                $rows[$i]['data_flags'][] = self::flag(['type' => 'duplicate_account_number', 'severity' => 'info', 'difference' => null],
                    self::m('flags.duplicate_number', ['list' => $list]));
            }
        }

        $warnings = [];
        if ($differentNames) {
            $shown      = array_slice($differentNames, 0, 10);
            $more       = count($differentNames) - count($shown);
            $warnings[] = self::m('warnings.duplicate_numbers', ['count' => count($differentNames), 'list' => self::shortList($shown, $more)]);
        }

        $info = [];
        if ($sameName) {
            $shown  = array_slice($sameName, 0, 10);
            $more   = count($sameName) - count($shown);
            $info[] = self::m('info.duplicate_same_name', ['count' => count($sameName), 'list' => self::shortList($shown, $more)]);
        }
        return [$rows, $info, $warnings];
    }

    /**
     * The same account (number + name) must get one classification: the AI can label its rows differently, across
     * batches or even in one batch. The majority label wins (tie: higher confidence, then the first row), and every row
     * of a group that disagreed is flagged for review, the disagreement itself showing the AI was unsure.
     * When the labels differ in normal side (supplier with a debit balance → advance payments), the rows are first split
     * by their balance side (closing, else opening) and unified per side only: those differences are correct.
     *
     * @param  array<int, array>  $labels  row => valid label
     * @return array{0: array<int, array>, 1: array<int, array>} labels, row => inconsistent_classification flag
     */
    private function unifyLabels(array $labels, array $extracted): array
    {
        $groups = [];
        foreach ($labels as $n => $label) {
            $account = $extracted[$n]['account'] ?? null;
            $number  = $account ? AmountParser::asciiDigits(trim($account['account_number'])) : '';
            $name    = $account ? self::normText($account['account_name']) : '';
            if ($label['row_type'] === 'account' && $number !== '' && $name !== '') {
                $groups[$number."\x1F".$name][] = $n;
            }
        }

        $flags     = [];
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        foreach ($groups as $rows) {
            $subs = array_unique(array_map(fn ($n) => $labels[$n]['sub_class'], $rows));
            if (count($subs) < 2) {
                continue;
            }

            $buckets = ['all' => $rows];
            $normals = array_unique(array_filter(array_map(fn ($s) => $this->taxonomy->normalSide($s), $subs)));
            if (count($normals) > 1) {
                $buckets = [];
                foreach ($rows as $n) {
                    $a   = $extracted[$n]['account'];
                    $net = $a['closing_debit'] - $a['closing_credit'];
                    if (abs($net) <= $tolerance) {
                        $net = $a['opening_debit'] - $a['opening_credit'];
                    }
                    $buckets[abs($net) <= $tolerance ? 'none' : ($net > 0 ? 'debit' : 'credit')][] = $n;
                }
            }

            foreach ($buckets as $bucket) {
                $bySub = [];
                foreach ($bucket as $n) {
                    $bySub[$labels[$n]['sub_class']][] = $n;
                }
                if (count($bySub) < 2) {
                    continue;
                }
                $winner = null;
                foreach ($bucket as $n) {
                    $votes = count($bySub[$labels[$n]['sub_class']]);
                    $best  = $winner === null ? null : count($bySub[$labels[$winner]['sub_class']]);
                    if ($winner === null || $votes > $best
                        || ($votes === $best && (int) ($labels[$n]['confidence'] ?? 0) > (int) ($labels[$winner]['confidence'] ?? 0))) {
                        $winner = $n;
                    }
                }

                $chosen  = $labels[$winner];
                $choices = Phrase::join(array_map(fn ($sub, $ns) => self::m('quoted_at', ['value' => $sub, 'rows' => FileRows::phrase($ns)]),
                    array_keys($bySub), $bySub), ' · ');
                foreach ($bucket as $n) {
                    $labels[$n] = ['row' => $n] + $chosen;
                    $flags[$n]  = self::flag(['type' => 'inconsistent_classification', 'difference' => null],
                        self::m('flags.inconsistent', ['choices' => $choices, 'chosen' => $chosen['sub_class']]));
                }
            }
        }

        return [$labels, $flags];
    }

    /** @return array<string, list<int>> normalized account number => indexes into $rows, only numbers that repeat */
    private function groupByNumber(array $rows): array
    {
        $groups = [];
        foreach ($rows as $i => $row) {
            $number = AmountParser::asciiDigits(trim($row['account_number']));
            if ($number !== '') {
                $groups[$number][] = $i;
            }
        }
        return array_filter($groups, fn ($g) => count($g) > 1);
    }

    private static function normText(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Keeps labels that reference a requested row exactly once and, for accounts, carry a known sub class.
     * Duplicated rows are dropped (conflicting answers), which triggers the retry.
     */
    private function validLabels(array $chunk, array $raw): array
    {
        $byRow = [];
        $count = [];
        foreach ($raw as $label) {
            $row = is_array($label) ? ($label['row'] ?? null) : null;
            if (! is_int($row) || ! isset($chunk[$row])) {
                continue;
            }
            $count[$row] = ($count[$row] ?? 0) + 1;
            $byRow[$row] = $label;
        }

        $valid = [];
        foreach ($byRow as $row => $label) {
            if ($count[$row] > 1 || ! in_array($label['row_type'] ?? null, ['account', 'parent', 'total', 'section', 'other'], true)) {
                continue;
            }
            if ($label['row_type'] === 'account' && ! $this->taxonomy->find((string) ($label['sub_class'] ?? ''))) {
                continue;
            }
            $valid[$row] = $label;
        }

        return $valid;
    }

    /**
     * Classification (from the AI) and data checks (from PHP) are kept apart:
     * the AI never influences data_flags, and the numbers never influence classification_confidence.
     */
    private function buildRow(array $account, ?array $ai, array $problemColumns, array $groups, ?string $note = null): array
    {
        $tax        = $ai ? $this->taxonomy->find($ai['sub_class']) : null;
        $scored     = $tax && is_numeric($ai['confidence'] ?? null);
        $confidence = $scored ? max(0, min(100, (int) $ai['confidence'])) : 0;   // the strict tool schema can't bound it

        $flags = [];
        if ($f = $this->reconciliationFlag($account, $groups)) {
            $flags[] = $f;
        }
        if ($tax) {
            array_push($flags, ...$this->abnormalBalanceFlags($account, $ai['sub_class'], $groups));
        }
        foreach ($problemColumns as $letter) {
            $flags[] = self::flag(['type' => 'invalid_number', 'difference' => null], AccountExtractor::invalidNumber($letter));
        }
        $reason = $tax ? $this->reasonPhrase($ai) : null;

        return [
            'account_number'            => $account['account_number'],
            'account_name'              => $account['account_name'],
            'financial_statement'       => $tax[0] ?? null,
            'main_class'                => $tax[1] ?? null,
            'sub_class'                 => $tax ? $ai['sub_class'] : null,
            'opening_debit'             => $account['opening_debit'],
            'opening_credit'            => $account['opening_credit'],
            'period_debit'              => $account['period_debit'],
            'period_credit'             => $account['period_credit'],
            'adjustment_debit'          => $account['adjustment_debit'],
            'adjustment_credit'         => $account['adjustment_credit'],
            'closing_debit'             => $account['closing_debit'],
            'closing_credit'            => $account['closing_credit'],
            'classification_confidence' => $confidence,
            'classification_reason'     => $reason?->ar(),
            // The same reason for any language: system wording (a cache source, the fallback) is translated, the AI's text is data.
            'classification_reason_phrase' => $reason?->toArray(),
            'data_flags'                => $flags,
            'source_note'               => $note,
            'confidence_missing'        => $tax && ! $scored,   // internal: the AI classified the row but gave no score
        ];
    }

    /**
     * Per pair: rows flipped by balance side, whether those flips are flagged, and rows whose side contradicts a class the
     * pair may not flip (kept, flagged).
     *
     * @return list<array{pair: string, label: string, rows: int, flagged: bool, contradictions: int}>
     */
    private static function sideFlips(array $flips): array
    {
        $out = [];
        foreach ($flips as $f) {
            $out[$f['pair']] ??= ['pair' => $f['pair'], 'label' => $f['label'], 'rows' => 0,
                'flagged' => false, 'contradictions' => 0];
            $f['to'] === null ? $out[$f['pair']]['contradictions']++ : $out[$f['pair']]['rows']++;
            $out[$f['pair']]['flagged'] = $out[$f['pair']]['flagged'] || ($f['flag'] && $f['to'] !== null);
        }

        return array_values($out);
    }

    /**
     * A row's review after the user chose its class: its flags as flagsForChosenClass() leaves them, then needs_review and
     * the note derived again from what is left, so no note outlives the doubt it was about (a still-valid flag, the
     * file's own note keep theirs).
     */
    public function reviewForChosenClass(array $row, array $groups): array
    {
        $row['data_flags'] = $this->flagsForChosenClass($row, $groups);
        // The user gave the class: no «no score» note (an internal field, left as the row had it).
        $reviewed = $this->finalizeRow(['confidence_missing' => false] + $row);

        return array_key_exists('confidence_missing', $row) ? ['confidence_missing' => false] + $reviewed : array_diff_key($reviewed, ['confidence_missing' => 1]);
    }

    /**
     * The note and needs_review a row's present fields give (the run's own rule), nothing changed: to find notes written
     * before the row's flags last changed.
     *
     * @return array{note: string, note_phrase: array, needs_review: bool}
     */
    public function reviewOf(array $row): array
    {
        $r = $this->finalizeRow($row + ['confidence_missing' => false]);

        return ['note' => $r['note'], 'note_phrase' => $r['note_phrase'], 'needs_review' => $r['needs_review']];
    }

    /**
     * The row's stored note without the warnings its present flags no longer give: a part worded as a flag's message
     * that no present flag words is dropped; every other part (the classification's reason, the file's note, a present
     * flag's warning, plain text) kept as stored. Null when nothing is dropped or the note has no stored phrase.
     *
     * @return array{note: string, note_phrase: array, dropped: list<string>}|null
     */
    public function withoutResolvedWarnings(array $row): ?array
    {
        $stored = $row['note_phrase'] ?? null;
        if (! is_array($stored) || $stored === []) {
            return null;
        }
        $parts = isset($stored['join']) ? $stored['join'] : [$stored];
        $warning = fn ($p) => is_array($p) && str_starts_with((string) ($p['key'] ?? ''), 'classification_messages.flags.');
        $present = array_map(fn ($f) => $f['message_phrase']['key'] ?? null, $row['data_flags'] ?? []);
        $resolved = fn ($p) => $warning($p) && ! in_array($p['key'], $present, true);
        $dropped = array_values(array_filter($parts, $resolved));
        if ($dropped === []) {
            return null;
        }
        $restore = fn ($p) => is_array($p) ? Phrase::fromArray($p) : Phrase::raw((string) $p);
        $kept = array_filter($parts, fn ($p) => ! $resolved($p));
        $note = Phrase::join(array_map($restore, array_values($kept)), (string) ($stored['sep'] ?? ' | '));
        $dropped = array_map(fn ($p) => $restore($p)->ar(), $dropped);

        return ['note' => $note->ar(), 'note_phrase' => $note->toArray(), 'dropped' => $dropped];
    }

    /** needs_review and the combined human-readable note are derived only here, from the separate fields. */
    private function finalizeRow(array $row): array
    {
        $classified = $row['sub_class'] !== null;
        $lowConf    = $row['classification_confidence'] < config('classification.review_threshold');

        // High-priority flags (statement_mismatch) lead the note, before the reason and the other flags.
        $high  = array_filter($row['data_flags'], fn ($f) => ($f['severity'] ?? null) === 'high');
        $flagPhrase = fn ($f) => is_array($f['message_phrase'] ?? null) ? Phrase::fromArray($f['message_phrase']) : Phrase::raw((string) $f['message']);
        $notes = array_values(array_map($flagPhrase, $high));
        if (! $classified) {
            $notes[] = self::m('note.unclassified');
        } elseif ($row['confidence_missing'] ?? false) {
            $notes[] = self::m('note.no_confidence');
        } elseif ($lowConf) {
            $notes[] = is_array($row['classification_reason_phrase'] ?? null)
                ? Phrase::fromArray($row['classification_reason_phrase']) : Phrase::raw((string) $row['classification_reason']);
        }
        foreach (array_diff_key($row['data_flags'], $high) as $flag) {
            $notes[] = $flagPhrase($flag);
        }
        if ($row['source_note'] !== null && $row['source_note'] !== '') {
            $notes[] = self::m('note.source_note', ['note' => $row['source_note']]);
        }
        $note = Phrase::join($notes, ' | ');

        // Flags with severity "info" are shown in the note but never set needs_review on their own.
        $reviewFlags         = array_filter($row['data_flags'], fn ($f) => ($f['severity'] ?? null) !== 'info');
        $row['needs_review'] = $lowConf || $reviewFlags !== [] || ($row['source_note'] ?? '') !== '';
        $row['note']         = $note->ar();
        $row['note_phrase']  = $note->toArray();

        return $row;
    }

    /**
     * The row's reason: the cache's own (worded by the cache), or the AI's, which must be Arabic (asked for Egyptian
     * Arabic; otherwise a fixed Egyptian fallback).
     */
    private function reasonPhrase(array $label): Phrase
    {
        $reason = trim((string) ($label['reason'] ?? ''));
        if (! preg_match('/\p{Arabic}/u', $reason)) {
            return Phrase::make(self::REASON_FALLBACK);
        }
        $phrase = $label['reason_phrase'] ?? null;

        return $phrase instanceof Phrase ? $phrase : (is_array($phrase) ? Phrase::fromArray($phrase) : Phrase::raw($reason));
    }

    private static function noRows(): LocalizedException
    {
        // The message runs and the sync endpoint always gave, kept as it is; the phrase words it in any language.
        return new LocalizedException(self::m('errors.no_rows'), 'No account rows found in the file.');
    }

    /**
     * closing should equal opening + movement (+ in-sheet adjustments when the file has them).
     * Needs opening, period and closing (pairs or net columns — both are debit/credit by now).
     */
    private function reconciliationFlag(array $a, array $groups): ?array
    {
        if (array_diff(['opening', 'period', 'closing'], $groups)) {
            return null;
        }

        $expected   = ($a['opening_debit'] - $a['opening_credit']) + ($a['period_debit'] - $a['period_credit'])
                    + ($a['adjustment_debit'] - $a['adjustment_credit']);   // 0 when there are no adjustment columns
        $actual     = $a['closing_debit'] - $a['closing_credit'];
        $difference = $actual - $expected;

        if (abs($difference) <= (float) config('classification.reconciliation_tolerance', 0.01)) {
            return null;
        }

        $amount = self::money(abs($difference));

        return self::flag(['type' => 'reconciliation', 'difference' => round($difference, 2)],
            self::m($difference > 0 ? 'flags.reconciliation_higher' : 'flags.reconciliation_lower', ['amount' => $amount]));
    }

    /** The doubts about a row's class that the user's own choice of class answers. */
    private const CLASS_DOUBTS = ['prefix_mismatch', 'statement_mismatch', 'classification_mismatch', 'existing_classification_conflict',
        'inconsistent_classification', 'side_contradiction', 'side_flip', 'rule_review', 'abnormal_opening', 'abnormal_closing', 'cash_credit'];

    /**
     * A row's flags once the user chose its class (a saved correction, or the class confirmed as it is): the doubts about
     * the class go, the user answered them; the balance is checked again against the class chosen, so a balance on that
     * class's wrong side stays flagged. Flags about the row itself (numbers, parents, use) stay as they are.
     */
    public function flagsForChosenClass(array $row, array $groups): array
    {
        $flags = array_values(array_filter($row['data_flags'] ?? [], fn ($f) => ! in_array($f['type'] ?? null, self::CLASS_DOUBTS, true)));
        [[$checked]] = $this->flagAccountMisuse([['data_flags' => $this->abnormalBalanceFlags($row, (string) $row['sub_class'], $groups)] + $row]);
        foreach ($checked['data_flags'] as $f) {
            if (in_array($f['type'], ['abnormal_opening', 'abnormal_closing', 'cash_credit'], true)) {
                $flags[] = $f;
            }
        }

        return $flags;
    }

    /** Opening and closing balances on the wrong side for the sub_class, above the materiality threshold. */
    private function abnormalBalanceFlags(array $a, string $subClass, array $groups): array
    {
        $normal = $this->taxonomy->normalSide($subClass);
        if (! $normal || in_array($subClass, config('classification.either_side_classes', []), true)) {
            return [];
        }

        $threshold = (float) config('classification.materiality_threshold', 1.00);
        $flags     = [];

        foreach (['opening', 'closing'] as $g) {
            if (! in_array($g, $groups, true)) {
                continue;
            }
            $net = $a["{$g}_debit"] - $a["{$g}_credit"];   // + debit, − credit

            $abnormal = ($normal === 'debit' && $net < -$threshold) || ($normal === 'credit' && $net > $threshold);
            if (! $abnormal) {
                continue;
            }

            $flags[]  = self::flag(['type' => "abnormal_{$g}", 'difference' => round($net, 2)], self::m('flags.abnormal', [
                'group'    => self::m("abnormal_groups.{$g}"),
                'side'     => self::m('sides.'.($net < 0 ? 'credit' : 'debit')),
                'amount'   => self::money(abs($net)),
                'expected' => self::m('natures.'.($normal === 'debit' ? 'debit' : 'credit')),
            ]));
        }

        return $flags;
    }

    /**
     * Account rows that came back without a classification: a partial result the user must see.
     *
     * @param  array<int, 'failed'|'missed'>  $rows
     * @return array{rows: int, ranges: list<string>, failed_batches: int, missed_by_ai: int}
     *         failed_batches / missed_by_ai count rows, by cause
     */
    private static function unclassified(array $rows): array
    {
        ksort($rows);
        $ranges = [];
        $start  = $prev = null;
        foreach (array_keys($rows) as $n) {
            if ($prev !== null && $n !== $prev + 1) {
                $ranges[] = $start === $prev ? "R{$start}" : "R{$start}–R{$prev}";
                $start    = $n;
            }
            $start ??= $n;
            $prev    = $n;
        }
        if ($start !== null) {
            $ranges[] = $start === $prev ? "R{$start}" : "R{$start}–R{$prev}";
        }
        $causes = array_count_values($rows);

        return ['rows' => count($rows), 'ranges' => $ranges, 'failed_batches' => $causes['failed'] ?? 0, 'missed_by_ai' => $causes['missed'] ?? 0];
    }

    private function hasBalances(array $account, array $groups): bool
    {
        foreach ($groups as $g) {
            if (abs($account["{$g}_debit"]) > 0.005 || abs($account["{$g}_credit"]) > 0.005) {
                return true;
            }
        }
        return false;
    }

    /**
     * One warning for every non-account row (other / section / unlabelled) that carries balances: the count, the
     * first 10 rows, and the closing balances left out of the totals.
     *
     * @param  array<int, array{0: float, 1: float}>  $dropped  row => closing [debit, credit]
     * @return list<string>
     */
    private function droppedWarning(array $dropped): array
    {
        if (! $dropped) {
            return [];
        }
        $rows = array_keys($dropped);

        return [self::m('warnings.dropped', ['count' => count($rows), 'rows' => FileRows::phrase($rows),
            'debit' => self::money(array_sum(array_column($dropped, 0))), 'credit' => self::money(array_sum(array_column($dropped, 1)))])];
    }

    private static function money(float $v): string
    {
        return number_format($v, 2);
    }

    /**
     * What ParentRows reads, for every row of the sheet in file order, and each row's level from the file's marker (none
     * when no parent under it adds up: ParentRows::trusted).
     *
     * @return array{0: array<int, array>, 1: array<int, ?int>}
     */
    private function parentInfo(UploadedFile $file, array $structure, array $dataRows, array $extracted, array $headerRows, array $accountRows, array $groups): array
    {
        $nameColumn = $structure['columns']['account_name'] ?? null;
        $hints = $nameColumn ? $this->reader->levelHints($file, $structure['sheet'], $nameColumn) : [];
        // A level column: its header says so («المستوى», "Level").
        $levelColumn = null;
        foreach ($headerRows as $cells) {
            foreach ($cells as $col => $v) {
                if (is_string($v) && preg_match('/^\s*(ال)?مستو[ىي]\s*$|^\s*(level|lvl)\s*$/iu', $v)) {
                    $levelColumn = $col;
                }
            }
        }

        $rows = [];
        ksort($dataRows);
        foreach (array_keys($dataRows) as $n) {
            $a = $extracted[$n]['account'];
            $level = $levelColumn !== null ? ($dataRows[$n][$levelColumn] ?? null) : null;
            $rows[$n] = [
                'account' => isset($accountRows[$n]),
                'name'    => $a['account_name'],
                'raw'     => $hints[$n]['raw'] ?? null,
                'indent'  => isset($hints[$n]) ? $hints[$n]['indent'] : null,
                'level'   => is_numeric($level) && (int) $level == $level && $level >= 0 && $level < 50 ? (int) $level : null,
                'amounts' => array_combine($groups, array_map(fn ($g) => round((float) $a["{$g}_debit"] - (float) $a["{$g}_credit"], 2), $groups)),
            ];
        }

        $tolerance = (float) config('classification.balance_tolerance', 0.01);

        return [$rows, ParentRows::trusted($rows, ParentRows::levels($rows)['levels'], $tolerance)];
    }

    /** By the file's levels: a parent, left out of the totals and the statements (ParentRows::byLevel). */
    private function flagLevelParents(array $results, array $rows, array $levels): array
    {
        $byLevel = ParentRows::byLevel($rows, $levels, (float) config('classification.balance_tolerance', 0.01));
        foreach ($results as $i => $r) {
            if (isset($byLevel[$r['row']])) {
                $results[$i]['data_flags'][] = self::flag(['type' => ParentRows::LEVEL, 'difference' => null, 'severity' => 'info', 'rows' => $byLevel[$r['row']]],
                    self::m('flags.parent_level', ['rows' => FileRows::phrase($byLevel[$r['row']], 5)]));
            }
        }

        return $results;
    }

    /**
     * By amounts alone (rows of no known level): a row whose balances are the sum of the rows right below it, and that
     * explains why the file doesn't add up (ParentRows::explaining) — counted twice, it unbalances the trial balance by
     * its own net. Flagged for the user to decide; still counted.
     */
    private function flagProbableParents(array $results, array $rows, array $levels, array $totalRows, array $groups): array
    {
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $excluded = array_fill_keys(array_column(array_filter($results, fn ($r) => ParentRows::excluded($r)), 'row'), true);
        $candidates = ParentRows::byAmounts($rows, $levels, $groups, $excluded, $tolerance);
        if (! $candidates) {
            return $results;
        }
        $counted = array_values(array_filter($results, fn ($r) => ! isset($excluded[$r['row']])));
        $sums = TrialBalanceTotals::sums($counted, $groups);
        $byRow = array_column($results, null, 'row');
        $explaining = ParentRows::explaining(array_intersect_key($byRow, $candidates), $sums, array_values($totalRows), $groups, $tolerance);

        foreach ($results as $i => $r) {
            if (in_array($r['row'], $explaining, true)) {
                $results[$i]['data_flags'][] = self::flag(['type' => ParentRows::AMOUNTS, 'difference' => null, 'rows' => $candidates[$r['row']]],
                    self::m('flags.probable_parent', ['rows' => FileRows::phrase($candidates[$r['row']], 5)]));
            }
        }

        return $results;
    }

    /**
     * An "account" whose number prefixes other accounts' numbers and whose closing net equals
     * the sum of those (leaf) accounts is likely a parent row the AI missed — it would double count.
     */
    private function flagParentAccounts(array $rows): array
    {
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $numbers   = array_map(fn ($r) => AmountParser::asciiDigits($r['account_number']), $rows);
        $net       = array_map(fn ($r) => $r['closing_debit'] - $r['closing_credit'], $rows);

        $hasDescendants = fn (string $n) => $n !== '' && array_filter(
            $numbers, fn ($other) => $other !== $n && str_starts_with($other, $n)
        );

        foreach ($rows as $i => $row) {
            $number = $numbers[$i];
            if ($number === '' || abs($net[$i]) <= $tolerance || ! $hasDescendants($number) || ParentRows::excluded($row)) {
                continue;
            }

            $sum = 0.0;
            foreach ($numbers as $j => $other) {
                if ($j !== $i && str_starts_with($other, $number) && $other !== $number && ! $hasDescendants($other)) {
                    $sum += $net[$j];
                }
            }

            if (abs($sum - $net[$i]) <= $tolerance) {
                $rows[$i]['data_flags'][] = self::flag(['type' => 'possible_parent', 'difference' => null], self::m('flags.possible_parent'));
            }
        }

        return $rows;
    }

    /**
     * The accountant's rules (ClassificationRules, docs/17-classification-rules.md) on balance-sheet accounts: a matched
     * rule's class for the side of the balance replaces the AI's or the cache's (a manual correction stays), with the side
     * checks of its class (a side pair's flip the rule agrees with keeps its flag); a side the rule flags gets rule_review. A rule with no class
     * for that side leaves the class as it is. No balance at all: the side of the class it has.
     */
    private function applyRules(array $rows, ClassificationRules $rules, array $groups, ?\Closure $fileMains = null): array
    {
        foreach ($rows as &$row) {
            if ($row['classification_source'] === ClassificationCache::MANUAL || ! ($m = $rules->match($row))) {
                continue;
            }
            $outcome = ClassificationRules::outcome($m['rule'], $row);
            if ($outcome['side'] === null && ($normal = $this->fileSide($m['rule'], $fileMains ? $fileMains($row) : null)
                ?? $this->kindSide($m['rule']) ?? $this->taxonomy->normalSide((string) $row['sub_class']))) {
                // No balance in any column (the movement nets to zero): the side the file's own column points to («مستحقة»), else
                // the rule's kind's (its classes' pair's base: an employee's account debit, a supplier credit), else the class
                // it has; never a review.
                $outcome = ['side' => $normal, 'class' => $normal === 'debit' ? $m['rule']->debit_class : $m['rule']->credit_class, 'review' => false];
            }
            $side    = $outcome['side'] === null ? null : self::m('sides.'.$outcome['side']);
            $row['classification_rule'] = $m['rule']->name;
            if ($outcome['class'] !== null && ($tax = $this->taxonomy->find($outcome['class']))) {
                $reason = self::m("rule_reasons.{$m['by']}", ['rule' => $m['rule']->name, 'side' => $side]);
                $row = array_merge($row, [
                    'financial_statement'          => $tax[0],
                    'main_class'                   => $tax[1],
                    'sub_class'                    => $outcome['class'],
                    'classification_source'        => 'rule',
                    'classification_confidence'    => 100,
                    'confidence_missing'           => false,
                    'classification_reason'        => $reason->ar(),
                    'classification_reason_phrase' => $reason->toArray(),
                    // The rule decided by the balance, whatever the AI had: the pair's flip of the AI's answer goes.
                    'data_flags'                   => array_values(array_filter($row['data_flags'], fn ($f) => ! in_array($f['type'],
                        ['side_contradiction', 'side_flip', 'abnormal_opening', 'abnormal_closing'], true))),
                ]);
                array_push($row['data_flags'], ...$this->abnormalBalanceFlags($row, $outcome['class'], $groups));
                // The rule's kind on the wrong side of a flagged pair (a supplier in debit): a review, as the pair asks.
                $pair = ClassificationCache::pairOf($outcome['class']);
                if ($pair && $outcome['side'] !== null && $pair[2] === $outcome['side'] && ClassificationCache::reversed($pair[1], $outcome['side'])) {
                    $row['data_flags'][] = self::flag(['type' => 'side_flip', 'difference' => null], self::m('flags.side_flip_review',
                        ['from' => $pair[1][$pair[1]['base'] ?? 'debit'][0], 'to' => $outcome['class']]));
                }
            }
            if ($outcome['review']) {
                $row['data_flags'][] = self::flag(['type' => 'rule_review', 'difference' => null],
                    self::m('flags.rule_review', ['rule' => $m['rule']->name, 'side' => $side]));
            }
        }
        unset($row);

        return $rows;
    }

    /** The rule's side whose class sits under a main class the file's own column names, when exactly one does. */
    private function fileSide(\App\Models\ClassificationRule $rule, ?array $mains): ?string
    {
        $sides = array_keys(array_filter(['debit' => $rule->debit_class, 'credit' => $rule->credit_class],
            fn ($c) => $c !== null && in_array($this->taxonomy->find($c)[1] ?? null, $mains ?? [], true)));

        return count($sides) === 1 ? $sides[0] : null;
    }

    /** The main classes the file's own classification column maps the row to (flagExistingClassification's), or null. */
    private function existingMains(array $row, array $existingValues, array $existingMap): ?array
    {
        $value = self::normText($row['existing_classification'] ?? $row['existing_note_classification'] ?? '');

        return $value !== '' ? ($existingMap[$existingValues[$value]['id'] ?? 0] ?? null) : null;
    }

    /** The side a rule's kind of account normally sits on: the base side of the side pair holding its classes, if any. */
    private function kindSide(\App\Models\ClassificationRule $rule): ?string
    {
        foreach ([$rule->debit_class, $rule->credit_class] as $class) {
            if ($class !== null && ($pair = ClassificationCache::pairOf($class))) {
                return $pair[1]['base'] ?? 'debit';
            }
        }

        return null;
    }

    /**
     * What each rule classified, by class and side («قاعدة «موظف» صنّفت 25 حساب: 9 «عُهد وسُلف العاملين» (مدين)، …»): the
     * trace of the rules in the file's info, as the side pairs have theirs.
     *
     * @return list<Phrase>
     */
    private static function ruleSummary(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            if (($r['classification_source'] ?? null) === 'rule') {
                $side = ClassificationCache::rowSide($r) ?? 'none';
                $by[$r['classification_rule']][$r['sub_class'].'|'.$side] = ($by[$r['classification_rule']][$r['sub_class'].'|'.$side] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($by as $rule => $classes) {
            $parts = [];
            foreach ($classes as $key => $n) {
                [$class, $side] = explode('|', $key);
                $parts[] = self::m($side === 'none' ? 'info.rule_part_zero' : 'info.rule_part', ['count' => $n, 'class' => $class,
                    'side' => $side === 'none' ? '' : self::m("sides.{$side}")]);
            }
            $out[] = self::m('info.rule_summary', ['rule' => $rule, 'count' => array_sum($classes), 'parts' => Phrase::join($parts, ' · ')]);
        }

        return $out;
    }

    /** Whether the row's class was chosen by its balance side on purpose: by a rule, or flipped within a side pair. */
    private static function movedBySide(array $row): bool
    {
        return ($row['classification_source'] ?? null) === 'rule' || in_array('side_flip', array_column($row['data_flags'], 'type'), true);
    }

    /** The refusal of an unbalanced trial balance: each group off and by how much. */
    private function unbalanced(array $reject): LocalizedException
    {
        return new LocalizedException(self::m('errors.unbalanced', [
            'groups' => array_map(fn ($g) => self::m('errors.unbalanced_group', ['group' => self::m("groups.{$g}"), 'diff' => self::money($reject[$g])]), array_keys($reject)),
            'limit'  => self::money((float) config('classification.reject_unbalanced_from', 1.00)),
        ]));
    }

    /**
     * Before the AI: which rows are accounts isn't known yet (the AI says which are parents), so the file is refused only
     * when it is off by the refusal limit however they are read: the numbered rows no other number starts with (the
     * leaves of a numbered chart), the same with the rows that have no number («سالم الحربي»), and every row; and a reading
     * off by exactly one of its rows' balances (a parent counted with its accounts) is not clearly off either. Formula
     * totals are left out. Anything else goes on to the AI and the full check after it.
     *
     * @return array<string, float> group → difference of the leaves (empty: not clearly unbalanced)
     */
    private function clearlyUnbalanced(array $extracted, array $subtotalRows, array $groups): array
    {
        $limit = (float) config('classification.reject_unbalanced_from', 1.00);
        $tb    = array_values(array_intersect(['opening', 'period', 'closing'], $groups));
        $rows  = array_diff_key(array_map(fn ($e) => $e['account'], $extracted), $subtotalRows);
        if (! $tb || ! $rows) {
            return [];
        }
        $codes  = array_filter(array_map(fn ($a) => ClassificationKey::code((string) $a['account_number']), $rows));
        $sorted = array_values(array_unique($codes));
        sort($sorted, SORT_STRING);
        $parent = [];
        foreach ($sorted as $i => $c) {
            // Sorted, a code's longer extensions follow it: the next distinct code starting with it makes it a parent.
            $parent[$c] = isset($sorted[$i + 1]) && str_starts_with($sorted[$i + 1], $c);
        }
        $leaves = array_keys(array_filter($codes, fn ($c) => ! $parent[$c]));
        $plain  = array_keys(array_diff_key($rows, $codes));
        $net    = fn (int $n, string $g) => (float) ($rows[$n]["{$g}_debit"] ?? 0) - (float) ($rows[$n]["{$g}_credit"] ?? 0);
        $signed = fn (array $set) => array_combine($tb, array_map(fn ($g) => array_sum(array_map(fn ($n) => $net($n, $g), $set)), $tb));
        $off    = fn (array $set) => array_map(fn ($d) => round(abs($d), 2), $signed($set));
        foreach ([[...$leaves, ...$plain], array_keys($rows)] as $reading) {
            $diff = $signed($reading);
            if (max(array_map('abs', $diff)) < $limit) {
                return [];
            }
            // Off by exactly one row's balances: a parent counted with its accounts (ParentRows::AMOUNTS), excluded later.
            foreach ($reading as $n) {
                if (max(array_map(fn ($g) => abs($diff[$g] - $net($n, $g)), $tb)) < $limit) {
                    return [];
                }
            }
        }
        $leafOff = $off($leaves ?: array_keys($rows));
        if (max($leafOff) < $limit) {
            return [];
        }

        return array_filter($leafOff, fn ($d) => $d >= $limit);
    }

    /**
     * Rows the AI labelled parent or section that the trial balance needs: when the accounts alone are off by the refusal
     * limit or more and adding these rows (all of them, or one) settles every group, they are accounts (a closed year's
     * result, «ارباح وخسائر 2024», once came back a parent and the file was refused). Their labels are dropped: they stay
     * for a manual class and nothing about them is stored. Real parents never qualify: added, they count their accounts twice.
     *
     * @return array{0: array<int, array>, 1: array<int, true>} labels, the rows rescued
     */
    private function rescueParents(array $labels, array $extracted, array $dataRows, array $subtotalRows, array $zero, array $groups): array
    {
        $limit = (float) config('classification.reject_unbalanced_from', 1.00);
        $tb    = array_values(array_intersect(['opening', 'period', 'closing'], $groups));
        $sum   = function (array $rows) use ($tb, $extracted) {
            $out = [];
            foreach ($tb as $g) {
                $out[$g] = array_sum(array_map(fn ($n) => (float) ($extracted[$n]["account"]["{$g}_debit"] ?? 0) - (float) ($extracted[$n]["account"]["{$g}_credit"] ?? 0), $rows));
            }

            return $out;
        };
        $accounts = $candidates = [];
        foreach (array_keys($dataRows) as $n) {
            if (isset($subtotalRows[$n]) || isset($zero[$n]) || ! isset($extracted[$n])) {
                continue;
            }
            $type = $labels[$n]['row_type'] ?? null;
            if ($type === 'account' || ($type === null && ($extracted[$n]['account']['account_name'] !== '' || $extracted[$n]['account']['account_number'] !== ''))) {
                $accounts[] = $n;
            } elseif (in_array($type, ['parent', 'section'], true) && trim((string) $extracted[$n]['account']['account_number']) !== '') {
                $candidates[] = $n;
            }
        }
        $off = $sum($accounts);
        if (! $candidates || ! $tb || max(array_map('abs', $off)) < $limit) {
            return [$labels, []];
        }
        $settles = fn (array $rows) => max(array_map(fn ($g) => abs($off[$g] + $sum($rows)[$g]), $tb)) < $limit;
        $found = $settles($candidates) ? $candidates : (array_values(array_filter($candidates, fn ($n) => $settles([$n])))[0] ?? null);
        if ($found === null) {
            return [$labels, []];
        }
        $found = (array) $found;
        foreach ($found as $n) {
            unset($labels[$n]);
        }

        return [$labels, array_fill_keys($found, true)];
    }

    /**
     * An account with no number, in a file whose other accounts have one: no code group can catch it, so it's flagged.
     *
     * @return array{0: list<array>, 1: list<int>} rows, the rows with no number
     */
    private function flagMissingNumbers(array $rows): array
    {
        $missing = [];
        if (! array_filter($rows, fn ($r) => trim((string) $r['account_number']) !== '')) {
            return [$rows, $missing];
        }
        foreach ($rows as &$row) {
            // A saved correction finds it by name: nothing left to catch.
            if (trim((string) $row['account_number']) === '' && $row['classification_source'] !== ClassificationCache::MANUAL) {
                $row['data_flags'][] = self::flag(['type' => 'missing_account_number', 'difference' => null], self::m('flags.missing_account_number'));
                $missing[] = $row['row'];
            }
        }
        unset($row);

        return [$rows, $missing];
    }

    /**
     * Balances that point to a booking mistake, after the adjustments: cash (classification.cash_classes) with a credit
     * closing balance (its abnormal_closing flag replaced by a plainer one), and an account marked not to be used
     * (classification.unused_words in its name) with movement. Each gets a flag, and the file a warning listing them.
     *
     * @return array{0: list<array>, 1: list<Phrase>} rows, warnings
     */
    private function flagAccountMisuse(array $rows): array
    {
        $threshold = (float) config('classification.materiality_threshold', 1.00);
        $found     = ['cash_credit' => [], 'unused_movement' => []];
        foreach ($rows as &$row) {
            $kind = collect(config('classification.cash_classes', []))->search(fn ($classes) => in_array($row['sub_class'], $classes, true));
            $net  = $row['closing_debit'] - $row['closing_credit'];
            if ($kind !== false && $net < -$threshold) {
                $row['data_flags'] = array_values(array_filter($row['data_flags'], fn ($f) => $f['type'] !== 'abnormal_closing'));
                $row['data_flags'][] = self::flag(['type' => 'cash_credit', 'difference' => round($net, 2)],
                    self::m("flags.cash_credit_{$kind}", ['amount' => self::money(-$net)]));
                $found['cash_credit'][] = $row;
            }
            $movement = abs($row['period_debit']) + abs($row['period_credit']) + abs($row['adjustment_debit']) + abs($row['adjustment_credit']);
            if ($movement >= 0.005 && ClassificationCache::nameHas((string) $row['account_name'], config('classification.unused_words', []))) {
                $row['data_flags'][] = self::flag(['type' => 'unused_movement', 'difference' => null], self::m('flags.unused_movement'));
                $found['unused_movement'][] = $row;
            }
        }
        unset($row);

        $warnings = [];
        foreach (array_filter($found) as $type => $list) {
            $warnings[] = self::m("warnings.{$type}", ['count' => count($list), 'accounts' => self::shortList(
                array_map(fn ($r) => self::m('quoted_at', ['value' => $r['account_name'], 'rows' => FileRows::phrase([$r['row']])]), array_slice($list, 0, 10)),
                count($list) - 10)]);
        }

        return [$rows, $warnings];
    }

    /**
     * The file's numbering scheme (no hard-coded rule), checked twice:
     * - per statement (flagStatementPrefixes): an account on the wrong side of the file's balance-sheet / income-statement
     *   split gets a high prefix_mismatch;
     * - per first digit of numeric account numbers: when enough OTHER classified accounts start with the digit
     *   (prefix_check.min_accounts) and most of them (min_share) share one family (cost counts as expenses), an account
     *   under that digit classified into another family gets prefix_mismatch.
     * Leave-one-out in both: an account is never evidence for its own classification.
     */
    private function flagNumberPrefixes(array $rows): array
    {
        $cfg  = config('classification.prefix_check');
        $rows = $this->flagStatementPrefixes($rows, $cfg);

        $digits  = [];   // row index => [first digit, major family]
        $byDigit = [];   // digit => family => count
        foreach ($rows as $i => $r) {
            $number = AmountParser::asciiDigits((string) $r['account_number']);
            $family = $r['main_class'] ? $this->taxonomy->familyOf($r['main_class']) : null;
            if (! $family || ! preg_match('/^\d+$/', $number) || strlen($number) < (int) $cfg['min_length']) {
                continue;
            }
            // A contra account (accumulated depreciation, a provision against receivables) is numbered with the assets in
            // some charts and with the credits in others: neither evidence nor doubt.
            $normal = $r['sub_class'] ? $this->taxonomy->normalSide($r['sub_class']) : null;
            if ($normal !== null && $normal !== (in_array(self::MAJOR_GROUPS[$family], ['أصول', 'مصروفات'], true) ? 'debit' : 'credit')) {
                continue;
            }
            // Liabilities and equity share their numbers in many charts (2 = both): one group here.
            $group = in_array(self::MAJOR_GROUPS[$family], ['التزامات', 'حقوق الملكية'], true) ? self::CREDITS : self::MAJOR_GROUPS[$family];
            $digits[$i] = [$number[0], $group];
            if (self::movedBySide($r)) {
                continue;   // its family is its balance's, not its number's: no evidence about the numbering
            }
            $byDigit[$number[0]][$group] = ($byDigit[$number[0]][$group] ?? 0) + 1;
        }

        foreach ($digits as $i => [$digit, $family]) {
            if (in_array('prefix_mismatch', array_column($rows[$i]['data_flags'], 'type'), true)) {
                continue;   // already flagged by the statement scheme, the stronger signal
            }
            if (self::movedBySide($rows[$i])) {
                continue;   // moved to the other side on purpose (a rule, a side pair): not a numbering doubt
            }
            // Leave-one-out: the account is never evidence for its own family.
            $families = $byDigit[$digit];
            $families[$family]--;
            arsort($families);
            $total    = array_sum($families);
            $count    = reset($families);
            $expected = key($families);
            if ($total >= (int) $cfg['min_accounts'] && $count / $total >= (float) $cfg['min_share'] && $expected !== $family) {
                $rows[$i]['data_flags'][] = self::flag(['type' => 'prefix_mismatch', 'difference' => null], self::m('flags.prefix_family', [
                    'digit' => $digit, 'count' => $count, 'total' => $total, 'expected' => self::family($expected), 'family' => self::family($family)]));
            }
        }

        return $rows;
    }

    /**
     * The file's statement scheme: one split point over the first digits that puts the balance sheet on one side and the
     * income statement on the other (e.g. 1–3 / 4–9). Inferred without the tested account (leave-one-out), so a digit
     * holding only misclassified accounts can't confirm itself; an account on the wrong side moves profit → severity high.
     */
    private function flagStatementPrefixes(array $rows, array $cfg): array
    {
        $statements = [];   // row index => [digit, side] (side 0 = balance sheet, 1 = income statement)
        $counts     = array_fill(0, 10, [0, 0]);
        foreach ($rows as $i => $r) {
            $number = AmountParser::asciiDigits((string) $r['account_number']);
            if ($r['financial_statement'] === null || ! preg_match('/^\d/', $number) || strlen($number) < (int) $cfg['min_length']) {
                continue;
            }
            $side = $r['financial_statement'] === self::INCOME_STATEMENT ? 1 : 0;
            $statements[$i] = [(int) $number[0], $side];
            $counts[(int) $number[0]][$side]++;
        }

        $names    = [0 => 'قائمة المركز المالي', 1 => self::INCOME_STATEMENT];
        $expected = [];   // "digit|side" => [expected side, low side, low digits, high digits] | null
        foreach ($statements as $i => [$digit, $side]) {
            $key = "{$digit}|{$side}";
            if (! array_key_exists($key, $expected)) {
                $without = $counts;
                $without[$digit][$side]--;
                $splits = $this->statementSplits($without, $cfg);
                // Flag only when every equally good split puts the digit on the other side.
                $sides = array_unique(array_map(fn ($s) => $digit <= $s[0] ? $s[1] : 1 - $s[1], $splits));
                $expected[$key] = $splits && count($sides) === 1 && reset($sides) !== $side
                    ? [reset($sides), $splits[0][1], ...self::splitDigits($without, $splits[0])] : null;
            }
            if (! $expected[$key]) {
                continue;
            }
            [$want, $low, $lowDigits, $highDigits] = $expected[$key];
            $rows[$i]['data_flags'][] = self::flag(['type' => 'prefix_mismatch', 'severity' => 'high', 'difference' => null], self::m('flags.prefix_statement', [
                'digit' => $digit, 'low_digits' => $lowDigits, 'low_statement' => $names[$low], 'high_digits' => $highDigits,
                'high_statement' => $names[1 - $low], 'expected' => $names[$want], 'statement' => $names[$side], 'sub_class' => $rows[$i]['sub_class']]));
        }

        return $rows;
    }

    /**
     * The digits the file uses on each side of a split, as "1 إلى 3" (the range of digits that hold an account of that side).
     *
     * @return array{0: string, 1: string}
     */
    private static function splitDigits(array $counts, array $split): array
    {
        [$k, $low] = $split;
        $range = function (array $digits) {
            return min($digits) === max($digits) ? (string) min($digits) : self::m('digit_range', ['from' => min($digits), 'to' => max($digits)]);
        };

        return [
            $range(array_filter(range(0, $k), fn ($d) => $counts[$d][$low] > 0)),
            $range(array_filter(range($k + 1, 9), fn ($d) => $counts[$d][1 - $low] > 0)),
        ];
    }

    /**
     * Best split points: [k, low] = digits 0..k hold statement side `low`, digits k+1..9 the other. Only splits with at least
     * min_accounts agreeing accounts on each side and statement_min_share agreement overall; all ties are returned.
     *
     * @param  array<int, array{0: int, 1: int}>  $counts  digit => [balance sheet, income statement]
     * @return list<array{0: int, 1: int}>
     */
    private function statementSplits(array $counts, array $cfg): array
    {
        $total = array_sum(array_map('array_sum', $counts));
        if ($total === 0) {
            return [];
        }

        $best = [];
        $max  = -1;
        foreach ([0, 1] as $low) {
            for ($k = 0; $k < 9; $k++) {
                $lowSide  = array_sum(array_map(fn ($d) => $counts[$d][$low], range(0, $k)));
                $highSide = array_sum(array_map(fn ($d) => $counts[$d][1 - $low], range($k + 1, 9)));
                if ($lowSide < (int) $cfg['min_accounts'] || $highSide < (int) $cfg['min_accounts']
                    || ($lowSide + $highSide) / $total < (float) ($cfg['statement_min_share'] ?? 0.95)) {
                    continue;
                }
                if ($lowSide + $highSide > $max) {
                    [$best, $max] = [[], $lowSide + $highSide];
                }
                if ($lowSide + $highSide === $max) {
                    $best[] = [$k, $low];
                }
            }
        }

        return $best;
    }

    /**
     * Caps the confidence of rows whose flags say the answer is probably wrong (config confidence_caps): a wrong-side
     * balance, an account number from another family. A person's label (manual correction, imported) keeps its confidence;
     * the flag still asks for a review.
     *
     * @param  array<int, string>  $sources  row => cache source, for the rows the cache answered
     */
    private function capConfidence(array $rows, array $sources): array
    {
        $caps = config('classification.confidence_caps');
        foreach ($rows as &$row) {
            if (in_array($sources[$row['row']] ?? null, [ClassificationCache::MANUAL, ClassificationCache::IMPORTED], true)) {
                continue;
            }
            foreach (array_column($row['data_flags'], 'type') as $type) {
                $cap = match ($type) {
                    'abnormal_opening', 'abnormal_closing', 'cash_credit' => $caps['abnormal_balance'] ?? null,
                    'prefix_mismatch' => $caps['prefix_mismatch'] ?? null,
                    default => null,
                };
                $cap !== null && $row['classification_confidence'] = min($row['classification_confidence'], (int) $cap);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * File-level checks. Debit ≠ credit is only an error when the file covers every major group;
     * a partial trial balance (e.g. assets only) is expected to be unbalanced → info, not warning.
     * A trial balance whose opening, movement or closing is off by `reject_unbalanced_from` or more is refused (`reject`),
     * full or partial, unless the probable parents (ParentRows::AMOUNTS: the user excludes them) explain it.
     *
     * @return array{warnings: list<string>, info: list<string>, reject: array<string, float>} reject: group → difference
     */
    private function fileChecks(array $rows, array $totalRows, array $groups): array
    {
        $tolerance   = (float) config('classification.balance_tolerance', 0.01);
        $materiality = (float) config('classification.materiality_threshold', 1.00);
        $warnings    = [];
        $info        = [];
        $unbalanced  = [];   // differences above the materiality threshold

        // Totals count account rows only, and leave out suspected parents (TrialBalanceTotals).
        $sums = TrialBalanceTotals::sums($rows, $groups);

        foreach ($groups as $g) {
            $diff = abs($sums[$g][0] - $sums[$g][1]);
            if ($diff <= $tolerance) {
                continue;
            }
            if ($diff <= $materiality) {
                $info[] = self::m('info.rounding', ['diff' => self::money($diff), 'group' => self::m("groups.{$g}")]);   // rounding, not an error
            } else {
                $unbalanced[$g] = $diff;
            }
        }

        $present = [];
        foreach ($rows as $row) {
            if ($row['main_class'] && ($family = $this->taxonomy->familyOf($row['main_class']))) {
                $present[self::MAJOR_GROUPS[$family]] = true;
            }
        }
        $ordered = array_values(array_filter(array_unique(array_values(self::MAJOR_GROUPS)), fn ($m) => isset($present[$m])));
        $isFull  = count($ordered) === count(array_unique(array_values(self::MAJOR_GROUPS)));

        if ($isFull) {
            // Full trial balance: opening, period, adjustments and closing must each balance.
            foreach ($unbalanced as $g => $diff) {
                $warnings[] = self::m('warnings.unbalanced', ['group' => self::m("groups.{$g}"), 'diff' => self::money($diff)]);
            }
        } else {
            // Adjusting entries balance by themselves, so this stays a warning even on a partial file.
            if (isset($unbalanced['adjustment'])) {
                $warnings[] = self::m('warnings.adjustments_unbalanced', ['diff' => self::money($unbalanced['adjustment'])]);
            }
            if (array_diff_key($unbalanced, ['adjustment' => true])) {
                $info[] = self::m('info.partial', ['groups' => $ordered ? array_map(fn ($f) => self::family($f), $ordered) : self::m('info.no_classified')]);
            }
        }

        $reject  = [];
        $limit   = (float) config('classification.reject_unbalanced_from', 1.00);
        $settled = TrialBalanceTotals::sums(array_filter($rows, fn ($r) => ! in_array(ParentRows::AMOUNTS, array_column($r['data_flags'], 'type'), true)), $groups);
        foreach (array_intersect(['opening', 'period', 'closing'], $groups) as $g) {
            $diff = round(abs($settled[$g][0] - $settled[$g][1]), 2);
            if ($diff >= $limit) {
                $reject[$g] = $diff;
            }
        }

        if ($totalRows && $groups) {
            $matches = array_filter($totalRows, function ($total) use ($groups, $sums, $tolerance) {
                foreach ($groups as $g) {
                    if (abs($total["{$g}_debit"] - $sums[$g][0]) > $tolerance
                        || abs($total["{$g}_credit"] - $sums[$g][1]) > $tolerance) {
                        return false;
                    }
                }
                return true;
            });

            if (! $matches) {
                $warning = self::m('warnings.total_mismatch', ['rows' => FileRows::phrase(array_keys($totalRows))]);
                // The difference is one row's balance: name it (a parent counted twice, most often).
                $counted = array_filter($rows, fn ($r) => ! ParentRows::excluded($r));
                foreach ($totalRows as $total) {
                    $culprits = array_filter($counted, fn ($r) => array_filter($groups, fn ($g) => abs($sums[$g][0] - $total["{$g}_debit"] - $r["{$g}_debit"]) > $tolerance
                        || abs($sums[$g][1] - $total["{$g}_credit"] - $r["{$g}_credit"]) > $tolerance) === []
                        && array_filter($groups, fn ($g) => abs($r["{$g}_debit"]) > $tolerance || abs($r["{$g}_credit"]) > $tolerance) !== []);
                    // A parent and its only child have the same balance: the one flagged a probable parent is named.
                    $flagged = array_filter($culprits, fn ($r) => in_array(ParentRows::AMOUNTS, array_column($r['data_flags'], 'type'), true));
                    $culprits = count($culprits) > 1 && count($flagged) === 1 ? $flagged : $culprits;
                    if (count($culprits) === 1) {
                        $r = reset($culprits);
                        $warning = self::m('warnings.total_mismatch_culprit', ['mismatch' => $warning, 'account' => AccountNames::one($r), 'row' => FileRows::phrase([$r['row']])]);
                        break;
                    }
                }
                $warnings[] = $warning;
            } else {
                $info[] = self::m('info.total_rows', ['rows' => FileRows::phrase(array_keys($totalRows))]);
            }
        }

        return ['warnings' => $warnings, 'info' => $info, 'reject' => $reject];
    }
}
