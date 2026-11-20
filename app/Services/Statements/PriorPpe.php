<?php

namespace App\Services\Statements;

use App\Services\AmountParser;
use App\Services\Statements\PriorPdf\Sections;
use App\Services\Statements\PriorPdf\Tables;
use App\Services\Statements\PriorPdf\Totals;
use App\Services\Statements\PriorPdf\Wording;

/**
 * The PPE note as last year's printed it (Phase 6, R13, R11): its categories (columns) as printed, their depreciation
 * rates from the policies note (useful life = 100 / rate, under the straight-line method only), last year's movement per
 * category, and this year's from the trial balance's accounts placed on them. An account is placed on a category by the
 * accountant's choice, its name (the category's words in it: «مجمع اهلاك مبانى وانشاءات» is «مباني وانشاءات»), or its
 * opening balance equal to the category's closing last year (cost, or accumulated depreciation for a contra account). This year's opening per category
 * is checked against last year's closing (a riyal either way: probably rounding, shown). Each category's accounts say
 * which of the note's own categories it is, so last year's movement becomes the note's comparative there (D2: one net
 * line on the balance sheet; the detail in the note).
 */
final class PriorPpe
{
    /** Last year's rows by what they say: a part (cost, accumulated depreciation, net book value) and the movement. */
    private const MOVES = [
        'cost'        => ['additions' => '/إضاف|اضاف/u', 'disposals' => '/استبعاد|إستبعاد|مبيعات|بيع/u', 'transfers' => '/تحويل|المحول/u'],
        'accumulated' => ['depreciation' => '/إضاف|اضاف|إهلاك|اهلاك|استهلاك|المحمل/u', 'acc_disposals' => '/استبعاد|إستبعاد/u'],
    ];

    /**
     * Last year's categories: the PPE note's movement table (the note of the statement line, else the note titled so),
     * each column a category (a table printed with its categories as rows was turned: Tables::transpose), the total
     * column left out (by its arithmetic, else its header); the cost and the accumulated depreciation printed as two
     * tables are read as one. Each category with its rate and last year's cells.
     *
     * @return ?list<array{key: string, label: string, rate: ?float, life: ?float, rate_range: ?array{0: float, 1: float}, life_range: ?array{0: float, 1: float},
     *         last: array<string, float>, page: int}> a rate printed as a range («10% - 20%»): rate and life null, their ranges
     *         (the life from 100 / the higher rate to 100 / the lower)
     */
    public static function categories(array $file, array $fileRows = []): ?array
    {
        $read = self::read($file, $fileRows);
        if ($read === null) {
            return null;
        }
        [$t, $columns, $cells, , $rates] = $read;
        // A life is 100 / the rate only under the straight-line method: computed when last year's file says so, else left
        // for the accountant (the rate is still shown).
        $method = self::method($file['document']);
        $life = fn (float $rate) => $method === 'straight_line' ? round(100 / $rate, 2) : null;
        $out = [];
        $n = 0;
        foreach ($columns as $i => $label) {
            $rate = $rates[StatementSynonyms::wording($label)] ?? self::nearRate($rates, StatementSynonyms::wording($label));
            $last = array_map(fn ($byColumn) => $byColumn[$i] ?? 0.0, $cells);
            $source = $rate !== null ? 'printed' : null;
            // No rate printed (the policies say «القسط الثابت على مدى الأعمار» only): the rate last year's own movement shows, the
            // year's depreciation over the cost, when it is a usual rate (10%, 25% …); a category fully depreciated shows none.
            if ($rate === null && $method !== 'other' && ($implied = self::impliedRate($last)) !== null) {
                [$rate, $source] = [[$implied, $implied], 'movement'];
            }
            $range = $rate !== null && $rate[0] !== $rate[1] && $rate[0] > 0 ? $rate : null;
            $single = $rate !== null && $range === null ? $rate[0] : null;
            // A rate read from the movement is a straight-line one by construction.
            $lifeOf = $source === 'movement' ? fn (float $r) => round(100 / $r, 2) : $life;
            $out[] = ['key' => 'c'.++$n, 'label' => $label, 'rate' => $single, 'life' => $single !== null && $single > 0 ? $lifeOf($single) : null,
                'rate_range' => $range, 'life_range' => $range === null || $method !== 'straight_line' ? null : [$life($range[1]), $life($range[0])],
                'rate_source' => $source, 'method' => $method, 'last' => $last, 'page' => $t['page']];
        }

        return $out === [] ? null : $out;
    }

    /**
     * The note's total of each of last year's rows (cost_opening, acc_closing, nbv_opening …): where the printed total
     * differs from its categories' sum, the figure that ties to the balance sheet or the accountant's choice
     * (PriorPdf\Totals); else the categories' sum. For the note's total column.
     *
     * @return array<string, float>
     */
    public static function totals(array $file, array $fileRows = []): array
    {
        $read = self::read($file, $fileRows);
        if ($read === null) {
            return [];
        }
        [, $columns, $cells, $at] = $read;
        $used = Totals::used($file['document'], $file['total_choices'] ?? []);
        $out = [];
        foreach ($cells as $key => $byColumn) {
            [$table, $row] = $at[$key] ?? [null, null];
            $out[$key] = $used[$table][$row] ?? round(array_sum(array_intersect_key($byColumn, $columns)), 2);
        }

        return $out;
    }

    /**
     * The PPE note's table as read: the table (a separate depreciation table's rows appended), its category columns, last
     * year's cells by row key and column, where each row key was printed ([table, row]), the rates.
     *
     * @return ?array{0: array, 1: array<int, string>, 2: array<string, array<int, float>>, 3: array<string, array{int, int}>, 4: array<string, float>}
     */
    private static function read(array $file, array $fileRows): ?array
    {
        $doc = $file['document'] ?? null;
        $section = $doc === null ? null : PriorNoteItems::section($doc, $file, $fileRows, ['sofp.ppe']) ?? self::titled($doc);
        if ($section === null) {
            return null;
        }
        $year = $doc['years']['current'] ?? null;
        $fix = fn (string $text) => Wording::apply($text, $doc['corrections'] ?? [], $file['wording_choices'] ?? []);
        $combined = self::combined(Sections::tablesOf($section, $doc['sections'], $doc['tables']), $fix);
        if ($combined === null) {
            return null;
        }
        [$t, $columns] = $combined;
        $rates = self::rates($doc, $fix);
        [$cells, $at] = self::cells($t, $year);

        return [$t, $columns, $cells, $at, $rates];
    }

    /**
     * The note's tables as one (a note read as several tables): its first movement table, then the later tables of the
     * same categories in its column order, each under its part's heading (its own, its title, else by its place: a later
     * movement table is the accumulated depreciation, a table of dated rows the net book values). Null without a movement
     * table.
     *
     * @return ?array{0: array, 1: array<int, string>} the table (each row with `at`: [table, row]) and its categories by column
     */
    public static function combined(array $tables, ?callable $fix = null): ?array
    {
        $fix ??= fn (string $text) => $text;
        $movement = array_values(array_filter($tables, fn ($t) => $t['movement'] ?? false));
        if ($movement === []) {
            return null;
        }
        $labels = fn (array $t) => array_filter(array_map(function ($i, $c) use ($t, $fix) {
            $label = $fix(Tables::columnName($c['header']));   // the category, whether the year is printed above or below it

            return $label === '' || $i === ($t['total_column'] ?? null) || preg_match('/^(ال)?(إجمالي|اجمالي|الإجمـالي|المجموع|الاجمالي)/u', preg_replace('/ـ+/u', '', $label)) ? null : $label;
        }, array_keys($t['columns']), $t['columns']));
        $t = $movement[0];
        $t['rows'] = array_map(fn ($r, $i) => $r + ['at' => [$t['id'], $i]], $t['rows'], array_keys($t['rows']));
        $columns = $labels($t);
        $parts = fn (array $rows) => array_map(fn ($r) => preg_match('/إهلاك|اهلاك|استهلاك|الاطفاء|إطفاء/u', $r['label']) ? 'accumulated' : (preg_match(Tables::NBV, $r['label']) ? 'nbv' : 'cost'),
            array_filter($rows, fn ($r) => Tables::isHeading($r) || ($r['kind'] ?? null) === 'heading'));
        $empty = ['raw' => null, 'amount' => null, 'status' => 'empty'];
        foreach (array_slice($tables, array_search($t['id'], array_column($tables, 'id'), true) + 1) as $more) {
            $theirs = array_flip(array_map(fn ($l) => StatementSynonyms::wording($l), $labels($more)));
            $order = array_map(fn ($l) => $theirs[StatementSynonyms::wording($l)] ?? null, $columns);
            if (in_array(null, $order, true) || count($theirs) !== count($columns)) {
                continue;
            }
            $dated = $more['rows'] !== [] && array_filter($more['rows'], fn ($r) => ! Tables::isHeading($r) && preg_match('/^\s*(كما\s+)?في\s/u', $r['label']) !== 1) === [];
            if (! ($more['movement'] ?? false) && ! $dated) {
                continue;
            }
            $seen = $parts($t['rows']);
            $heading = match (true) {
                Tables::isHeading($more['rows'][0]) => null,
                trim((string) $more['title']) !== '' => $more['title'],
                $dated => 'صافي القيمة الدفترية',
                ! in_array('accumulated', $seen, true) => 'الإهلاك المتراكم',
                default => null,
            };
            $rows = $heading === null ? [] : [['label' => $heading, 'note' => null, 'cells' => [], 'kind' => 'heading']];
            foreach ([...$rows, ...array_map(fn ($r, $j) => $r + ['at' => [$more['id'], $j]], $more['rows'], array_keys($more['rows']))] as $r) {
                $cells = array_fill(0, count($t['columns']), $empty);
                foreach ($order as $i => $j) {
                    $cells[$i] = $r['cells'][$j] ?? $empty;
                }
                $t['rows'][] = ['cells' => $cells, 'kind' => $r['kind'] ?? 'line'] + $r;
            }
        }

        return [$t, $columns];
    }

    /**
     * The note's net book value at the end of the year, its categories added up: cost − accumulated depreciation. Null
     * when the year's cost can't be read.
     */
    public static function netBookValue(array $tables, ?int $year): ?float
    {
        $combined = self::combined($tables);
        if ($combined === null) {
            return null;
        }
        [$t, $columns] = $combined;
        [$cells] = self::cells($t, $year);
        if (! isset($cells['cost_closing'])) {
            return null;
        }
        $sum = fn (string $key) => array_sum(array_map(fn ($i) => $cells[$key][$i] ?? 0.0, array_keys($columns)));

        return round($sum('cost_closing') - $sum('acc_closing'), 2);
    }

    /** The note titled as the PPE note, when no statement line links to one: its first movement table's. */
    private static function titled(array $doc): ?int
    {
        foreach ($doc['sections'] as $s) {
            if ($s['parent'] === null && preg_match('/ممتلكات|الأصول الثابتة|الاصول الثابتة|الموجودات الثابتة|العقارات والآلات/u', $s['title'])
                && array_filter(Sections::tablesOf($s['id'], $doc['sections'], $doc['tables']), fn ($t) => $t['movement'] ?? false) !== []) {
                return $s['id'];
            }
        }

        return null;
    }

    /**
     * Each PPE account on a category of last year: the accountant's choice, its name, its opening = the category's
     * closing last year (one account; cost or accumulated depreciation by the account's side), or a word of one category
     * only in its name (to check).
     *
     * @param array<string, string> $choices row → category key (or `none`)
     * @return array<int, array{category: ?string, by: string, side: string}>
     */
    public static function place(ScheduleContext $ctx, array $categories, array $choices): array
    {
        $out = [];
        $wording = array_map(fn ($c) => array_filter(explode(' ', StatementSynonyms::wording($c['label'])), fn ($w) => mb_strlen($w) > 2), $categories);
        foreach ($ctx->rowsOn('sofp.ppe') as $n => $share) {
            $side = $ctx->isContra($n) ? 'accumulated' : 'cost';
            $choice = $choices[(string) $n] ?? null;
            if ($choice !== null && ($choice === 'none' || in_array($choice, array_column($categories, 'key'), true))) {
                $out[$n] = ['category' => $choice === 'none' ? null : $choice, 'by' => 'choice', 'side' => $side];
                continue;
            }
            $name = explode(' ', StatementSynonyms::wording((string) ($ctx->rows[$n]['account_name'] ?? '')));
            $byName = array_keys(array_filter($wording, fn ($w) => $w !== [] && array_diff($w, $name) === []));
            if (count($byName) === 1) {
                $out[$n] = ['category' => $categories[$byName[0]]['key'], 'by' => 'name', 'side' => $side];
                continue;
            }
            $opening = $ctx->sum([$n => $share], $side === 'cost' ? 'debit' : 'credit')['opening'];
            $closing = $side === 'cost' ? 'cost_closing' : 'acc_closing';
            $byAmount = $opening === null || abs($opening) <= Tables::TOLERANCE ? []
                : array_keys(array_filter($categories, fn ($c) => abs(($c['last'][$closing] ?? 0.0) - $opening) <= Tables::TOLERANCE));
            if (count($byAmount) === 1) {
                $out[$n] = ['category' => $categories[$byAmount[0]]['key'], 'by' => 'amount', 'side' => $side];
                continue;
            }
            // A word of one category only, in the account's name («اثاث» of «اثاث ومفروشات» in «مجمع اهلاك اثاث وتجهيزات»).
            $byWords = array_keys(array_filter($wording, fn ($w) => array_intersect($w, $name) !== []));
            $out[$n] = count($byWords) === 1 ? ['category' => $categories[$byWords[0]]['key'], 'by' => 'words', 'side' => $side]
                : ['category' => null, 'by' => 'none', 'side' => $side];
        }

        return $out;
    }

    /**
     * The note by last year's categories: each with its accounts, this year's opening and closing per side from them (and
     * the movements when the trial balance has movement columns), last year's cells, and this year's opening against last
     * year's closing; and, per category of the note's own, last year's cells to be its comparative.
     *
     * @return ?array{categories: list<array>, accounts: list<array>, comparative: array<string, float>}
     */
    public static function build(ScheduleContext $ctx, array $file, array $fileRows, array $choices): ?array
    {
        $categories = self::categories($file, $fileRows);
        if ($categories === null) {
            return null;
        }
        $placed = self::place($ctx, $categories, $choices);
        $byKey = array_column($categories, null, 'key');
        foreach ($categories as $i => $c) {
            $rows = ['cost' => [], 'accumulated' => []];
            foreach ($placed as $n => $p) {
                if ($p['category'] === $c['key']) {
                    $rows[$p['side']][$n] = $ctx->rowsOn('sofp.ppe')[$n];
                }
            }
            $cost = $ctx->sum($rows['cost'], 'debit');
            $acc = $ctx->sum($rows['accumulated'], 'credit');
            $categories[$i]['current'] = [
                'cost_opening' => $cost['opening'], 'additions' => $cost['increase'], 'disposals' => $cost['decrease'], 'cost_closing' => $cost['closing'],
                'acc_opening' => $acc['opening'], 'depreciation' => $acc['increase'], 'acc_disposals' => $acc['decrease'], 'acc_closing' => $acc['closing'],
            ];
            $categories[$i]['current']['nbv_closing'] = round($cost['closing'] - $acc['closing'], 2) + 0.0;
            $categories[$i]['accounts'] = count($rows['cost']) + count($rows['accumulated']);
            // This year's opening = last year's closing, per side (a riyal either way: probably rounding).
            $categories[$i]['opening_check'] = array_values(array_filter(array_map(function ($pair) use ($categories, $i) {
                [$open, $close] = $pair;
                $a = $categories[$i]['current'][$open];
                $b = $categories[$i]['last'][$close] ?? 0.0;
                if ($a === null || (abs($a) < 0.005 && abs($b) < 0.005)) {
                    return null;
                }
                $d = round($a - $b, 2) + 0.0;

                return ['side' => $open === 'cost_opening' ? 'cost' : 'accumulated', 'opening' => $a, 'prior' => $b, 'difference' => $d,
                    'kind' => OpeningCheck::kind($a, $b)];
            }, [['cost_opening', 'cost_closing'], ['acc_opening', 'acc_closing']])));
        }

        // Last year's cells by the note's own categories: each category of last year goes where its accounts are.
        $comparative = [];
        $owns = [];
        foreach ($categories as $c) {
            $own = [];
            foreach ($placed as $n => $p) {
                if ($p['category'] === $c['key']) {
                    $own[] = $ctx->categories[$n] ?? $ctx->catalogue->category('ppe', $ctx->rows[$n]['sub_class'] ?? null);
                }
            }
            $own = array_values(array_unique(array_filter($own)));
            $owns[$c['key']] = count($own) === 1 ? $own[0] : null;
            if (count($own) !== 1) {
                continue;   // no account, or accounts of several: last year's cells can't be placed
            }
            foreach ($c['last'] as $row => $amount) {
                $comparative["ppe.{$row}.{$own[0]}"] = round(($comparative["ppe.{$row}.{$own[0]}"] ?? 0.0) + $amount, 2);
            }
        }

        foreach ($categories as $i => $c) {
            $categories[$i]['own'] = $owns[$c['key']] ?? null;
        }

        return ['categories' => $categories, 'comparative' => $comparative, 'totals' => self::totals($file, $fileRows), 'accounts' => array_values(array_map(fn ($n, $p) => [
            'row' => $n, 'name' => (string) ($ctx->rows[$n]['account_name'] ?? $n), 'side' => $p['side'], 'category' => $p['category'], 'by' => $p['by'],
            'label' => $p['category'] === null ? null : $byKey[$p['category']]['label'],
            // A difference from last year's approved closing (Step B), placed by it: not an account to move.
            'approved_opening' => ApprovedOpening::isRow($n),
        ], array_keys($placed), $placed))];
    }

    /**
     * Last year's cells of the movement table, by row key and column index, and where each balance was printed: its
     * year's block (the balances naming the year: at its start and its end), the movements between them, and the net
     * book values.
     *
     * @return array{0: array<string, array<int, float>>, 1: array<string, ?array{int, int}>} the cells; where each was printed
     */
    private static function cells(array $t, ?int $year): array
    {
        $at = [];
        $parts = [];
        $part = null;
        foreach ($t['rows'] as $r) {
            if (Tables::isHeading($r) || $r['kind'] === 'heading') {
                $part = preg_match('/إهلاك|اهلاك|استهلاك|الاطفاء|إطفاء/u', $r['label']) ? 'accumulated' : (preg_match(Tables::NBV, $r['label']) ? 'nbv' : 'cost');
                if (array_filter($r['cells'], fn ($c) => $c['status'] !== 'empty') === []) {
                    continue;
                }
            }
            $parts[$part ?? 'cost'][] = $r;
        }
        $amount = fn (array $r) => array_map(fn ($c) => (float) ($c['amount'] ?? 0.0), $r['cells']);
        $out = [];
        foreach (['cost' => ['cost_opening', 'cost_closing'], 'accumulated' => ['acc_opening', 'acc_closing']] as $p => [$open, $close]) {
            $rows = $parts[$p] ?? [];
            $balances = array_keys(array_filter($rows, fn ($r) => Tables::isBalance($r['label'])));
            // The year's block: from the balance naming its start to the one naming its end; else the last two balances.
            $named = array_values(array_filter($balances, fn ($k) => $year !== null && in_array($year, Tables::years($rows[$k]['label']), true)));
            [$from, $to] = count($named) >= 2 ? [$named[0], end($named)] : (count($balances) >= 2 ? array_slice($balances, -2) : [null, null]);
            if ($from === null) {
                continue;
            }
            $out[$open] = $amount($rows[$from]);
            $out[$close] = $amount($rows[$to]);
            [$at[$open], $at[$close]] = [$rows[$from]['at'] ?? null, $rows[$to]['at'] ?? null];
            for ($k = $from + 1; $k < $to; $k++) {
                foreach (self::MOVES[$p] as $move => $pattern) {
                    if (preg_match($pattern, $rows[$k]['label'])) {
                        $out[$move] = array_map(fn ($a, $b) => $a + abs($b), $out[$move] ?? array_fill(0, count($rows[$k]['cells']), 0.0), $amount($rows[$k]));
                        break;
                    }
                }
            }
        }
        foreach ($parts['nbv'] ?? [] as $r) {
            $key = $year !== null && in_array($year, Tables::years($r['label']), true) ? 'nbv_closing' : 'nbv_opening';
            if (! isset($out[$key])) {
                [$out[$key], $at[$key]] = [$amount($r), $r['at'] ?? null];
            }
        }

        return [$out, $at];
    }

    /** The depreciation rates in use (% a year): a rate read from a movement is taken only when it is one of these. */
    private const USUAL_RATES = [2.0, 2.5, 3.0, 3.33, 4.0, 5.0, 6.25, 6.67, 7.5, 8.0, 10.0, 12.5, 15.0, 16.67, 20.0, 25.0, 30.0, 33.33, 40.0, 50.0, 100.0];

    /**
     * The straight-line rate a category's movement shows: the one usual rate between the year's depreciation over the cost at the
     * start and over the cost at the end. Null without depreciation (fully depreciated), or when none or several usual rates fit.
     *
     * @param array<string, float> $last the category's cells (cost_opening, cost_closing, depreciation …)
     */
    public static function impliedRate(array $last): ?float
    {
        $charge = abs((float) ($last['depreciation'] ?? 0.0));
        $open = abs((float) ($last['cost_opening'] ?? 0.0));
        $close = abs((float) ($last['cost_closing'] ?? 0.0));
        if ($charge < 1.0 || $open < 1.0 || $close < 1.0) {
            return null;   // nothing charged (fully depreciated), or bought during the year: no year's charge to read a rate from
        }
        // The charge lies between the rate on the cost at the start (the additions not depreciated in their year) and on the
        // cost at the end (depreciated the whole year): the one usual rate between the two (3% of slack for rounding), else none.
        $low = $charge / max($open, $close) * 100 * 0.97;
        $high = $charge / min($open, $close) * 100 * 1.03;
        $fits = array_values(array_filter(self::USUAL_RATES, fn ($r) => $r >= $low && $r <= $high));

        return count($fits) === 1 ? $fits[0] : null;
    }

    /** The rate of the one category within a letter of this one (a reading slip in one of the two tables). */
    private static function nearRate(array $rates, string $wording): ?array
    {
        $near = array_filter($rates, fn ($rate, $w) => strlen($w) === strlen($wording) && levenshtein($w, $wording) <= 2, ARRAY_FILTER_USE_BOTH);

        return count($near) === 1 ? reset($near) : null;
    }

    /**
     * The rates of the policies note's table of depreciation rates (a header with «%»), by category wording: [low, high],
     * the same twice for one rate; a range as printed («10% - 20%», «من 10% إلى 20%») both ends.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private static function rates(array $doc, callable $fix): array
    {
        $out = [];
        foreach ($doc['tables'] as $t) {
            // A table of useful lives in years (its header, or its cells, say «سنة / سنوات»): the rate is 100 / the years.
            $years = preg_match('/سنة|سنوات|سنه|العمر|الأعمار|الاعمار/u', implode(' ', array_column($t['columns'], 'header')).' '.implode(' ', array_map(fn ($r) => implode(' ', array_column($r['cells'], 'raw')), $t['rows'])));
            if ($years && ! Tables::isRates($t['columns'], $t['rows'])) {
                foreach ($t['rows'] as $r) {
                    $n = \App\Services\AmountParser::asciiDigits((string) ($r['cells'][0]['raw'] ?? ''));
                    if (trim($r['label']) !== '' && preg_match('/^\D*(\d+(?:\.\d+)?)\s*(?:[-–—]|إلى|الى)?\s*(\d+(?:\.\d+)?)?\D*$/u', $n, $m) && (float) $m[1] > 0 && (float) $m[1] <= 100) {
                        $low = 100 / max((float) $m[1], (float) ($m[2] ?? $m[1]));
                        $high = 100 / min((float) $m[1], (float) ($m[2] ?? $m[1]));
                        $out[StatementSynonyms::wording($fix(trim($r['label'])))] = [round($low, 2), round($high, 2)];
                    }
                }
                continue;
            }
            if (! Tables::isRates($t['columns'], $t['rows'])) {
                continue;
            }
            foreach ($t['rows'] as $r) {
                $rate = self::rate($r['cells'][0] ?? null);
                if ($rate !== null && trim($r['label']) !== '') {
                    $out[StatementSynonyms::wording($fix(trim($r['label'])))] = $rate;
                }
            }
        }

        return $out;
    }

    /**
     * The depreciation method last year's file states, from its text on depreciation: `straight_line` when it names only
     * that one, `other` when it names another (declining balance, units of production …), null when it says nothing.
     */
    public static function method(array $doc): ?string
    {
        $texts = [];
        foreach ($doc['sections'] ?? [] as $sec) {
            foreach ([$sec['title'] ?? '', ...($sec['paragraphs'] ?? [])] as $text) {
                $texts[] = (string) $text;
            }
        }
        foreach ($doc['tables'] ?? [] as $t) {
            foreach ($t['rows'] ?? [] as $r) {
                $texts[] = implode(' ', [(string) ($r['label'] ?? ''), ...array_map(fn ($c) => (string) ($c['raw'] ?? ''), $r['cells'] ?? [])]);
            }
        }
        $found = [];
        foreach ($texts as $text) {
            // Only text about depreciation: a lease's income «بالقسط الثابت» says nothing of the assets.
            if (! preg_match('/هلاك|ستهلك|depreciat/iu', $text)) {
                continue;
            }
            preg_match('/القسط\s*الثابت|الخط\s*المستقيم|قسط\s*ثابت|straight[\s-]*line/iu', $text) && $found['straight_line'] = true;
            preg_match('/المتناقص|متناقص|وحدات\s*(ال)?إنتاج|وحدات\s*(ال)?انتاج|مجموع\s*أرقام\s*السنوات|declining|reducing\s*balance|units\s*of\s*production/iu', $text) && $found['other'] = true;
        }

        return isset($found['other']) ? 'other' : (isset($found['straight_line']) ? 'straight_line' : null);
    }

    /** A rate cell: one rate, or two printed as a range; null when neither. @return ?array{0: float, 1: float} */
    public static function rate(?array $cell): ?array
    {
        if (($cell['amount'] ?? null) !== null) {
            return [abs((float) $cell['amount']), abs((float) $cell['amount'])];
        }
        $raw = AmountParser::asciiDigits((string) ($cell['raw'] ?? ''));
        if (preg_match_all('/\d+(?:\.\d+)?/', $raw, $m) === 2 && preg_match('/[-–—]|إلى|الى|حتى/u', $raw)) {
            $rates = array_map('floatval', $m[0]);
            sort($rates);

            return $rates[0] > 0 && $rates[1] <= 100 ? [$rates[0], $rates[1]] : null;
        }

        return null;
    }
}
