<?php

namespace App\Services;

use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationImport;
use App\Models\ClassificationImportMapping as Mapping;
use App\Models\ClassificationImportRow;
use App\Models\ClassificationNameLayerEntry;
use App\Repositories\Contracts\TaxonomyMapperRepositoryInterface;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Repositories\Eloquents\TaxonomyMapperRepository;
use App\Support\ClassificationKey;
use App\Support\Llm\Provider;
use App\Support\ImportNormalizer;
use Closure;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use App\Exceptions\LocalizedException;
use App\Support\Phrase;
use RuntimeException;

/**
 * Imports trial balances already classified by accountants into the classification cache:
 * process() reads the file and maps every distinct value once (الميزانية → main class, then (main class, الإيضاحات) →
 * sub-class), reusing earlier imports' mappings before asking the LLM; the accountant approves mappings in the review page;
 * apply() writes the rows whose mappings are all approved (source = imported) and can run again as more get approved.
 * Imported entries never replace a manual correction or an AI answer.
 */
class CacheImport
{
    public const ROUTE = TaxonomyMapperRepository::ROUTE;

    /**
     * Why a row is not imported: code => Arabic label (classification_messages.import.skip_reasons in every language).
     * Structural reasons are set while reading, the rest by resolve() / apply().
     */
    public const SKIP_REASONS = [
        'header_row'        => 'صف عناوين مكرر داخل البيانات',
        'empty_name'        => 'اسم الحساب فارغ',
        'empty_budget'      => 'الميزانية فارغة',
        'budget_not_arabic' => 'الميزانية تبدو اسم حساب وليست بند ميزانية',
        'budget_skipped'    => 'تقرر عدم استيراد بند الميزانية',
        'budget_unmapped'   => 'لم يُربط بند الميزانية بالشجرة',
        'note_unmapped'     => 'لم يُربط الإيضاح بتصنيف فرعي',
        'no_side'           => 'يعتمد تصنيفه على اتجاه الرصيد، والرصيد الختامي والافتتاحي وصافي الحركة جميعها صفر',
        'key_conflict'      => 'ورد المفتاح نفسه (كود + اسم + ميزانية) بتصنيفين مختلفين',
        'cache_has_entry'   => 'تحتوي الذاكرة على تصحيح يدوي أو إجابة ذكاء اصطناعي لنفس المفتاح',
    ];

    private const STRUCTURAL = ['header_row', 'empty_name', 'empty_budget', 'budget_not_arabic'];

    /** Mapping methods decided without the LLM's judgement: approved when created, unless flagged. */
    private const AUTO_APPROVED = ['override', 'taxonomy', 'side_rule'];

    /** Header labels after ImportNormalizer::text => field. A row with 3+ of them, one being the name, is a header row. */
    private const LABELS = [
        'رقم حساب' => 'code', 'اسم حساب' => 'name', 'ميزانيه' => 'budget', 'بنود ميزانيه' => 'budget', 'مستوي قوائم' => 'budget',
        'ايضاحات' => 'note', 'ايضاحات ميزانيه' => 'note', 'اخر مدين' => 'closing_debit', 'اخر دائن' => 'closing_credit',
        'اسم شركه' => 'company', 'account' => 'english_name', 'م' => 'serial',
        'اول مدين' => 'opening_debit', 'اول دائن' => 'opening_credit', 'حركه مدينه' => 'period_debit', 'حركه دائنه' => 'period_credit',
        'تسويه مدين' => 'other', 'تسويه دائن' => 'other',
    ];

    /**
     * Columns the file must have, and the ones without which a part of the import is weaker (their names and effects:
     * classification_messages.import.columns / effects).
     */
    private const REQUIRED = ['name', 'budget'];
    private const RECOMMENDED = ['note', 'company', 'closing_debit'];

    public function __construct(
        private TaxonomyRepositoryInterface $taxonomy,
        private TaxonomyMapperRepositoryInterface $mapper,
    ) {}

    /** Reads and maps a file in one go (the artisan command); the site queues process() instead. */
    public function import(string $path, string $fileName): ClassificationImport
    {
        $import = ClassificationImport::create(['file_name' => $fileName, 'status' => ClassificationImport::RUNNING, 'file_hash' => hash_file('sha256', $path)]);
        $this->process($import, $path);

        return $import->fresh();
    }

    /**
     * Reads the file into rows, maps them, builds the report: the import becomes pending_review.
     *
     * @param  Closure(string $stage, int $done, int $total): void|null  $progress
     */
    public function process(ClassificationImport $import, string $path, ?Closure $progress = null): void
    {
        $progress ??= fn () => null;
        $progress('reading', 0, 0);
        $rows = $this->read($path);
        DB::transaction(function () use ($rows, $import) {
            $import->rows()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                ClassificationImportRow::insert(array_map(fn ($r) => $r + ['import_id' => $import->id], $chunk));
            }
        });
        $this->resolve($import, $progress);
        $import->update(['status' => ClassificationImport::PENDING, 'stage' => null, 'report' => $this->report($import)]);
    }

    /** Re-maps what is missing (new values, or values under a changed budget mapping), re-resolves the rows, rebuilds the report. */
    public function refresh(ClassificationImport $import): ClassificationImport
    {
        $this->resolve($import);
        $import->update(['report' => $this->report($import)]);

        return $import;
    }

    /* ---------- reading ---------- */

    /**
     * The file's header, checked before anything is queued.
     *
     * @return array{errors: list<Phrase>, warnings: list<Phrase>}
     */
    public function check(string $path): array
    {
        try {
            [$reader, $readable] = \App\Support\SpreadsheetFile::open($path);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class implements IReadFilter
            {
                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $row <= 60;
                }
            });
            $cells = $reader->load($readable)->getSheet(0)->toArray(null, true, false, false);
        } catch (\Throwable) {
            return ['errors' => [self::m('unreadable')], 'warnings' => []];
        }

        $fields = [];
        foreach ($cells as $row) {
            $found = $this->labels($row);
            if (count($found) > count($fields)) {
                $fields = $found;
            }
        }
        $errors = [];
        foreach (self::REQUIRED as $field) {
            isset($fields[$field]) || $errors[] = self::m('missing_column', ['name' => self::m("columns.{$field}")]);
        }
        if ($errors && count($fields) < 2) {
            $errors = [self::m('no_header')];
        }
        $warnings = [];
        foreach (self::RECOMMENDED as $field) {
            isset($fields[$field]) || $warnings[] = self::m('missing_recommended', ['name' => self::m("columns.{$field}"), 'effect' => self::m("effects.{$field}")]);
        }

        return ['errors' => $errors, 'warnings' => $errors ? [] : $warnings];
    }

    /** @return list<array> import rows (without import_id), in file order */
    public function read(string $path): array
    {
        [$reader, $readable] = \App\Support\SpreadsheetFile::open($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($readable)->getSheet(0);

        $layout = null;
        $out    = [];
        foreach ($sheet->toArray(null, true, false, false) as $i => $cells) {
            $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $cells);
            if (! array_filter($cells, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            if ($header = $this->header($cells)) {
                // A header repeated inside the data may move the columns (one company's block is shifted): it sets the
                // layout from here on; the company column, often unlabelled there, carries over.
                $layout = $header + ['company' => $layout['company'] ?? null];
                if ($i > 0) {
                    $out[] = $this->row($i + 1, [], 'header_row');
                }
                continue;
            }
            if ($layout === null) {
                throw new LocalizedException(self::m('data_before_header'));
            }
            $get = fn (string $f) => isset($layout[$f]) ? ($cells[$layout[$f]] ?? null) : null;
            $r   = [
                'company'      => $this->text($get('company')),
                'account_code' => $this->code($get('code')),
                'account_name' => $this->text($get('name')),
                'budget_value' => $this->text($get('budget')),
                'note_value'   => $this->text($get('note')),
                'side'          => $this->side($get('closing_debit'), $get('closing_credit')),
                'opening_side'  => $this->side($get('opening_debit'), $get('opening_credit')),
                'movement_side' => $this->side($get('period_debit'), $get('period_credit')),
            ];
            $reason = match (true) {
                $r['account_name'] === null                       => 'empty_name',
                $r['budget_value'] === null                       => 'empty_budget',
                ! ImportNormalizer::isArabic($r['budget_value'])  => 'budget_not_arabic',
                default                                           => null,
            };
            $out[] = $this->row($i + 1, $r, $reason);
        }
        if ($layout === null) {
            throw new LocalizedException(self::m('no_header_row'));
        }

        return $out;
    }

    /** @return array<string, int> field => column index of the known labels of a row */
    private function labels(array $cells): array
    {
        $fields = [];
        foreach ($cells as $col => $v) {
            $field = is_string($v) ? (self::LABELS[ImportNormalizer::text($v)] ?? null) : null;
            if ($field !== null) {
                $fields[$field] ??= $col;
            }
        }

        return $fields;
    }

    /** @return array<string, int>|null field => column index, when the row is a header */
    private function header(array $cells): ?array
    {
        $fields = $this->labels($cells);

        return count($fields) >= 3 && isset($fields['name']) ? $fields : null;
    }

    private function row(int $n, array $r, ?string $reason): array
    {
        return ['row' => $n, 'skip_reason' => $reason] + $r + [
            'company' => null, 'account_code' => null, 'account_name' => null, 'budget_value' => null, 'note_value' => null,
            'side' => null, 'budget_mapping_id' => null, 'note_mapping_id' => null, 'sub_class' => null, 'confidence' => 0,
            'approved' => false, 'applied' => false, 'opening_side' => null, 'movement_side' => null,
        ];
    }

    private function text(mixed $v): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', (string) $v));

        return $v === '' ? null : mb_substr($v, 0, 250);
    }

    private function code(mixed $v): ?string
    {
        if (is_float($v) && floor($v) === $v) {
            $v = (string) (int) $v;
        }

        return $this->text($v);
    }

    private function side(mixed $debit, mixed $credit): ?string
    {
        return ClassificationCache::side(['closing_debit' => is_numeric($debit) ? $debit : 0, 'closing_credit' => is_numeric($credit) ? $credit : 0]);
    }

    /* ---------- mapping ---------- */

    private function resolve(ClassificationImport $import, ?Closure $progress = null): void
    {
        $progress ??= fn () => null;
        $rows = $import->rows()->where(fn ($q) => $q->whereNull('skip_reason')->orWhereNotIn('skip_reason', self::STRUCTURAL))->get();

        $progress('budget', 0, 0);
        $budgets = $this->budgetMappings($import, $rows->pluck('budget_value')->all());
        $notes   = $this->noteMappings($import, $rows, $budgets, $progress);
        $sides   = $this->sideRules();

        DB::transaction(function () use ($rows, $budgets, $notes, $sides, $import) {
            $counts = [];
            foreach ($rows as $row) {
                $b = $budgets[ImportNormalizer::text($row->budget_value)] ?? null;
                $n = $b && $b->decision !== 'skip' && $b->sub_class === null ? ($notes[$this->noteKey($b, $row)] ?? null) : null;
                $sideRule = $n?->method === 'side_rule' ? ($sides[$n->normalized] ?? null) : null;
                $sub = $b?->sub_class ?? match (true) {
                    $n?->decision !== 'map' => null,
                    $sideRule !== null      => ($side = self::ruleSide($row)) ? $sideRule[$side] : null,
                    default                 => self::activeSub($n),
                };
                // A routed row is decided by its note alone: the routing only says the note decides.
                $confidence = $b?->decision === self::ROUTE ? ($n?->confidence ?? 0) : min($b?->confidence ?? 0, $n?->confidence ?? 100);
                $reason     = match (true) {
                    $b === null || ($b->decision === 'map' && $b->main_class === null) => 'budget_unmapped',
                    $b->decision === 'skip'                                           => 'budget_skipped',
                    $sideRule !== null && self::ruleSide($row) === null               => 'no_side',
                    $sub === null                                                     => 'note_unmapped',
                    default                                                           => null,
                };
                $row->fill([
                    'budget_mapping_id' => $b?->id, 'note_mapping_id' => $n?->id, 'confidence' => $confidence,
                    'sub_class' => $reason ? null : $sub, 'skip_reason' => $reason,
                    'approved'  => ! $reason && $b->reviewed && (! $n || $n->reviewed),
                ]);
                // A row whose class or approval changed since the last «تطبيق» waits for the next one.
                $row->isDirty(['sub_class', 'approved']) && $row->applied = false;
                $row->isDirty() && $row->save();
                foreach ([$b, $n] as $m) {
                    $m && $counts[$m->id] = ($counts[$m->id] ?? 0) + 1;
                }
            }
            $import->mappings()->update(['rows' => 0]);
            foreach ($counts as $id => $c) {
                Mapping::whereKey($id)->update(['rows' => $c]);
            }
        });
    }

    /**
     * The newest mapping of each value in the other imports (an export's mappings included), approved ones first.
     *
     * @return array<string, Mapping> by parent|normalized
     */
    private function library(ClassificationImport $import, string $kind): array
    {
        $out = [];
        foreach (Mapping::where('kind', $kind)->where('import_id', '!=', $import->id)->where('reviewed', true)->orderBy('updated_at')->get()
            ->concat(Mapping::where('kind', $kind)->where('import_id', '!=', $import->id)->where('reviewed', false)->orderBy('updated_at')->get()) as $m) {
            $key = $m->parent.'|'.$m->normalized;
            if (! isset($out[$key]) || ! $out[$key]->reviewed || $m->reviewed) {
                $out[$key] = $m;   // approved beats unreviewed; then the newest
            }
        }

        return $out;
    }

    /** A copy of an earlier mapping: approved stays approved (method reused), the rest stays unreviewed with its own method. */
    private function reuse(ClassificationImport $import, Mapping $from, string $value): Mapping
    {
        return Mapping::create(array_intersect_key($from->toArray(), array_flip([
            'kind', 'normalized', 'parent', 'decision', 'main_class', 'sub_class', 'function_sub_class', 'policy', 'confidence', 'reason', 'flag', 'reviewed',
            'reason_phrase', 'flag_phrase',
        ])) + ['import_id' => $import->id, 'value' => $value, 'method' => $from->reviewed ? 'reused' : $from->method, 'rows' => 0]);
    }

    /** @return array<string, Mapping> by normalized value; missing values: override → earlier import → taxonomy → one LLM call */
    private function budgetMappings(ClassificationImport $import, array $values): array
    {
        $existing  = $import->mappings()->where('kind', Mapping::BUDGET)->get()->keyBy('normalized')->all();
        $library   = $this->library($import, Mapping::BUDGET);
        $mains     = array_combine(array_map([ImportNormalizer::class, 'text'], $this->taxonomy->mainClasses()), $this->taxonomy->mainClasses());
        $overrides = [];
        foreach (config('classification.import.budget_overrides', []) as $value => $o) {
            $overrides[ImportNormalizer::text($value)] = $o;
        }

        $pending = [];
        foreach (array_unique(array_filter($values)) as $value) {
            $norm = ImportNormalizer::text($value);
            if (isset($existing[$norm]) || isset($pending[$norm])) {
                continue;
            }
            $base = ['import_id' => $import->id, 'kind' => Mapping::BUDGET, 'value' => $value, 'normalized' => $norm, 'confidence' => 100];
            if ($o = $overrides[$norm] ?? null) {
                $existing[$norm] = $this->create($base + (isset($o['skip'])
                    ? ['decision' => 'skip', 'method' => 'override', 'reason' => $o['skip']]
                    : $this->toMain($o['main'], $o['sub'] ?? null) + ['decision' => 'map', 'method' => 'override', 'reason' => $o['reason'] ?? null,
                        'flag' => $o['flag'] ?? null, 'policy' => $o['policy'] ?? null]));
            } elseif ($from = $library['|'.$norm] ?? null) {
                $existing[$norm] = $this->reuse($import, $from, $value);
            } elseif (isset($mains[$norm])) {
                $existing[$norm] = $this->create($base + $this->toMain($mains[$norm]) + ['decision' => 'map', 'method' => 'taxonomy']);
            } else {
                $pending[$norm] = $value;
            }
        }

        if ($pending) {
            $ids     = array_keys($pending);
            $this->usesAi($import);
            $answers = $this->mapper->mapBudgetItems(array_values($pending), $this->taxonomy->mainClasses());
            foreach (array_values($pending) as $i => $value) {
                $a = $answers[$i] ?? ['decision' => 'skip', 'main_class' => null, 'confidence' => 0, 'reason' => self::m('ai_no_budget')];
                $existing[$ids[$i]] = $this->create([
                    'import_id' => $import->id, 'kind' => Mapping::BUDGET, 'value' => $value, 'normalized' => $ids[$i],
                    'decision' => $a['decision'], 'method' => 'llm', 'confidence' => $a['confidence'],
                ] + self::reason($a['reason']) + ($a['decision'] === 'map' ? $this->toMain($a['main_class']) : []));
            }
        }

        return $existing;
    }

    /** Creates a mapping; one decided by config or an exact taxonomy match is approved unless it carries a review flag. */
    private function create(array $values): Mapping
    {
        $values['reviewed'] = in_array($values['method'], self::AUTO_APPROVED, true) && empty($values['flag']);

        return Mapping::create($values);
    }

    /** main_class, plus the sub-class when it is given or the main class has only one. */
    private function toMain(string $main, ?string $sub = null): array
    {
        $subs = $this->subsOf($main);

        return ['main_class' => $main, 'sub_class' => $sub ?? (count($subs) === 1 ? $subs[0] : null)];
    }

    private function noteKey(Mapping $budget, ClassificationImportRow $row): string
    {
        return ($budget->decision === self::ROUTE ? self::ROUTE : $budget->main_class).'|'.ImportNormalizer::text($row->note_value ?? $row->account_name);
    }

    /**
     * The side a side rule decides by: the closing balance's; when it is zero, the opening balance's, then the net
     * movement's; null when all are zero (the row is not imported: a cached guess could be wrong for a later balance).
     */
    public static function ruleSide(ClassificationImportRow $row): ?string
    {
        return $row->side ?? $row->opening_side ?? $row->movement_side;
    }

    /** @return array<string, array{debit: string, credit: string}> by normalized note (config side_rules) */
    private function sideRules(): array
    {
        $out = [];
        foreach (config('classification.import.side_rules', []) as $rule) {
            foreach ($rule['notes'] as $note) {
                $out[ImportNormalizer::text($note)] = ['debit' => $rule['debit'], 'credit' => $rule['credit']];
            }
        }

        return $out;
    }

    /** @return array<string, Mapping> by noteKey; side rule → earlier import → exact sub-class name → the LLM in batches */
    private function noteMappings(ClassificationImport $import, $rows, array $budgets, Closure $progress): array
    {
        $existing = [];
        foreach ($import->mappings()->where('kind', Mapping::NOTE)->get() as $m) {
            $existing[$m->parent.'|'.$m->normalized] = $m;
        }
        $library = $this->library($import, Mapping::NOTE);
        $sides   = $this->sideRules();

        $pending = [];
        foreach ($rows as $row) {
            $b = $budgets[ImportNormalizer::text($row->budget_value)] ?? null;
            if (! $b || $b->decision === 'skip' || $b->sub_class !== null || ($b->decision === 'map' && $b->main_class === null)) {
                continue;
            }
            $key = $this->noteKey($b, $row);
            if (isset($existing[$key])) {
                continue;
            }
            $parent = $b->decision === self::ROUTE ? self::ROUTE : $b->main_class;
            $policy = $b->policy ? config("classification.import.policies.{$b->policy}") : null;
            $pending[$key] ??= [
                'parent' => $parent, 'budget' => $b->value, 'note' => $row->note_value ?? $row->account_name, 'names' => [],
                // A policy item's sub_class is by nature: no main-class hint pulling it back to the line item.
                'hint_main' => $parent === self::ROUTE || $policy ? null : $parent, 'policy' => $policy ? $b->policy : null,
            ] + ($policy ? ['function_subs' => $this->subsOf($policy['function_main'])] : []);
            if (count($pending[$key]['names']) < 3 && ! in_array($row->account_name, $pending[$key]['names'], true)) {
                $pending[$key]['names'][] = $row->account_name;
            }
        }

        $bySub = [];
        foreach ($this->taxonomy->subClasses() as $sub) {
            $bySub[ImportNormalizer::text($sub)] = $sub;
        }
        foreach ($pending as $key => $p) {
            $norm = ImportNormalizer::text($p['note']);
            if (isset($sides[$norm])) {
                // Decided per row by the closing balance side (resolve()).
                $existing[$key] = $this->noteMapping($import, $p, ['sub_class' => null, 'function_sub_class' => null, 'confidence' => 100,
                    'reason' => self::m('side_rule', ['debit' => $sides[$norm]['debit'], 'credit' => $sides[$norm]['credit']])], 'side_rule', 'map');
            } elseif ($from = $library[$p['parent'].'|'.$norm] ?? null) {
                $existing[$key] = $this->reuse($import, $from, $p['note']);
            } elseif (! $p['policy'] && ($sub = $bySub[$norm] ?? null)) {
                // A note that is already a sub-class name needs no LLM (policy items need both answers: always the LLM).
                $existing[$key] = $this->noteMapping($import, $p, ['sub_class' => $sub, 'function_sub_class' => null, 'confidence' => 100, 'reason' => ''], 'taxonomy');
            } else {
                continue;
            }
            unset($pending[$key]);
        }

        $none    = ['sub_class' => null, 'function_sub_class' => null, 'confidence' => 0, 'reason' => self::m('ai_no_note')];
        $batches = array_chunk($pending, max(1, (int) config('classification.import.llm_batch_size', 150)), true);
        $progress('notes', 0, count($batches));
        $batches && $this->usesAi($import);
        foreach ($batches as $done => $batch) {
            $keys    = array_keys($batch);
            $answers = $this->mapper->mapNotes(array_values($batch), $this->taxonomy->map());
            foreach (array_values($batch) as $i => $p) {
                $existing[$keys[$i]] = $this->noteMapping($import, $p, $answers[$i] ?? $none, 'llm');
            }
            $progress('notes', $done + 1, count($batches));
        }

        return $existing;
    }

    private function noteMapping(ClassificationImport $import, array $p, array $a, string $method, ?string $decision = null): Mapping
    {
        $main = $a['sub_class'] ? ($this->taxonomy->find($a['sub_class'])[1] ?? null) : null;

        return $this->create([
            'import_id' => $import->id, 'kind' => Mapping::NOTE, 'value' => $p['note'], 'normalized' => ImportNormalizer::text($p['note']),
            'parent' => $p['parent'], 'decision' => $decision ?? ($a['sub_class'] ? 'map' : 'skip'), 'sub_class' => $a['sub_class'], 'main_class' => $main,
            'function_sub_class' => $a['function_sub_class'] ?? null, 'policy' => $p['policy'] ?? null,
            'method' => $method, 'confidence' => $a['confidence'],
        ] + self::reason($a['reason']) + self::flagOf($this->outsideFlag($p['hint_main'] ?? null, $main, $p['policy'] ?? null)));
    }

    /** An import message (classification_messages.import). */
    private static function m(string $key, array $params = []): Phrase
    {
        return Phrase::make('classification_messages.import.'.$key, $params);
    }

    /** A mapping's reason: the Arabic text (as always) and, when the system worded it, its phrase (an AI's reason is data). */
    private static function reason(Phrase|string|null $reason): array
    {
        return $reason instanceof Phrase
            ? ['reason' => $reason->ar(), 'reason_phrase' => $reason->toArray()]
            : ['reason' => $reason ?: null, 'reason_phrase' => null];
    }

    private static function flagOf(?Phrase $flag): array
    {
        return ['flag' => $flag?->ar(), 'flag_phrase' => $flag?->toArray()];
    }

    /** The review flag of a note mapped outside the main class its الميزانية maps to (policy notes: the policy decides). */
    private function outsideFlag(?string $hint, ?string $main, ?string $policy): ?Phrase
    {
        return $hint && $main && $main !== $hint && ! $policy ? self::m('outside_main', ['hint' => $hint, 'main' => $main]) : null;
    }

    /** The sub-class a note mapping gives its rows: by function when its policy's mode is function, else by nature. */
    public static function activeSub(Mapping $note): ?string
    {
        if ($note->policy && config("classification.import.policies.{$note->policy}.mode") === 'function') {
            return $note->function_sub_class;
        }

        return $note->sub_class;
    }

    /** @return list<string> */
    public function subsOf(string $main): array
    {
        return array_keys(array_filter($this->taxonomy->map(), fn ($t) => $t[1] === $main));
    }

    /* ---------- review ---------- */

    /** The accountant's decision on one mapping (or approval as is); the import's rows and report follow. */
    public function review(Mapping $mapping, array $data, bool $refresh = true): Mapping
    {
        if (isset($data['decision'])) {
            $map = $data['decision'] === 'map';
            if ($mapping->kind === Mapping::BUDGET) {
                $mapping->fill([
                    'decision'   => $data['decision'],
                    'main_class' => $map ? $data['main_class'] : null,
                    'sub_class'  => $map ? ($data['sub_class'] ?? $this->toMain($data['main_class'])['sub_class']) : null,
                ]);
            } elseif (! $map) {
                $mapping->fill(['decision' => 'skip']);
            } elseif ($mapping->policy && config("classification.import.policies.{$mapping->policy}.mode") === 'function') {
                $mapping->fill(['decision' => 'map', 'function_sub_class' => $data['sub_class']]);
            } else {
                $main = $this->taxonomy->find($data['sub_class'])[1] ?? null;
                $mapping->fill(['decision' => 'map', 'sub_class' => $data['sub_class'], 'main_class' => $main]);
            }
            $mapping->method = 'manual';
        }
        // Reviewed = the accountant's decision, whether they changed it or approved it as is.
        $mapping->fill(['reviewed' => true, 'confidence' => 100]);
        $mapping->save();
        if ($refresh) {
            $this->refresh(ClassificationImport::findOrFail($mapping->import_id));
        }

        return $mapping->fresh();
    }

    /** Approves mappings as they are, then re-resolves once. @return int approved */
    public function approve(ClassificationImport $import, array $ids): int
    {
        $mappings = $import->mappings()->whereKey($ids)->get();
        DB::transaction(fn () => $mappings->each(fn (Mapping $m) => $this->review($m, [], false)));
        $this->refresh($import);

        return $mappings->count();
    }

    /* ---------- apply ---------- */

    /**
     * Writes the rows whose mappings are all approved (never over a manual correction or an AI answer) and rebuilds the
     * name layer. Run it again as more mappings get approved, or after switching a policy: the result follows the
     * current mappings, and this import's entries that no approved row produces any more are removed.
     */
    public function apply(ClassificationImport $import): array
    {
        if (! in_array($import->status, [ClassificationImport::PENDING, ClassificationImport::APPLIED], true)) {
            throw new LocalizedException(self::m('not_ready'));
        }
        $this->resolve($import);   // the current mappings and policy modes

        return DB::transaction(function () use ($import) {
            [$entries, $conflicts, $taken] = $this->entries($import);
            $kept = array_column($entries, 'key_hash');
            ClassificationCacheEntry::where('import_id', $import->id)->where('source', ClassificationCache::IMPORTED)
                ->whereNotIn('key_hash', $kept)->delete();
            // These rows get no cache entry but keep their sub-class: still the accountant's label, counted by the name layer.
            $import->rows()->whereIn('id', $conflicts)->update(['skip_reason' => 'key_conflict']);
            $import->rows()->whereIn('id', $taken)->update(['skip_reason' => 'cache_has_entry']);
            $import->rows()->update(['applied' => DB::raw('approved')]);
            foreach (array_chunk($entries, 200) as $chunk) {
                ClassificationCacheEntry::upsert($chunk, ['key_hash'], [
                    'base_hash', 'name_hash', 'normalized_key', 'original_name', 'account_code', 'budget_item', 'row_type', 'main_class', 'sub_class',
                    'source', 'confidence', 'reason', 'taxonomy_version', 'import_id', 'updated_at',
                ]);
            }
            $import->update(['status' => ClassificationImport::APPLIED, 'applied_at' => now()]);
            $layer = $this->rebuildNameLayer();
            $import->update(['report' => $this->report($import)]);

            return [
                'cache_entries' => count($entries), 'key_conflicts' => count($conflicts), 'kept_existing' => count($taken),
                'pending_review' => $import->rows()->whereNotNull('sub_class')->where('approved', false)->count(), 'name_layer' => $layer,
            ];
        });
    }

    /**
     * @return array{0: list<array>, 1: list<int>, 2: list<int>} cache rows to upsert from the approved rows; row ids whose key
     *         has two sub-classes in the import; row ids whose key the cache holds from a manual correction or the AI
     */
    private function entries(ClassificationImport $import): array
    {
        $byHash = [];
        foreach ($import->rows()->whereNotNull('sub_class')->where('approved', true)->get() as $row) {
            $key = ClassificationKey::make($row->account_code, $row->account_name, $row->budget_value, null);
            if ($key !== null) {
                $byHash[ClassificationKey::hash($key)][] = [$row, $key];
            }
        }

        $taken = [];
        foreach (array_chunk(array_keys($byHash), 500) as $hashes) {
            $taken += ClassificationCacheEntry::whereIn('key_hash', $hashes)->where('source', '!=', ClassificationCache::IMPORTED)->pluck('key_hash', 'key_hash')->all();
        }

        $entries     = [];
        $conflictIds = [];
        $takenIds    = [];
        foreach ($byHash as $hash => $list) {
            $ids = array_map(fn ($x) => $x[0]->id, $list);
            if (isset($taken[$hash])) {
                array_push($takenIds, ...$ids);
                continue;
            }
            // Compared as stored: one account on both sides of a pair (a side rule) is one kind, not a conflict.
            $subs = array_unique(array_map(fn ($x) => ClassificationCache::baseClass($x[0]->sub_class, $x[0]->account_name), $list));
            // A row already on the pair's base side is the accountant's own choice of member (e.g. سلف by its note, whatever
            // the name says): the other side's rows of that pair follow it instead of the name rule.
            $chosen = array_unique(array_filter(array_map(fn ($x) => $x[0]->sub_class, $list), fn ($s) => ($p = ClassificationCache::pairOf($s)) && $p[2] === ($p[1]['base'] ?? 'debit')));
            if (count($chosen) === 1) {
                $pair = ClassificationCache::pairOf(reset($chosen))[0];
                $subs = array_unique(array_map(fn ($s) => (ClassificationCache::pairOf($s)[0] ?? null) === $pair ? reset($chosen) : $s, $subs));
            }
            if (count($subs) > 1) {
                array_push($conflictIds, ...$ids);
                continue;
            }
            [$row, $key] = $list[0];
            $sub = reset($subs);
            $entries[] = [
                'key_hash'         => $hash,
                'base_hash'        => ClassificationKey::baseHash($key),
                'name_hash'        => ClassificationKey::nameHash($row->account_name),
                'normalized_key'   => $key,
                'original_name'    => $row->account_name,
                'account_code'     => $row->account_code,
                'budget_item'      => $row->budget_value,
                'row_type'         => 'account',
                'main_class'       => $this->taxonomy->find($sub)[1] ?? null,
                'sub_class'        => $sub,
                'source'           => ClassificationCache::IMPORTED,
                'confidence'       => 100,   // approved by the accountant
                'reason'           => "تصنيف المحاسب من ملف «{$import->file_name}»".($row->company ? " ({$row->company})" : ''),
                'taxonomy_version' => ClassificationCache::version(),
                'import_id'        => $import->id,
                'created_at'       => now(),
                'updated_at'       => now(),
            ];
        }

        return [$entries, $conflictIds, $takenIds];
    }

    /* ---------- name-only layer ---------- */

    /**
     * Name (+ side) groups of the resolved rows: the most used sub-class, and the share of companies that use only it.
     * $withSide = false groups by name only (the report's "before").
     *
     * @param  iterable<ClassificationImportRow>  $rows
     * @return list<array{name: string, normalized: string, side: ?string, companies: int, share: int, sub_class: string, votes: array<string, int>}>
     */
    public function nameGroups(iterable $rows, bool $withSide): array
    {
        $generic = array_map([ImportNormalizer::class, 'text'], config('classification.import.name_layer.generic_names', []));
        $groups  = [];
        foreach ($rows as $row) {
            if ($row->sub_class === null || ($withSide && $row->side === null) || in_array(ImportNormalizer::text($row->account_name), $generic, true)) {
                continue;
            }
            $g = ClassificationKey::name($row->account_name).($withSide ? '|'.$row->side : '');
            $groups[$g]['name'] ??= $row->account_name;
            $groups[$g]['side'] = $withSide ? $row->side : null;
            $groups[$g]['companies'][(string) $row->company][$row->sub_class] = ($groups[$g]['companies'][(string) $row->company][$row->sub_class] ?? 0) + 1;
        }

        $out = [];
        foreach ($groups as $g) {
            // votes: companies using each sub-class; a company that uses two sub-classes for the name agrees with neither.
            $votes = [];
            foreach ($g['companies'] as $subs) {
                foreach (array_keys($subs) as $sub) {
                    $votes[$sub] = ($votes[$sub] ?? 0) + 1;
                }
            }
            arsort($votes);
            $top       = array_key_first($votes);
            $companies = count($g['companies']);
            $agree     = count(array_filter($g['companies'], fn ($subs) => array_keys($subs) === [$top]));
            $out[] = [
                'name' => $g['name'], 'normalized' => ClassificationKey::name($g['name']), 'side' => $g['side'], 'companies' => $companies,
                'share' => (int) floor(100 * $agree / $companies), 'sub_class' => $top, 'votes' => $votes,
            ];
        }

        return $out;
    }

    /** Whether a name group is trusted by the layer (enough companies, enough agreement). */
    public static function usable(array $group): bool
    {
        return $group['companies'] >= (int) config('classification.import.name_layer.min_companies', 2)
            && $group['share'] >= (int) config('classification.import.name_layer.min_share', 80);
    }

    public static function conflicting(array $group): bool
    {
        return $group['companies'] >= (int) config('classification.import.name_layer.min_companies', 2) && ! self::usable($group);
    }

    /**
     * Rebuilds the layer from the applied rows of every import (run again after changing the thresholds). Entries brought
     * by a JSON export stay, unless the local rows now decide the same name and side. @return int entries from rows
     */
    public function rebuildNameLayer(): int
    {
        $rows    = ClassificationImportRow::where('applied', true)->whereNotNull('sub_class')->get();
        $entries = [];
        foreach (array_filter($this->nameGroups($rows, true), [self::class, 'usable']) as $g) {
            $entries[] = [
                'name_hash' => sha1($g['normalized']), 'side' => $g['side'], 'normalized_name' => $g['normalized'], 'original_name' => $g['name'],
                'sub_class' => $g['sub_class'], 'companies' => $g['companies'], 'share' => $g['share'], 'origin' => 'rows',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        ClassificationNameLayerEntry::where('origin', 'rows')->delete();
        foreach (array_chunk($entries, 500) as $chunk) {
            ClassificationNameLayerEntry::upsert($chunk, ['name_hash', 'side'], ['normalized_name', 'original_name', 'sub_class', 'companies', 'share', 'origin', 'updated_at']);
        }

        return count($entries);
    }

    /* ---------- report ---------- */

    /** Each policy: its mode, rows, and how many rows would change sub-class if it were switched. */
    private function openDecisions($mappings): array
    {
        $out = [];
        foreach (config('classification.import.policies', []) as $name => $policy) {
            $notes = $mappings->where('kind', Mapping::NOTE)->where('policy', $name)->where('decision', 'map');
            $other = $policy['mode'] === 'function' ? 'nature' : 'function';
            $out[] = [
                'policy' => $name, 'label' => $policy['label'], 'decision' => $policy['decision'], 'mode' => $policy['mode'],
                'rows' => $notes->sum('rows'),
                'rows_changing_if_switched_to_'.$other => $notes->filter(fn ($m) => $m->sub_class !== $m->function_sub_class)->sum('rows'),
                'switch' => self::switchText($name, $other)->ar(),
                'by_function' => $notes->groupBy('function_sub_class')->map->sum('rows')->sortDesc()->all(),
                'by_nature'   => $notes->groupBy('sub_class')->map->sum('rows')->sortDesc()->all(),
            ];
        }

        return $out;
    }

    public function report(ClassificationImport $import): array
    {
        $rows     = $import->rows()->get();
        $mappings = $import->mappings()->get()->keyBy('id');
        $mapped   = $rows->whereNotNull('sub_class')->whereNotIn('skip_reason', ['key_conflict', 'cache_has_entry']);

        $skipped = [];
        foreach ($rows->whereNotNull('skip_reason')->groupBy('skip_reason') as $reason => $list) {
            $skipped[$reason] = [
                'label' => self::SKIP_REASONS[$reason] ?? $reason,
                'rows'  => $list->count(),
                'examples' => $list->take(5)->map(fn ($r) => ['row' => $r->row, 'company' => $r->company, 'account_name' => $r->account_name, 'budget_value' => $r->budget_value, 'note_value' => $r->note_value])->values()->all(),
            ];
        }

        // Every distinct الميزانية value as written, and what it maps to.
        $budgetValues = $rows->whereNotNull('budget_mapping_id')->groupBy('budget_value')->map(function ($list, $value) use ($mappings) {
            $m = $mappings[$list->first()->budget_mapping_id];

            return ['value' => $value, 'rows' => $list->count(), 'mapping_id' => $m->id, 'decision' => $m->decision, 'main_class' => $m->main_class,
                'sub_class' => $m->sub_class, 'method' => $m->method, 'confidence' => $m->confidence, 'flag' => $m->flag, 'flag_phrase' => $m->flag_phrase, 'reviewed' => $m->reviewed];
        })->sortByDesc('rows')->values()->all();

        $notes = $mappings->where('kind', Mapping::NOTE);

        // Name layer preview: the other imports' applied rows and this import's mapped rows, by name only (before) and by name + side (after).
        $pool = ClassificationImportRow::where('import_id', '!=', $import->id)->where('applied', true)->whereNotNull('sub_class')->get()
            ->concat($rows->whereNotNull('sub_class'));
        $before = $this->nameGroups($pool, false);
        $after  = $this->nameGroups($pool, true);
        $conflicts = array_values(array_filter($after, [self::class, 'conflicting']));
        usort($conflicts, fn ($a, $b) => $b['companies'] <=> $a['companies']);
        $min = ClassificationCache::minConfidence();

        return [
            'import_id' => $import->id,
            'status'    => $import->status,
            'file_name' => $import->file_name,
            'rows' => [
                'total'          => $rows->count(),
                'companies'      => $rows->whereNotNull('company')->pluck('company')->unique()->count(),
                'mapped'         => $mapped->count(),
                'approved'       => $mapped->where('approved', true)->count(),      // written by «تطبيق» (cumulative)
                'pending_review' => $mapped->where('approved', false)->count(),
                'applied'        => $rows->where('applied', true)->count(),        // every row the last «تطبيق» went through, kept ones included
                'ready'          => $mapped->where('approved', true)->where('applied', false)->count(),   // approved, not written yet
                'written'        => $mapped->where('approved', true)->where('applied', true)->count(),    // approved and written
                'kept_existing'  => $rows->where('applied', true)->whereIn('skip_reason', ['key_conflict', 'cache_has_entry'])->count(),
                'cache_entries'  => ClassificationCacheEntry::where('import_id', $import->id)->count(),
                'skipped'        => $rows->whereNotNull('skip_reason')->count(),
            ],
            'skipped' => $skipped,
            'budget_mapping' => [
                'distinct_values'     => count($budgetValues),
                'distinct_normalized' => $mappings->where('kind', Mapping::BUDGET)->count(),
                'by_method'           => $mappings->where('kind', Mapping::BUDGET)->countBy('method')->all(),
                'by_decision'         => $mappings->where('kind', Mapping::BUDGET)->countBy('decision')->all(),
                'values'              => $budgetValues,
            ],
            'note_mapping' => [
                'distinct_pairs' => $notes->count(),
                'by_method'      => $notes->countBy('method')->all(),
                'unmapped'       => $notes->where('decision', 'skip')->count(),
                'unmapped_rows'  => $notes->where('decision', 'skip')->sum('rows'),
            ],
            'review' => [
                'mappings'            => $mappings->where('rows', '>', 0)->count(),
                'reviewed'            => $mappings->where('rows', '>', 0)->where('reviewed', true)->count(),
                'flagged_unreviewed'  => ($flagged = $mappings->whereNotNull('flag')->where('reviewed', false)->where('rows', '>', 0))->count(),
                'flagged_rows'        => $flagged->sum('rows'),
                'flagged_top'         => $flagged->sortByDesc('rows')->take(20)->values()
                    ->map(fn ($m) => ['mapping_id' => $m->id, 'kind' => $m->kind, 'value' => $m->value, 'sub_class' => $m->sub_class, 'flag' => $m->flag, 'flag_phrase' => $m->flag_phrase, 'rows' => $m->rows])->all(),
                'pending_below_cutoff' => $mapped->where('approved', false)->where('confidence', '<', $min)->count(),
                'cutoff'               => $min,
            ],
            'open_decisions' => $this->openDecisions($mappings),
            'name_layer' => [
                'min_share'            => (int) config('classification.import.name_layer.min_share', 80),
                'min_companies'        => (int) config('classification.import.name_layer.min_companies', 2),
                'names'                => count($before),
                'usable_by_name'       => count(array_filter($before, [self::class, 'usable'])),
                'usable_by_name_side'  => count(array_filter($after, [self::class, 'usable'])),
                'conflicts_before'     => count(array_filter($before, [self::class, 'conflicting'])),
                'conflicts_after'      => count($conflicts),
                'conflicts'            => array_map(fn ($g) => array_intersect_key($g, array_flip(['name', 'side', 'companies', 'share', 'votes'])), array_slice($conflicts, 0, 100)),
            ],
        ];
    }

    /** How to switch a policy: its setting, then «تطبيق» again. */
    public static function switchText(string $policy, string $other): Phrase
    {
        return self::m('switch', ['setting' => 'CLASSIFIER_IMPORT_'.strtoupper($policy)."={$other}"]);
    }

    /** Records the provider and model the import's mapping asks (the first AI call of the import). */
    private function usesAi(ClassificationImport $import): void
    {
        $import->provider ?? $import->update(['provider' => Provider::name(), 'model' => Provider::model()]);
    }
}
