<?php

namespace App\Repositories\Eloquents;

use App\Repositories\Contracts\AccountClassifierRepositoryInterface;
use App\Services\AccountExtractor;
use App\Services\SpreadsheetReader;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;
use App\Support\Llm\UsageMeter;
use Closure;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AccountClassifierRepository implements AccountClassifierRepositoryInterface
{
    public const ROW_TYPES = ['account', 'parent', 'total', 'section', 'other'];

    private const FIELD_LABELS = [
        'account_number' => 'account number / code',
        'account_name'   => 'account name / description',
        'opening_debit'  => 'opening balance, debit',
        'opening_credit' => 'opening balance, credit',
        'opening_net'    => 'opening balance, single signed/net column',
        'opening_side'   => 'opening balance, debit/credit indicator (e.g. مدين/دائن, Dr/Cr) for opening_net',
        'period_debit'   => 'period movement, debit',
        'period_credit'  => 'period movement, credit',
        'period_net'     => 'period movement, single signed/net column',
        'period_side'    => 'period movement, debit/credit indicator for period_net',
        'adjustment_debit'  => 'adjustments (تسويات) in the sheet, debit',
        'adjustment_credit' => 'adjustments (تسويات) in the sheet, credit',
        'adjustment_net'    => 'adjustments in the sheet, single signed/net column',
        'adjustment_side'   => 'adjustments, debit/credit indicator for adjustment_net',
        'closing_debit'  => 'closing balance, debit',
        'closing_credit' => 'closing balance, credit',
        'closing_net'    => 'closing balance, single signed/net column',
        'closing_side'   => 'closing balance, debit/credit indicator for closing_net',
        'notes'          => 'remarks describing a problem or issue with the row (may have no header)',
        'audit_request'  => 'auditor requests / PBC items / instructions about the row (not problems)',
        'existing_classification'      => "the file's own classification of the account (e.g. بنود الميزانية, statement line)",
        'existing_note_classification' => "the file's own note-level classification (e.g. إيضاحات الميزانية)",
        'note_reference' => 'note number referring to the notes of the financial statements (e.g. a «إيضاح» / «رقم الإيضاح» / "Note" column holding 5, 12, 15/أ)',
    ];

    public function __construct(private TaxonomyRepository $taxonomy, private ClassifierDriver $driver, private UsageMeter $meter) {}

    public function detectStructure(array $preview, array $previousErrors = [], array $sheetNames = [], array $letters = []): array
    {
        $text = '';
        foreach ($preview as $sheetNo => $rows) {
            $text .= "### Sheet {$sheetNo}".(isset($sheetNames[$sheetNo]) ? ": {$sheetNames[$sheetNo]}" : '')."\n";
            foreach ($rows as $n => $cells) {
                $letters = array_merge($letters, array_keys($cells));
                $text .= SpreadsheetReader::formatRow($n, $cells)."\n";
            }
        }
        $letters = array_values(array_unique($letters));
        sort($letters);
        $letterEnum = ['type' => 'string', 'enum' => $letters];

        $columnProps = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            $columnProps[$field] = ['type' => 'string', 'enum' => $letters, 'description' => $label];
        }

        $system = 'You analyse spreadsheet exports of trial balances (ميزان المراجعة) in any language and any layout. '
            .'Identify which sheet holds the trial balance, its header row(s), the first data row, and which column letter holds each field. '
            .'Rules: omit fields the file does not have. '
            .'For each of opening/period/closing use either a debit+credit column pair, or a single net column (positive = debit, negative = credit), optionally with a side column that says debit or credit. '
            .'If the account number and name are in the same cell, give the same letter for account_number and account_name. '
            .'Headers may span several rows (e.g. a group title like "الرصيد الافتتاحي" above "مدين"/"دائن"); list all of them in header_rows. '
            .'If the sheet has adjustment columns (e.g. "تسوية مدين"/"تسوية دائن") between the movement and the closing balance, map them to the adjustment group. '
            .'If a column contains free-text remarks about rows, return it as notes even if it has no header. '
            .'notes is only for remarks that describe a problem or issue with the row (e.g. "غير مطابق مع التحليلي", "الرصيد غلط"). '
            .'If the file already classifies each account (e.g. a "بنود الميزانية" / statement-line column), map it to existing_classification; a finer note-level classification column (e.g. "إيضاحات") is existing_note_classification. '
            .'A column of note numbers (e.g. "إيضاح" / "رقم الإيضاح" / "Note" holding 5, 12, 15/أ) is note_reference, never notes. '
            .'A column of requests or instructions — auditor requests, PBC items, documents to provide, samples to pull (e.g. "الطلبات", "مطلوب كشف حساب", "عينة من اضافات الاصول الثابتة") — is audit_request, never notes. '
            .'List every other sheet in other_sheets with a type: adjustments (adjusting entries, قيود تسوية), subsidiary_ledger, notes, or other, and a short description. '
            .'For adjustments sheets also give first_data_row and the columns for account_name, account_number (if any), debit and credit. '
            .'Rows are shown as "R<row> | <column>: <value>"; empty cells are omitted.';

        $content = "Identify the trial balance structure.\n\n".$text;
        if ($previousErrors) {
            $content .= "\nYour previous answer was rejected by validation:\n- ".implode("\n- ", $previousErrors)."\nFix these problems.";
        }

        return $this->call(new ToolCall([['text' => $system]], [
            'name'        => 'submit_structure',
            'description' => 'Submit the detected trial balance structure',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'sheet'          => ['type' => 'integer', 'enum' => array_keys($preview)],
                    'header_rows'    => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'first_data_row' => ['type' => 'integer'],
                    'columns'        => [
                        'type'       => 'object',
                        'properties' => $columnProps,
                        'required'   => ['account_name'],
                    ],
                    'other_sheets'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'sheet'          => ['type' => 'integer', 'enum' => array_keys($preview)],
                                'type'           => ['type' => 'string', 'enum' => AccountExtractor::SHEET_TYPES],
                                'description'    => ['type' => 'string'],
                                'first_data_row' => ['type' => 'integer', 'description' => 'adjustments only'],
                                'columns'        => [
                                    'type'        => 'object',
                                    'description' => 'adjustments only',
                                    'properties'  => array_fill_keys(AccountExtractor::ADJUSTMENT_FIELDS, $letterEnum),
                                ],
                            ],
                            'required' => ['sheet', 'type', 'description'],
                        ],
                    ],
                ],
                'required' => ['sheet', 'header_rows', 'first_data_row', 'columns'],
            ],
        ], $content), 'structure')->wait()->input;
    }

    /**
     * The classification instructions. The quoted sub-class names are the config names: classifyRules() gives them with
     * the current names after a rename on the taxonomy page (TaxonomyRules::usesOf counts them as a use).
     */
    public const CLASSIFY_RULES = <<<'PROMPT'
You are an expert accountant classifying the rows of a trial balance (ميزان المراجعة). The file can be in any language and any layout. Rows are shown as "R<row> | <column>: <value>"; the column map says what each column holds.

For every requested row, return exactly one entry in rows with row_type:
- account: a real posting account with balances
- parent: a group/summary account whose balance is the sum of the accounts under it
- total: a total or subtotal row
- section: a section heading
- other: anything else (repeated headers, notes, empty rows)
Return every requested row exactly once, and only the requested rows.

For every account row, choose exactly one sub_class from the allowed list.

Infer the numbering scheme from the file itself: accounts sharing a prefix usually belong to the same group. Use the account name as the primary signal and the number as supporting evidence. If the name conflicts with its group, lower confidence and say why.
The full chart of accounts is given for context (row | number | name | the file's own classification, if any). Classify similar accounts consistently with each other.

The file's own classification: when rows carry an existing classification (existing_classification / existing_note_classification columns), treat it as strong evidence prepared by the client. Map it to the allowed list and review it: follow it unless the account name clearly contradicts it. If you choose a different class, lower confidence and say why in reason. Do not ignore it.

Ambiguous cases:
- عهدة (custody/imprest: money given to an employee to spend on the company's behalf, settled with receipts) → "عُهد العاملين". سلفة (a loan to the employee, deducted from salary) → "سلف العاملين". Do not merge them.
- Deferred incoming cheques from customers (شيكات واردة مؤجلة) → "أوراق قبض" (notes receivable, under trade receivables), not prepayments.
- Credit card → "بطاقات ائتمان دائنة" (current liability). Debit/mada/prepaid card → "نقدية لدى البنوك". If the name doesn't make it clear, choose the most likely and set confidence below 70.
- A bank guarantee (خطاب ضمان) itself is off-balance-sheet. An account for it in the trial balance is usually the cash margin → "تأمينات لدى الغير".
- Account names starting with "مصروف" / "مصاريف" / "مصروفات" are income-statement expenses, unless the name says prepaid (مقدم، مدفوع مقدماً), accrued (مستحق) or deferred (مؤجل), which are balance-sheet accounts.
- End of service: "مصروف مكافأة نهاية الخدمة" (the period's expense, income statement) is not "مخصص نهاية الخدمة" (the provision, a liability). An expense-named account is the expense.
- Contra accounts → always the dedicated contra sub_class ("مجمع إهلاك الأصول الثابتة", "مجمع إهلاك الأصول غير الملموسة", "مخصص ديون مشكوك في تحصيلها", "مخصص هبوط أسعار المخزون", "مردودات ومسموحات المبيعات", "مردودات المشتريات"), never the related asset's sub_class.
- Periodic inventory: the opening and closing stock accounts posted with the income-statement accounts (e.g. "بضاعة أول المدة", "مخزون أول المدة", "بضاعة آخر المدة" with a credit balance) → "مخزون أول المدة" / "مخزون آخر المدة" (cost of sales), even when the file's own classification calls them inventory. The balance-sheet inventory account is the one carrying the stock, numbered with the balance-sheet accounts → an inventory sub_class. An inventory-like name numbered with the income-statement accounts is an opening or closing stock account.
- Provisions for warranty or after-sales maintenance of goods sold (مخصص ضمان، مخصص صيانة بضاعة مباعة) → "مخصص ضمان" (a liability), never "مخصص هبوط أسعار المخزون". Other provisions for liabilities with no dedicated sub_class (legal claims, etc.) → "مخصصات أخرى".
- Investments: land or buildings held for rent or capital appreciation (عقارات استثمارية، استثمارات عقارية) → "استثمارات عقارية", not property, plant and equipment. Investments in associates or joint ventures (شركات زميلة، شركات شقيقة، مشروعات مشتركة) → "استثمارات في شركات زميلة". Other or unspecified investments → "استثمارات قصيرة الأجل" / "استثمارات طويلة الأجل".
- Dividends declared and not yet paid (توزيعات أرباح مستحقة، أرباح مستحقة للشركاء/للمساهمين) → "توزيعات أرباح مستحقة" (a liability), not retained earnings.
- Deferred or unearned revenue (إيرادات مؤجلة، إيرادات مقدمة، إيرادات غير مكتسبة) → "إيرادات مؤجلة". Cash received from customers before an invoice (دفعات مقدمة من العملاء) stays "دفعات مقدمة من العملاء".
- A reversal of a receivables impairment or of a doubtful-debts allowance no longer needed (رد مخصص ديون مشكوك فيها، رد خسائر الانخفاض) → "رد خسائر الانخفاض", not "إيرادات أخرى".
- Accounts marked "لا تستخدمه" / inactive → classify normally by name; don't lower confidence for this.

confidence scores the classification only (always give it; 0 for rows that are not accounts):
- 90–100: the name is explicit and has only one reasonable interpretation.
- 70–89: this is the most likely class, but another interpretation is reasonable.
- below 70: the name is vague, conflicts with the number/group, or conflicts with similar accounts in the file.
Do NOT lower confidence because a balance is zero, and do NOT consider balances when scoring confidence. The code checks balances separately.

reason: one very short sentence in Egyptian Arabic (عامية مصرية), whatever the language of the file.

Never output amounts or balances.

When asked for file-level mappings, return them once:
- adjustment_matches: one entry per adjustment line with account_row = the R number (from the chart) of the trial-balance account the line refers to, matched by name (e.g. "الصندوق" is "النقد في الصندوق"). If the account is not in the trial balance, omit account_row.
- existing_class_matches: one entry per distinct existing classification value (X1, X2, …) with main_classes = every allowed main class that value can cover. A broad line such as "المصروفات العمومية والإدارية" covers many expense main classes; a precise one covers one.
PROMPT;

    public function warmCache(array $structure, array $headerRows, array $fileContext): void
    {
        $prompt = $this->classifyPrompt($structure, $headerRows, [], [], $fileContext, null);

        // Only the prefix matters: this call writes the cache and its answer is ignored (providers caching implicitly send nothing).
        $this->call(new ToolCall($prompt->system, $prompt->tool, $prompt->content, $prompt->maxTokens, warmUp: true), 'warm')->wait();
    }

    public function classifyRowsRolling(array $batches, int $concurrency, Closure $onAnswer): void
    {
        $stopped = false;

        // Resolves to the parsed answer or the final error (a ProviderException), never rejects.
        $request = fn (array $args): PromiseInterface => $this->call($this->classifyPrompt(...$args), $args[5] === null ? 'rows' : 'matching')
            ->then(fn (ToolAnswer $a) => $this->parseRows($a), fn (Throwable $e) => $e);
        // After a fatal error, answers still arriving from requests in flight are dropped.
        $answer = function ($key, $result, bool $isRetry) use ($onAnswer, &$stopped) {
            return $stopped ? null : $onAnswer($key, $result, $isRetry);
        };

        // Each batch is one chain (answer → optional missing-only retry), so a retry keeps its batch's slot.
        $chains = function () use ($batches, $request, $answer) {
            foreach ($batches as $key => $args) {
                yield $key => $request($args)->then(function ($result) use ($key, $request, $answer) {
                    $retry = $answer($key, $result, false);

                    return $retry === null ? null : $request($retry)->then(fn ($r) => $answer($key, $r, true));
                });
            }
        };

        // Exceptions thrown in the callbacks (fatal errors) reject the chain; stop the whole run instead of skipping the batch.
        (new EachPromise($chains(), [
            'concurrency' => $concurrency,
            'rejected'    => function ($reason, $key, PromiseInterface $aggregate) use (&$stopped) {
                $stopped = true;
                $aggregate->reject($reason);
            },
        ]))->promise()->wait();
    }

    /** CLASSIFY_RULES with the sub classes' current names (the same text for every request of a file: the cache holds). */
    public function classifyRules(): string
    {
        $renamed = $this->taxonomy->renamedOrigins()['sub'];

        return strtr(self::CLASSIFY_RULES, array_combine(array_map(fn ($o) => '"'.$o.'"', array_keys($renamed)), array_map(fn ($c) => '"'.$c.'"', $renamed)) ?: []);
    }

    private function parseRows(ToolAnswer $answer): array
    {
        $input = $answer->input;

        return [
            'rows'                   => self::listField($input, 'rows'),
            'adjustment_matches'     => self::listField($input, 'adjustment_matches'),
            'existing_class_matches' => self::listField($input, 'existing_class_matches'),
        ];
    }

    /**
     * A list field of the tool input. The model sometimes sends a list as a JSON string: decoded here.
     * Anything still not an array is [] (a gap, retried once by the caller). Items are validated by the caller.
     */
    private static function listField(array $input, string $field): array
    {
        $value = $input[$field] ?? [];
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;
        Log::channel(config('classification.log_channel', 'classification'))->warning('llm_tool_field_malformed', [
            'field'   => $field,
            'type'    => get_debug_type($value),
            'decoded' => is_array($decoded),
            'raw'     => Str::limit(is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE), 2000),
        ]);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array{adjustments: list<int>, existing: list<int>}|null  $match  ids of the adjustment lines / existing values to match (null: none)
     */
    private function classifyPrompt(array $structure, array $headerRows, array $rows, array $context, array $fileContext, ?array $match): ToolCall
    {
        $format = fn (array $set) => implode("\n", array_map(
            fn ($n, $cells) => SpreadsheetReader::formatRow($n, $cells), array_keys($set), $set
        ));

        $map = [];
        foreach ($structure['columns'] as $field => $letter) {
            if ($letter) {
                $map[] = "{$letter} = ".self::FIELD_LABELS[$field];
            }
        }

        $adjustments = $fileContext['adjustments'] ?? [];
        $existing    = $fileContext['existing_values'] ?? [];

        // Everything identical for every batch of this file goes in the cached prefix.
        $fileBlock = "Column map:\n".implode("\n", $map)
            ."\n\nHeader rows:\n".$format($headerRows)
            ."\n\nFull chart of accounts, for context only (row | number | name | file classification):\n"
            .implode("\n", array_map(
                fn ($a) => rtrim("R{$a['row']} | {$a['account_number']} | {$a['account_name']} | ".($a['existing'] ?? ''), ' |'),
                $fileContext['chart'] ?? []
            ))
            .($existing ? "\n\nDistinct existing classification values (id | value):\n".implode("\n", array_map(
                fn ($e) => "X{$e['id']} | {$e['value']}", $existing
            )) : '')
            .($adjustments ? "\n\nAdjustment lines (id | sheet row | account | debit | credit):\n".implode("\n", array_map(
                fn ($a) => "A{$a['id']} | {$a['sheet_name']} R{$a['row']} | {$a['account_name']} | {$a['debit']} | {$a['credit']}",
                $adjustments
            )) : '');

        // What to ask goes in the message, never in the cached prefix, so every call of the file reads the same cache.
        $asks = array_filter([
            ($match['adjustments'] ?? []) ? 'adjustment_matches for adjustment lines '.implode(', ', array_map(fn ($id) => "A{$id}", $match['adjustments'])) : null,
            ($match['existing'] ?? []) ? 'existing_class_matches for existing classification values '.implode(', ', array_map(fn ($id) => "X{$id}", $match['existing'])) : null,
        ]);
        $content = implode("\n\n", array_filter([
            $context ? "Context rows only, do not classify:\n".$format($context) : null,
            $rows ? "Rows to classify:\n".$format($rows) : null,
            $asks ? ($rows ? 'Also return ' : 'No rows to classify. Return only ').implode(' and ', $asks).'.' : null,
        ])) ?: 'No rows to classify.';

        $properties = [
            'rows' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'row'        => ['type' => 'integer', 'description' => 'row number of a requested row'],
                        'row_type'   => ['type' => 'string', 'enum' => self::ROW_TYPES],
                        'sub_class'  => ['type' => 'string', 'enum' => $this->taxonomy->subClasses()],
                        'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        'reason'     => ['type' => 'string'],
                    ],
                    'required' => ['row', 'row_type', 'confidence'],
                ],
            ],
        ];
        if ($adjustments) {
            $properties['adjustment_matches'] = [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'adjustment_row' => ['type' => 'integer', 'enum' => array_column($adjustments, 'id')],
                        'account_row'    => ['type' => 'integer', 'description' => 'R number of the trial-balance account; omit when the account is not in the trial balance'],
                    ],
                    'required' => ['adjustment_row'],
                ],
            ];
        }
        if ($existing) {
            $properties['existing_class_matches'] = [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'existing_id'  => ['type' => 'integer', 'enum' => array_column($existing, 'id')],
                        'main_classes' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $this->taxonomy->mainClasses()]],
                    ],
                    'required' => ['existing_id', 'main_classes'],
                ],
            ];
        }

        return new ToolCall([
            ['text' => $this->classifyRules()],
            ['text' => $fileBlock, 'cache' => true],
        ], [
            'name'        => 'submit_rows',
            'description' => 'Submit the row type and classification of every requested row',
            'strict'      => true,
            'schema'      => [
                'type'       => 'object',
                'properties' => $properties,
                'required'   => ['rows'],
            ],
        ], $content);
    }

    /**
     * One forced tool call to the configured provider (retries and errors are the driver's). Counts the tokens of the
     * answer for the run's usage (meta.usage), by kind of call (UsageMeter), and logs an answer without a tool call.
     *
     * @return PromiseInterface<ToolAnswer>
     */
    private function call(ToolCall $call, string $kind): PromiseInterface
    {
        return $this->driver->classify($call)->then(function (ToolAnswer $answer) use ($call, $kind) {
            if ($answer->usage !== null) {
                $this->meter->add($answer->usage, $kind);
            }
            if ($answer->noToolCall !== null && ! $call->warmUp) {
                Log::channel(config('classification.log_channel', 'classification'))->warning('llm_no_tool_call', [
                    'kind' => $kind, 'tool' => $call->tool['name'], 'reason' => $answer->noToolCall,
                ]);
            }

            return $answer;
        });
    }
}
