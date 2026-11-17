<?php

namespace App\Services;

use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationImport;
use App\Models\ClassificationImportMapping as Mapping;
use App\Models\ClassificationNameLayerEntry;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Support\ClassificationKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Exceptions\LocalizedException;
use App\Support\Phrase;

/**
 * Moves the classification cache between instances: an Excel export for the accountant, a JSON export with everything
 * (entries, the الميزانية / الإيضاحات mappings, the name layer), and the import of that JSON (no LLM, same priorities).
 */
class CacheTransfer
{
    public const FORMAT  = 'classification-cache-export';
    public const VERSION = 1;

    /** Who wins when an incoming entry meets an existing one with the same key: the higher rank; a manual correction is never replaced. */
    private const RANK = [ClassificationCache::MANUAL => 3, ClassificationCache::AI => 2, ClassificationCache::IMPORTED => 1];

    public const SOURCE_LABELS = [
        ClassificationCache::AI       => 'ذكاء اصطناعي',
        ClassificationCache::MANUAL   => 'تصحيح يدوي',
        ClassificationCache::IMPORTED => 'مستورد من ملفات المحاسبين',
    ];

    private const ENTRY_FIELDS = [
        'key_hash', 'name_hash', 'normalized_key', 'original_name', 'account_code', 'budget_item', 'row_type', 'main_class', 'sub_class',
        'source', 'confidence', 'reason', 'taxonomy_version', 'hit_count', 'side', 'side_locked', 'created_at', 'updated_at',
    ];

    private const MAPPING_FIELDS = [
        'kind', 'value', 'normalized', 'parent', 'decision', 'main_class', 'sub_class', 'function_sub_class', 'policy', 'method', 'confidence',
        'reason', 'flag', 'reviewed', 'updated_at',
    ];

    public function __construct(private TaxonomyRepositoryInterface $taxonomy) {}

    /** @param  array{source?: ?string, from?: ?string, to?: ?string}  $filters  from / to: dates (Y-m-d), on the last update */
    public function entries(array $filters): Builder
    {
        return ClassificationCacheEntry::query()
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('updated_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('updated_at', '<=', Carbon::parse($d)->endOfDay()))
            ->orderBy('source')->orderBy('original_name');
    }

    /* ---------- JSON ---------- */

    public function exportJson(array $filters): array
    {
        $mappings = fn (string $kind) => $this->latestMappings($kind)->map(fn (Mapping $m) => array_intersect_key($m->toArray(), array_flip(self::MAPPING_FIELDS)))->values()->all();

        return [
            'format'              => self::FORMAT,
            'format_version'      => self::VERSION,
            'exported_at'         => now()->toIso8601String(),
            'taxonomy_version'    => ClassificationCache::version(),
            'taxonomy_subclasses' => $this->taxonomy->subClasses(),
            'filters'             => (object) array_filter($filters),   // an object even when empty
            'cache_entries'       => $this->entries($filters)->get()->map(fn ($e) => array_intersect_key($e->toArray(), array_flip(self::ENTRY_FIELDS)))->all(),
            'budget_mappings'     => $mappings(Mapping::BUDGET),
            'note_mappings'       => $mappings(Mapping::NOTE),
            'name_layer'          => ClassificationNameLayerEntry::orderBy('normalized_name')->get()
                ->map(fn ($e) => $e->only(['side', 'normalized_name', 'original_name', 'sub_class', 'companies', 'share']))->all(),
        ];
    }

    /** One mapping per value across every import: the approved one first, then the newest. */
    private function latestMappings(string $kind)
    {
        return Mapping::where('kind', $kind)->orderBy('reviewed')->orderBy('updated_at')->orderBy('id')->get()
            ->keyBy(fn (Mapping $m) => $m->parent.'|'.$m->normalized)->sortBy('normalized');
    }

    /**
     * Imports a JSON export: entries by the priority rules, mappings as an «export» import (reused by later uploads),
     * name-layer entries where this instance has none. No LLM call.
     */
    public function importJson(array $data, string $fileName): array
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new LocalizedException(Phrase::make('classification_messages.transfer.not_export'));
        }
        if ((int) ($data['format_version'] ?? 0) > self::VERSION) {
            throw new LocalizedException(Phrase::make('classification_messages.transfer.newer_version', ['version' => (int) $data['format_version']]));
        }

        return DB::transaction(function () use ($data, $fileName) {
            $report = ['entries' => $this->importEntries($data['cache_entries'] ?? [])];
            $report += $this->importMappings($data, $fileName);
            $report['name_layer'] = $this->importNameLayer($data['name_layer'] ?? []);
            $report['taxonomy_version'] = ['export' => (string) ($data['taxonomy_version'] ?? ''), 'here' => ClassificationCache::version()];

            return $report;
        });
    }

    private function importEntries(array $entries): array
    {
        $out = ['added' => 0, 'updated' => 0, 'kept_manual' => 0, 'kept_existing' => 0, 'skipped_unknown_sub_class' => [], 'skipped_invalid' => 0,
            'ai_other_taxonomy_version' => 0];
        $existing = [];
        foreach (array_chunk(array_column($entries, 'key_hash'), 500) as $hashes) {
            $existing += ClassificationCacheEntry::whereIn('key_hash', $hashes)->get()->keyBy('key_hash')->all();
        }

        foreach ($entries as $e) {
            $key = (string) ($e['normalized_key'] ?? '');
            if ($key === '' || ($e['key_hash'] ?? null) !== ClassificationKey::hash($key) || ! isset(self::RANK[$e['source'] ?? ''])) {
                $out['skipped_invalid']++;
                continue;
            }
            // A class renamed since the export (here or there) arrives under a former name: its current name.
            $e['sub_class'] = $e['sub_class'] === null ? null : ($this->taxonomy->resolveSub((string) $e['sub_class']) ?? $e['sub_class']);
            if ($e['sub_class'] !== null && ! $this->taxonomy->find((string) $e['sub_class'])) {
                $out['skipped_unknown_sub_class'][] = ['name' => $e['original_name'] ?? '', 'sub_class' => $e['sub_class'], 'source' => $e['source']];
                continue;
            }
            $current = $existing[$e['key_hash']] ?? null;
            if ($current) {
                if ($current->source === ClassificationCache::MANUAL) {
                    $out['kept_manual']++;
                    continue;
                }
                $incoming = self::RANK[$e['source']];
                $here     = self::RANK[$current->source] ?? 0;
                $newer    = Carbon::parse($e['updated_at'] ?? '1970-01-01')->gt($current->updated_at);
                if ($incoming < $here || ($incoming === $here && ! $newer)) {
                    $out['kept_existing']++;
                    continue;
                }
            }
            $values = array_intersect_key($e, array_flip(self::ENTRY_FIELDS)) + ['hit_count' => 0];
            // Stored as this instance stores it: the base class of a side pair (an older export may hold the resolved one).
            empty($values['side_locked']) && $values['sub_class'] = ClassificationCache::baseClass($values['sub_class'], $values['original_name'] ?? '');
            $values['base_hash']  = ClassificationKey::baseHash($key);
            $values['main_class'] = $values['sub_class'] !== null ? ($this->taxonomy->find($values['sub_class'])[1] ?? null) : null;   // this taxonomy's
            $values['created_at'] = Carbon::parse($values['created_at'] ?? now());
            $values['updated_at'] = Carbon::parse($values['updated_at'] ?? now());
            $values['import_id']  = null;
            $model = $current ?? new ClassificationCacheEntry;
            $model->timestamps = false;
            $model->forceFill($values)->save();
            $current ? $out['updated']++ : $out['added']++;
            if ($e['source'] === ClassificationCache::AI && (string) $e['taxonomy_version'] !== ClassificationCache::version()) {
                $out['ai_other_taxonomy_version']++;   // stored, but the lookup ignores it until the versions match
            }
        }
        $out['skipped_unknown_sub_class_count'] = count($out['skipped_unknown_sub_class']);

        return $out;
    }

    private function importMappings(array $data, string $fileName): array
    {
        $import = ClassificationImport::create(['file_name' => $fileName, 'status' => ClassificationImport::APPLIED, 'kind' => ClassificationImport::EXPORT, 'applied_at' => now()]);
        $mains  = array_flip($this->taxonomy->mainClasses());
        $out    = [];
        foreach ([Mapping::BUDGET => 'budget_mappings', Mapping::NOTE => 'note_mappings'] as $kind => $field) {
            $stored = 0;
            $unknown = [];
            foreach ($data[$field] ?? [] as $m) {
                foreach (['sub_class', 'function_sub_class'] as $f) {
                    isset($m[$f]) && $m[$f] = $this->taxonomy->resolveSub((string) $m[$f]) ?? $m[$f];
                }
                foreach (['main_class', 'parent'] as $f) {
                    isset($m[$f]) && $m[$f] = $this->taxonomy->resolveMain((string) $m[$f]) ?? $m[$f];
                }
                $bad = ($m['main_class'] ?? null) !== null && ! isset($mains[$m['main_class']]) && ($m['kind'] ?? $kind) === Mapping::BUDGET
                    || array_filter([$m['sub_class'] ?? null, $m['function_sub_class'] ?? null], fn ($s) => $s !== null && ! $this->taxonomy->find($s));
                if ($bad) {
                    $unknown[] = $m['value'] ?? '';
                    continue;
                }
                Mapping::create(array_intersect_key($m, array_flip(self::MAPPING_FIELDS)) + ['kind' => $kind, 'import_id' => $import->id, 'rows' => 0]);
                $stored++;
            }
            $out[$field] = ['stored' => $stored, 'skipped_unknown_class' => $unknown];
        }
        $import->update(['report' => ['kind' => 'export'] + $out]);
        $out['import_id'] = $import->id;

        return $out;
    }

    private function importNameLayer(array $entries): array
    {
        $out = ['added' => 0, 'kept_existing' => 0, 'skipped_unknown_sub_class' => 0];
        foreach ($entries as $e) {
            $e['sub_class'] = $this->taxonomy->resolveSub((string) ($e['sub_class'] ?? '')) ?? ($e['sub_class'] ?? null);
            if (! $this->taxonomy->find((string) ($e['sub_class'] ?? ''))) {
                $out['skipped_unknown_sub_class']++;
                continue;
            }
            $hash = sha1((string) $e['normalized_name']);
            if (ClassificationNameLayerEntry::where('name_hash', $hash)->where('side', $e['side'])->exists()) {
                $out['kept_existing']++;   // this instance's own rows decide
                continue;
            }
            ClassificationNameLayerEntry::create([
                'name_hash' => $hash, 'side' => $e['side'], 'normalized_name' => $e['normalized_name'], 'original_name' => $e['original_name'],
                'sub_class' => $e['sub_class'], 'companies' => (int) $e['companies'], 'share' => (int) $e['share'], 'origin' => 'export',
            ]);
            $out['added']++;
        }

        return $out;
    }

    /* ---------- Excel ---------- */

    /** @return string path of a temporary .xlsx */
    public function exportXlsx(array $filters): string
    {
        $book  = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('ذاكرة التصنيف');
        $rows = [['اسم الحساب', 'كود الحساب', 'بند الميزانية', 'القائمة المالية', 'التصنيف الرئيسي', 'التصنيف الفرعي', 'المصدر', 'الثقة', 'مرات الاستخدام', 'آخر تعديل']];
        foreach ($this->entries($filters)->where('row_type', 'account')->cursor() as $e) {
            $tax    = $e->sub_class ? $this->taxonomy->find($e->sub_class) : null;
            $rows[] = [$e->original_name, $e->account_code, $e->budget_item, $tax[0] ?? null, $tax[1] ?? $e->main_class, $e->sub_class,
                self::SOURCE_LABELS[$e->source] ?? $e->source, $e->confidence, $e->hit_count, $e->updated_at?->format('Y-m-d H:i')];
        }
        $this->fill($sheet, $rows);
        $this->codesAsText($sheet, array_column(array_slice($rows, 1), 1));

        $layer = $book->createSheet();
        $layer->setTitle('طبقة الأسماء');
        $rows = [['اسم الحساب', 'اتجاه الرصيد', 'التصنيف الفرعي', 'عدد الشركات', 'نسبة الاتفاق %', 'المصدر']];
        foreach (ClassificationNameLayerEntry::orderBy('normalized_name')->get() as $e) {
            $rows[] = [$e->original_name, $e->side === 'debit' ? 'مدين' : 'دائن', $e->sub_class, $e->companies, $e->share,
                $e->origin === 'export' ? 'ملف تصدير' : 'ملفات مستوردة'];
        }
        $this->fill($layer, $rows);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'cache-export').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function fill($sheet, array $rows): void
    {
        $sheet->setRightToLeft(true);
        $sheet->fromArray($rows, null, 'A1', true);
        $last = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}".max(1, count($rows)));
        foreach (range('A', $last) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /** Account codes stay text (leading zeros): the default binder would turn «0110» into 110. */
    private function codesAsText($sheet, array $codes): void
    {
        foreach ($codes as $i => $code) {
            $code !== null && $sheet->setCellValueExplicit('B'.($i + 2), (string) $code, DataType::TYPE_STRING);
        }
    }
}
