<?php

namespace App\Services;

use App\Models\ClassificationCacheEntry;
use App\Models\ClassificationNameLayerEntry;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Support\ClassificationKey;
use App\Support\ImportNormalizer;
use App\Support\Phrase;
use Closure;
use Illuminate\Support\Facades\Schema;

/**
 * Rows seen before are labelled from the DB instead of the AI: a manual correction first, then an AI answer of the
 * current taxonomy version with enough confidence; anything else goes to the AI and its good answers are stored.
 */
class ClassificationCache
{
    public const AI     = 'ai';
    public const MANUAL = 'manual_correction';
    public const IMPORTED   = 'imported';        // accountant-labelled files (CacheImport)
    public const NAME_LAYER = 'imported_name';   // a row's source only: the name-only layer of the imports
    public const IMPORTED_CODE = 'imported_code';   // a row's source only: an imported entry found by code + name alone

    /** classification_reason of a cached row: this prefix + the AI's reason when it was stored. */
    public const CACHED_PREFIX = 'من ذاكرة التصنيف (إجابة سابقة للنظام): ';
    public const MANUAL_REASON = 'تصحيح يدوي محفوظ من مراجعة سابقة';
    public const IMPORTED_PREFIX = 'من بيانات مصنفة يدويًا تم استيرادها: ';
    public const IMPORTED_CODE_PREFIX = 'من بيانات مصنفة يدويًا تم استيرادها (الكود والاسم نفسهما، بدون بند الميزانية): ';

    /** Row types that become the parent of the generic rows below them. */
    private const HEADER_TYPES = ['parent', 'section'];

    /** The lookup, in order (:version / :min come from config). Shown by the explain API. */
    public const STEPS = [
        ['source' => self::MANUAL, 'title' => 'تصحيح يدوي',
            'description' => 'إذا سبق تصحيح هذا الحساب (بنفس مفتاح البحث) يدويًا، يُستخدم التصحيح مباشرة. وهو أعلى أولوية ويتغلب على أي إجابة من الذكاء الاصطناعي، '
                .'ويبقى ساريًا حتى لو تغيرت نسخة شجرة التصنيف ما دام التصنيف الفرعي موجودًا فيها. ويؤخذ التصنيف الرئيسي من الشجرة الحالية بحسب التصنيف الفرعي.'],
        ['source' => self::AI, 'title' => 'إجابة سابقة من الذكاء الاصطناعي',
            'description' => 'إذا لم يوجد تصحيح يدوي، تُستخدم إجابة الذكاء الاصطناعي المحفوظة لنفس المفتاح، بشرط أن تكون من نسخة شجرة التصنيف الحالية (:version) ودرجة ثقتها :min أو أكثر.'],
        ['source' => self::IMPORTED, 'title' => 'تصنيف مستورد من ملفات المحاسبين',
            'description' => 'إذا لم يوجد تصحيح يدوي ولا إجابة ذكاء اصطناعي لنفس المفتاح، يُستخدم تصنيف المحاسب من ملف مستورد بعد أن يراجع المحاسب الربط. '
                .'ولا يحل الاستيراد محل تصحيح يدوي أو إجابة ذكاء اصطناعي، بينما تحل إجابة الذكاء الاصطناعي الجديدة محله.'],
        ['source' => self::IMPORTED_CODE, 'title' => 'تصنيف مستورد بالكود والاسم فقط',
            'description' => 'إذا لم يوجد سجل للمفتاح كاملًا، يُبحث عن التصنيفات المستوردة بنفس كود الحساب واسمه دون بند الميزانية '
                .'(ميزان العميل غالبًا لا يحتوي على عمود الميزانية الموجود في ملفات المحاسبين). ويُستخدم فقط إذا اتفقت جميع السجلات المستوردة لهذا الكود والاسم على تصنيف فرعي واحد.'],
        ['source' => self::NAME_LAYER, 'title' => 'اسم الحساب من الملفات المستوردة',
            'description' => 'إذا لم يوجد أي سجل للمفتاح، يُبحث عن اسم الحساب وحده مع اتجاه الرصيد الختامي (مدين/دائن) في الملفات المستوردة: '
                .'ويُستخدم فقط إذا صنّفته :companies شركات أو أكثر، واتفق :share% منها أو أكثر على نفس التصنيف الفرعي. ولا تؤخذ الأسماء العامة (أخرى، متنوعة…) بالاسم مطلقًا.'],
        ['source' => 'llm', 'title' => 'سؤال الذكاء الاصطناعي',
            'description' => 'إذا لم يتوافر أي مما سبق، يُرسل الصف إلى الذكاء الاصطناعي ومعه الصفوف المجاورة له واسم الحساب الرئيسي وعنوان القسم الذي يعلوه كسياق للقراءة فقط '
                .'(حتى لو كانت هذه الصفوف قادمة من الذاكرة). وبعد الرد، تُخزَّن الإجابة التي اجتازت التحقق ودرجة ثقتها :min أو أكثر للمرة القادمة.'],
    ];

    /** After the lookup, whatever the source. */
    public const AFTER = 'تُطبَّق جميع الفحوصات الرقمية (توازن الأرصدة، الأرصدة الشاذة، مقارنة تصنيف الملف، أرقام الحسابات المكررة) على جميع الصفوف، حتى التي جاء تصنيفها من الذاكرة. '
        .'أما التصنيفات التي تعتمد على اتجاه الرصيد (العُهد والأمانات، ضريبة القيمة المضافة، العملاء ودفعاتهم المقدمة، الموردين ودفعاتهم المقدمة) '
        .'فتُحدَّد من جديد في كل تصنيف بحسب رصيد الصف (الختامي، فإن كان صفرًا فالافتتاحي، فإن كان صفرًا فصافي الحركة)، عدا التصحيح اليدوي الذي اختار عكس الرصيد عن قصد.';

    /**
     * Row flags that keep an AI answer out of the cache: a classification problem (needs a review first), or a sign the
     * answer is wrong (balance on the wrong side, a parent counted as an account, a number from another family).
     */
    public const NOT_STORED_FLAGS = [
        'statement_mismatch', 'classification_mismatch', 'inconsistent_classification',
        'abnormal_opening', 'abnormal_closing', 'cash_credit', 'possible_parent', 'prefix_mismatch', 'parent_level', 'probable_parent',
    ];

    /** Never stored (mirrors store()). */
    public const NOT_CACHED = [
        'إجابة الذكاء الاصطناعي التي درجة ثقتها أقل من :min.',
        'صف عليه تنبيه بأن تصنيفه يختلف عن تصنيف الملف (statement_mismatch أو classification_mismatch): يحتاج إلى مراجعة أولًا.',
        'حساب صُنّف بأكثر من تصنيف في الملف نفسه وتم توحيده (inconsistent_classification).',
        'صف رصيده (الافتتاحي أو الختامي) على عكس طبيعة التصنيف (abnormal_opening / abnormal_closing).',
        'صف يبدو حسابًا تجميعيًا رصيده يساوي مجموع الحسابات التي تحته (possible_parent)، أو تشير مستويات الملف إلى أنه تجميعي (parent_level)، أو تساوي أرصدته مجموع الصفوف التي تحته (probable_parent).',
        'صف أول رقم في كود حسابه تابع لنوع حسابات آخر في الملف نفسه (prefix_mismatch).',
        'ورد المفتاح نفسه بتصنيفين مختلفين في الملف نفسه.',
        'صف لم يصنّفه الذكاء الاصطناعي، أو فشلت الدفعة الخاصة به.',
        'صف بلا اسم، أو باسم عام (دون كود ولا بند ميزانية) والحساب الرئيسي الذي يعلوه غير معروف.',
        'صفوف الإجمالي التي تم التعرف عليها من معادلات إكسل (SUBTOTAL) وليس من الذكاء الاصطناعي.',
        'إجابة الذكاء الاصطناعي لا تحل محل تصحيح يدوي أبدًا.',
    ];

    public function __construct(private TaxonomyRepositoryInterface $taxonomy) {}

    public static function enabled(): bool
    {
        return (bool) config('classification.cache.enabled', true);
    }

    public static function minConfidence(): int
    {
        return (int) config('classification.cache.min_confidence', 90);
    }

    public static function version(): string
    {
        return (string) config('classification.cache.taxonomy_version', '1');
    }

    /** [code, name, budget item] of an extracted row. */
    private static function fields(array $extracted): array
    {
        return [$extracted['account']['account_number'], $extracted['account']['account_name'], $extracted['existing'] ?? null];
    }

    /**
     * Keys of the rows, in file order. A generic row takes the nearest parent/section row above it; after a named row
     * of unknown type, generic rows get no key until the next known parent/section.
     *
     * @param  Closure(int $row, ?string $key): ?string  $rowType  the row's type, null when unknown
     * @return array<int, ?string>
     */
    public static function keys(array $extracted, Closure $rowType): array
    {
        $parent = '';   // nothing above the first row
        $keys   = [];
        foreach ($extracted as $n => $e) {
            [$code, $name, $budget] = self::fields($e);
            $keys[$n] = ClassificationKey::make($code, $name, $budget, $parent);
            if (ClassificationKey::name($name) === '') {
                continue;   // a row without a name is never a parent
            }
            $type   = $rowType($n, $keys[$n]);
            $parent = $type === null ? null : (in_array($type, self::HEADER_TYPES, true) ? $name : $parent);
        }
        return $keys;
    }

    /**
     * Labels of the rows answered from the cache (the same shape as validated AI labels) and their source.
     *
     * @return array{labels: array<int, array>, sources: array<int, string>}
     */
    public function lookup(array $extracted): array
    {
        if (! self::enabled()) {
            return ['labels' => [], 'sources' => [], 'flips' => []];
        }

        // One query: rows with a code or budget item by their key, generic rows (whose key needs the parent, known
        // only while walking the file) by their name; generic keys are then matched in memory.
        $keys  = [];
        $names = [];
        foreach ($extracted as $e) {
            [$code, $name, $budget] = self::fields($e);
            if (ClassificationKey::isGeneric($code, $budget)) {
                ClassificationKey::name($name) !== '' && $names[] = ClassificationKey::nameHash($name);
            } elseif ($key = ClassificationKey::make($code, $name, $budget, null)) {
                $keys[] = ClassificationKey::hash($key);
            }
        }
        $entries = $this->fetch($keys, $names);
        $byCode  = $this->importedByCode($extracted);
        $layer   = $this->nameLayer($extracted);

        $labels = [];
        $sources = [];
        $used    = [];   // entry id => rows it answered
        $usedLayer = [];
        $locked    = [];   // rows answered by a manual correction locked against the side
        self::keys($extracted, function (int $n, ?string $key) use ($entries, $byCode, $layer, $extracted, &$labels, &$sources, &$used, &$usedLayer, &$locked) {
            $entry = $key === null ? null : ($entries[ClassificationKey::hash($key)] ?? null);
            $label = $entry ? $this->label($entry, $n) : null;
            $source = $entry?->source;
            if (! $label && ($base = ClassificationKey::baseHash($key)) !== null && ($entry = $byCode[$base] ?? null)) {
                // The full key missed: imported entries with the same code + name, when they agree on one sub-class.
                $label  = $this->label($entry, $n);
                $label && $label = self::withReason($label, Phrase::make('classification_messages.cache_reasons.imported_code', ['reason' => (string) $entry->reason]));
                $source = self::IMPORTED_CODE;
            }
            if (! $label) {
                // The key missed: the name-only layer, by name and closing balance side.
                $side = self::side($extracted[$n]['account']);
                $hit  = $side ? ($layer[ClassificationKey::nameHash($extracted[$n]['account']['account_name']).'|'.$side] ?? null) : null;
                if (! $hit || ! $this->taxonomy->find($hit->sub_class)) {
                    return null;
                }
                $labels[$n]  = self::withReason([
                    'row' => $n, 'row_type' => 'account', 'sub_class' => $hit->sub_class, 'confidence' => $hit->share,
                ], Phrase::make('classification_messages.cache_reasons.name_layer', ['companies' => $hit->companies, 'share' => $hit->share]));
                $sources[$n] = self::NAME_LAYER;
                $usedLayer[$hit->id] = ($usedLayer[$hit->id] ?? 0) + 1;

                return 'account';
            }
            $labels[$n]  = $label;
            $sources[$n] = $source;
            $used[$entry->id] = ($used[$entry->id] ?? 0) + 1;
            $entry->side_locked && $locked[$n] = true;

            return $label['row_type'];
        });

        // Side-dependent sub-classes are never returned as stored: the row's balance side re-decides within the pair.
        [$labels, $flips] = self::applyFlips($labels, $extracted, $locked);

        $byCount = [];
        foreach ($used as $id => $count) {
            $byCount[$count][] = $id;
        }
        foreach ($byCount as $count => $ids) {
            ClassificationCacheEntry::whereKey($ids)->toBase()->increment('hit_count', $count);   // updated_at stays the last edit
        }
        foreach ($usedLayer as $id => $count) {
            ClassificationNameLayerEntry::whereKey($id)->toBase()->increment('hit_count', $count);
        }

        return ['labels' => $labels, 'sources' => $sources, 'flips' => $flips];
    }

    /**
     * Re-decides side-pair members by each row's balance side (flip), for cached and fresh AI answers alike.
     *
     * @param array<int, array> $labels row => label
     * @param array<int, true> $locked rows whose class the user locked against flips
     * @return array{0: array<int, array>, 1: array<int, array>} the labels, and row => flip
     */
    public static function applyFlips(array $labels, array $extracted, array $locked = []): array
    {
        $flips = [];
        foreach ($labels as $n => &$label) {
            if (($label['row_type'] ?? null) !== 'account' || isset($locked[$n]) || ! ($flip = self::flip($label['sub_class'] ?? null, $extracted[$n]['account']))) {
                continue;
            }
            $flips[$n] = $flip;
            // The reason stays the answer's own: the row's side_flip flag says what the flip did (once, in the note).
            if ($flip['to'] !== null) {
                $label['sub_class'] = $flip['to'];
            }
        }
        unset($label);

        return [$labels, $flips];
    }

    /**
     * The row's balance side for the side pairs: the closing balance's; when it is zero, the opening balance's, then the
     * period's net movement; null when all are zero.
     */
    public static function rowSide(array $account): ?string
    {
        foreach (['closing', 'opening', 'period'] as $g) {
            if ($side = self::side(['closing_debit' => $account["{$g}_debit"] ?? 0, 'closing_credit' => $account["{$g}_credit"] ?? 0])) {
                return $side;
            }
        }

        return null;
    }

    /**
     * The enabled side pair holding a sub-class, and the side its member sits on.
     *
     * @return array{0: string, 1: array, 2: string}|null [pair name, pair, debit|credit]
     */
    public static function pairOf(?string $subClass): ?array
    {
        foreach (config('classification.side_pairs', []) as $name => $pair) {
            if (! ($pair['enabled'] ?? false)) {
                continue;
            }
            foreach (['debit', 'credit'] as $side) {
                if (in_array($subClass, $pair[$side], true)) {
                    return [$name, $pair, $side];
                }
            }
        }

        return null;
    }

    /**
     * What the row's side does to a cached sub-class of a pair: flips it to the other member (to = the member), or, when the
     * pair flips only some names (only_names) and this one is not among them, keeps it (to = null) with a review flag.
     *
     * @return array{pair: string, label: string, side: string, from: string, to: ?string, flag: bool}|null null: the side agrees
     */
    public static function flip(?string $subClass, array $account): ?array
    {
        $side = self::rowSide($account);

        return $side ? self::resolve($subClass, $side, (string) ($account['account_name'] ?? '')) : null;
    }

    /**
     * The sub-class the cache stores: for a side-pair member, the member it resolves to on the pair's base side (config
     * side_pairs.*.base), so an entry holds the account's kind, not the side its balance had once; the lookup re-decides it
     * by the row's own side. Anything else (and a member the pair may not flip, e.g. a non-VAT government receivable) as is.
     */
    public static function baseClass(?string $subClass, ?string $name): ?string
    {
        $found = self::pairOf($subClass);
        if (! $found) {
            return $subClass;
        }

        return self::resolve($subClass, $found[1]['base'] ?? 'debit', (string) $name)['to'] ?? $subClass;
    }

    /** What putting a pair member on $side does to it (see flip); null when it already sits on that side. */
    private static function resolve(?string $subClass, string $side, string $text): ?array
    {
        if (! ($found = self::pairOf($subClass)) || $found[2] === $side) {
            return null;
        }
        [$name, $pair] = $found;
        $out  = ['pair' => $name, 'label' => self::pairLabel($pair), 'side' => $side, 'from' => $subClass];
        if (isset($pair['only_names']) && ! self::nameHas($text, $pair['only_names'])) {
            return $out + ['to' => null, 'flag' => true];
        }
        $to = $pair[$side][0];
        foreach ($pair['by_name'][$side] ?? [] as $member => $words) {
            if (self::nameHas($text, $words)) {
                $to = $member;
                break;
            }
        }

        return $out + ['to' => $to, 'flag' => self::reversed($pair, $side)];
    }

    /**
     * Whether a flagged pair's member on $side is the account's kind on the wrong side (a supplier in debit, a customer in
     * credit), for review. On the base side it is the account's own kind: an AI answer put right by the side, no review.
     */
    public static function reversed(array $pair, string $side): bool
    {
        return (bool) ($pair['flag'] ?? false) && $side !== ($pair['base'] ?? 'debit');
    }

    /** A pair's name for messages: its config label with the classes renamed on the taxonomy page under their new names. */
    public static function pairLabel(array $pair): string
    {
        $renamed = app(TaxonomyRepositoryInterface::class)->renamedOrigins()['sub'] ?? [];

        return $renamed ? strtr($pair['label'], $renamed) : $pair['label'];
    }

    /** Whether the name has one of the words, as whole words after normalization (a word joined to «و» counts: «وسلف»). */
    public static function nameHas(string $name, array $words): bool
    {
        $tokens = explode(' ', ImportNormalizer::text($name));
        $tokens = array_map(fn ($t) => mb_strlen($t) > 2 && str_starts_with($t, 'و') ? [$t, mb_substr($t, 1)] : [$t], $tokens);
        foreach ($words as $word) {
            $want = explode(' ', ImportNormalizer::text($word));
            for ($i = 0; $i + count($want) <= count($tokens); $i++) {
                $hit = true;
                foreach ($want as $k => $w) {
                    if (! in_array($w, $tokens[$i + $k], true)) {
                        $hit = false;
                        break;
                    }
                }
                if ($hit) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether the lookup would use this entry today. */
    public function usable(ClassificationCacheEntry $entry): bool
    {
        return $this->label($entry, 0) !== null;
    }

    /** The entry as a label for row $n, or null when it must not be used. */
    private function label(ClassificationCacheEntry $entry, int $n): ?array
    {
        $manual = $entry->source === self::MANUAL;
        // Accountants' labels (manual, imported) stay valid after a version bump while their sub-class exists.
        if (! $manual && $entry->source !== self::IMPORTED && $entry->taxonomy_version !== self::version()) {
            return null;
        }
        if ($entry->row_type !== 'account') {
            return ['row' => $n, 'row_type' => $entry->row_type, 'confidence' => 0];
        }
        if (! $manual && $entry->confidence < self::minConfidence()) {
            return null;
        }
        if (! $this->taxonomy->find((string) $entry->sub_class)) {
            return null;   // the sub-class left the taxonomy
        }

        return self::withReason([
            'row'        => $n,
            'row_type'   => 'account',
            'sub_class'  => $entry->sub_class,
            'confidence' => $manual ? 100 : $entry->confidence,
        ], match ($entry->source) {
            // The AI's own reason (data), marked as coming from the cache.
            self::MANUAL   => Phrase::make('classification_messages.cache_reasons.manual'),
            self::IMPORTED => Phrase::make('classification_messages.cache_reasons.imported', ['reason' => (string) $entry->reason]),
            default        => $entry->reason
                ? Phrase::make('classification_messages.cache_reasons.cached', ['reason' => $entry->reason])
                : Phrase::make('classification_messages.cache_reasons.cached_no_reason'),
        });
    }

    /** A label's reason: the Arabic text (`reason`, as always) and the same reason for any language (`reason_phrase`). */
    private static function withReason(array $label, Phrase $reason): array
    {
        return ['reason' => $reason->ar(), 'reason_phrase' => $reason] + $label;
    }

    /**
     * Stores the AI's answers worth reusing (see NOT_CACHED) and returns every row's key.
     *
     * @param  array<int, array>  $labels    final labels by row (after unification)
     * @param  array<int, true>  $fromAi    rows the AI labelled in this run
     * @param  array<int, true>  $excluded  rows whose flags say the answer needs review first
     * @return array<int, ?string> row => readable key (null: the row can't be cached)
     */
    /**
     * The second look, once the AI answered: a generic row (no code, no budget item: its key needs the parent above it)
     * whose parent was unknown during lookup (a row above it went to the AI) is looked up again with every row's type
     * known. A usable entry replaces the AI's answer (a manual correction of «سالم الحربي» is found whatever came above).
     *
     * @param  array<int, array>  $labels  every row's label so far (cache and AI)
     * @param  list<int>  $fromAi  the rows the AI answered
     * @return array{labels: array<int, array>, sources: array<int, string>, flips: array<int, array>}
     */
    public function lookupGeneric(array $extracted, array $labels, array $fromAi): array
    {
        $none = ['labels' => [], 'sources' => [], 'flips' => []];
        $generic = array_values(array_filter($fromAi, function ($n) use ($extracted) {
            if (! isset($extracted[$n])) {
                return false;
            }
            [$code, , $budget] = self::fields($extracted[$n]);

            return ClassificationKey::isGeneric($code, $budget);
        }));
        if (! self::enabled() || ! $generic) {
            return $none;
        }
        $keys    = self::keys($extracted, fn (int $n) => $labels[$n]['row_type'] ?? null);
        $hashes  = array_filter(array_map(fn ($n) => isset($keys[$n]) ? ClassificationKey::hash($keys[$n]) : null, $generic));
        $entries = $hashes ? ClassificationCacheEntry::whereIn('key_hash', array_values($hashes))->get()->keyBy('key_hash') : collect();

        $found = $sources = $used = $locked = [];
        foreach ($hashes as $i => $hash) {
            $n = $generic[$i];
            if (($entry = $entries[$hash] ?? null) && ($label = $this->label($entry, $n))) {
                $found[$n] = $label;
                $sources[$n] = $entry->source;
                $used[$entry->id] = ($used[$entry->id] ?? 0) + 1;
                $entry->side_locked && $locked[$n] = true;
            }
        }
        foreach ($used as $id => $count) {
            ClassificationCacheEntry::whereKey($id)->toBase()->increment('hit_count', $count);
        }
        [$found, $flips] = self::applyFlips($found, $extracted, $locked);

        return ['labels' => $found, 'sources' => $sources, 'flips' => $flips];
    }

    public function store(array $extracted, array $labels, array $fromAi, array $excluded): array
    {
        $keys = self::keys($extracted, fn (int $n) => $labels[$n]['row_type'] ?? null);
        if (! self::enabled()) {
            return $keys;
        }

        $rows      = [];
        $conflicts = [];
        foreach (array_keys($fromAi) as $n) {
            $key   = $keys[$n] ?? null;
            $label = $labels[$n] ?? null;
            if ($key === null || $label === null || isset($excluded[$n])) {
                continue;
            }
            $account    = $label['row_type'] === 'account';
            $confidence = max(0, min(100, (int) ($label['confidence'] ?? 0)));
            $tax        = $account ? $this->taxonomy->find((string) ($label['sub_class'] ?? '')) : null;
            if ($account && (! $tax || $confidence < self::minConfidence())) {
                continue;
            }

            [$code, $name, $budget] = self::fields($extracted[$n]);
            $hash  = ClassificationKey::hash($key);
            $reason = trim((string) ($label['reason'] ?? ''));
            $sub    = $account ? self::baseClass($label['sub_class'], $name) : null;   // the account's kind, not its side today
            $tax    = $account ? $this->taxonomy->find($sub) : null;
            $value  = [
                'key_hash'         => $hash,
                'base_hash'        => ClassificationKey::baseHash($key),
                'name_hash'        => ClassificationKey::nameHash($name),
                'normalized_key'   => $key,
                'original_name'    => trim((string) $name),
                'account_code'     => trim((string) $code) ?: null,
                'budget_item'      => trim((string) $budget) ?: null,
                'row_type'         => $label['row_type'],
                'main_class'       => $tax[1] ?? null,
                'sub_class'        => $sub,
                'source'           => self::AI,
                'confidence'       => $account ? $confidence : 0,
                'reason'           => $account && preg_match('/\p{Arabic}/u', $reason) ? $reason : null,   // shown like the AI's reasons: Arabic only
                'taxonomy_version' => self::version(),
            ];
            if (isset($rows[$hash]) && [$rows[$hash]['row_type'], $rows[$hash]['sub_class']] !== [$value['row_type'], $value['sub_class']]) {
                $conflicts[$hash] = true;   // one key, two answers in the same file: neither is reliable
            }
            $rows[$hash] ??= $value;
        }
        $rows = array_diff_key($rows, $conflicts);

        foreach (array_chunk($rows, 200, true) as $chunk) {
            // An AI answer never replaces a manual correction.
            $manual = ClassificationCacheEntry::whereIn('key_hash', array_keys($chunk))->where('source', self::MANUAL)->pluck('key_hash')->all();
            if ($chunk = array_diff_key($chunk, array_flip($manual))) {
                ClassificationCacheEntry::upsert(array_values($chunk), ['key_hash'], [
                    'base_hash', 'name_hash', 'normalized_key', 'original_name', 'account_code', 'budget_item', 'row_type', 'main_class', 'sub_class',
                    'source', 'confidence', 'reason', 'taxonomy_version',
                ]);
            }
        }

        return $keys;
    }

    /** Saves a manual correction for a key (replacing any AI answer); the main class comes from the current taxonomy. */
    public function correct(string $key, ?string $code, string $name, ?string $budget, string $subClass, ?string $side = null): ClassificationCacheEntry
    {
        // A side-pair member chosen against the row's side is a deliberate override: locked. With the side, it is the kind
        // of account: later rows flip by their own side.
        $member = self::pairOf($subClass)[2] ?? null;
        $locked = $member !== null && $side !== null && $member !== $side;
        $locked || $subClass = self::baseClass($subClass, $name);

        return ClassificationCacheEntry::updateOrCreate(['key_hash' => ClassificationKey::hash($key)], [
            'side'             => $side,
            'side_locked'      => $locked,
            'base_hash'        => ClassificationKey::baseHash($key),
            'name_hash'        => ClassificationKey::nameHash($name),
            'normalized_key'   => $key,
            'original_name'    => trim($name),
            'account_code'     => trim((string) $code) ?: null,
            'budget_item'      => trim((string) $budget) ?: null,
            'row_type'         => 'account',
            'main_class'       => $this->taxonomy->find($subClass)[1] ?? null,
            'sub_class'        => $subClass,
            'source'           => self::MANUAL,
            'confidence'       => 100,
            'reason'           => null,
            'taxonomy_version' => self::version(),
        ]);
    }

    /**
     * An entry's class set by hand from the memory page: kept as a manual correction (it wins over the AI from now on).
     * No row, so no side: a side-pair member is stored as the account's kind and later rows flip by their own side.
     */
    public function reclassify(ClassificationCacheEntry $entry, string $subClass): ClassificationCacheEntry
    {
        $subClass = self::baseClass($subClass, (string) $entry->original_name);
        $entry->update([
            'side_locked'      => false,
            'row_type'         => 'account',
            'main_class'       => $this->taxonomy->find($subClass)[1] ?? null,
            'sub_class'        => $subClass,
            'source'           => self::MANUAL,
            'confidence'       => 100,
            'reason'           => null,
            'taxonomy_version' => self::version(),
        ]);

        return $entry;
    }

    /** How the cache works, for the accountant: built from the rules, constants and config above. */
    public function explain(): array
    {
        $vars = [':version' => self::version(), ':min' => (string) self::minConfidence(),
            ':companies' => (string) config('classification.import.name_layer.min_companies', 2), ':share' => (string) config('classification.import.name_layer.min_share', 80)];
        $text = fn (string $s) => strtr($s, $vars);

        $examples = [
            ['code' => '5101', 'name' => 'مصروفات  إدارية', 'budget_item' => 'المصروفات العمومية والإدارية', 'parent' => null],
            ['code' => null, 'name' => 'أخرى', 'budget_item' => null, 'parent' => 'المصروفات البيعية'],
            ['code' => null, 'name' => 'أخرى', 'budget_item' => null, 'parent' => 'الإيرادات الأخرى'],
        ];

        return [
            'enabled' => self::enabled(),
            'steps'   => array_map(fn ($s, $i) => ['order' => $i + 1, 'source' => $s['source'], 'title' => $s['title'], 'description' => $text($s['description'])],
                self::STEPS, array_keys(self::STEPS)),
            'after_lookup'        => self::AFTER,
            'normalization_rules' => array_merge(
                array_map(fn ($rule, $r) => [
                    'rule' => $rule, 'description' => $r['description'], 'before' => $r['example'], 'after' => ClassificationKey::apply($rule, $r['example']),
                ], array_keys(ClassificationKey::RULES), ClassificationKey::RULES),
                [['rule' => 'account_code', 'description' => ClassificationKey::CODE_RULE['description'],
                    'before' => ClassificationKey::CODE_RULE['example'], 'after' => ClassificationKey::code(ClassificationKey::CODE_RULE['example'])]],
            ),
            'normalization_example' => ['before' => 'مَصْرُوفـات  إدارية', 'after' => ClassificationKey::name('مَصْرُوفـات  إدارية')],
            'key' => [
                'fields'   => ClassificationKey::KEY_FIELDS,
                'why'      => ClassificationKey::KEY_WHY,
                'examples' => array_map(fn ($e) => $e + ['key' => ClassificationKey::make($e['code'], $e['name'], $e['budget_item'], $e['parent'])], $examples),
            ],
            'stored_fields' => array_map(fn ($column) => [
                'field' => $column, 'description' => ClassificationCacheEntry::FIELD_DESCRIPTIONS[$column] ?? null,
            ], Schema::getColumnListing((new ClassificationCacheEntry)->getTable())),
            'thresholds' => [
                'cache_min_confidence' => self::minConfidence(),
                'review_threshold'     => (int) config('classification.review_threshold'),
                'taxonomy_version'     => self::version(),
                'description'          => $text('تُخزَّن إجابة الذكاء الاصطناعي وتُستخدم فقط إذا كانت درجة ثقتها :min أو أكثر ومن نسخة الشجرة :version. '
                    .'عند تغيّر شجرة التصنيف أو تعليمات الذكاء الاصطناعي، تزداد النسخة فتُتجاهل جميع الإجابات القديمة (وتبقى التصحيحات اليدوية).'),
            ],
            'not_cached' => array_map($text, self::NOT_CACHED),
        ];
    }

    /** debit | credit of the closing balance; null when it is zero. */
    public static function side(array $account): ?string
    {
        $net = (float) ($account['closing_debit'] ?? 0) - (float) ($account['closing_credit'] ?? 0);

        return abs($net) < 0.005 ? null : ($net > 0 ? 'debit' : 'credit');
    }

    /**
     * Imported entries by base hash (code + name), for the rows with a code: only bases whose usable imported entries all
     * hold one sub-class (one clear answer); the first such entry stands for them.
     *
     * @return array<string, ClassificationCacheEntry>
     */
    private function importedByCode(array $extracted): array
    {
        $hashes = [];
        foreach ($extracted as $e) {
            [$code, $name, $budget] = self::fields($e);
            $hash = ClassificationKey::baseHash(ClassificationKey::make($code, $name, $budget, null));
            $hash !== null && $hashes[$hash] = true;
        }
        if (! $hashes) {
            return [];
        }
        $groups = [];
        foreach (array_chunk(array_keys($hashes), 500) as $chunk) {
            foreach (ClassificationCacheEntry::whereIn('base_hash', $chunk)->where('source', self::IMPORTED)->orderBy('id')->get() as $e) {
                $this->usable($e) && $groups[$e->base_hash][] = $e;
            }
        }
        $out = [];
        foreach ($groups as $hash => $list) {
            if (count(array_unique(array_map(fn ($e) => $e->sub_class, $list))) === 1) {
                $out[$hash] = $list[0];
            }
        }

        return $out;
    }

    /** @return array<string, ClassificationNameLayerEntry> by name hash|side, for the names of the file */
    private function nameLayer(array $extracted): array
    {
        $hashes = [];
        foreach ($extracted as $e) {
            ClassificationKey::name($e['account']['account_name']) !== '' && $hashes[] = ClassificationKey::nameHash($e['account']['account_name']);
        }
        if (! $hashes || ! Schema::hasTable('classification_name_layer')) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($hashes)), 500) as $chunk) {
            foreach (ClassificationNameLayerEntry::whereIn('name_hash', $chunk)->get() as $e) {
                $out[$e->name_hash.'|'.$e->side] = $e;
            }
        }

        return $out;
    }

    /** @return array<string, ClassificationCacheEntry> by key hash: entries with one of the keys, or one of the names */
    private function fetch(array $keyHashes, array $nameHashes): array
    {
        if (! $keyHashes && ! $nameHashes) {
            return [];
        }

        return ClassificationCacheEntry::query()
            ->where(fn ($q) => $q->whereIn('key_hash', array_values(array_unique($keyHashes)))
                ->orWhereIn('name_hash', array_values(array_unique($nameHashes))))
            ->get()->keyBy('key_hash')->all();
    }
}
