<?php

namespace App\Services\Statements;

use App\Services\Statements\PriorPdf\Sections;
use App\Services\Statements\PriorPdf\Tables;
use App\Services\Statements\PriorPdf\Wording;

/**
 * The notes' item tables as last year's notes printed them (R11, R12, D3): with last year's statements confirmed from
 * a PDF, a note's rows are last year's note lines, in order and as printed (its corrections applied, R16), with last
 * year's amounts; this year's figure of each line is the trial-balance accounts placed on it (R12). An account is placed
 * on a line by the accountant's choice; else by its name or its class's (the line's wording); else by its opening
 * balance equal to the line's amount last year (the TB's names and the PDF's differ), one account or a class's accounts
 * together; else by most of the line's words in its name. An account placed on
 * no line is a new item, by its class, with «-» for last year (D3). A note whose PDF table can't be found keeps its
 * rows by class, last year's figures from its statement line where they follow from it (fromLines).
 */
final class PriorNoteItems
{
    /** Notes whose table is built otherwise: trade receivables split gross and impairment by class. */
    private const SKIPPED = ['trade_receivables'];

    /**
     * @param array $breakdowns NoteComparatives::breakdowns()
     * @param array<string, array> $accounts note → NoteBreakdowns::accounts()
     * @param array $file the confirmed file of last year's statements (blocks, document, wording choices)
     * @param array $fileRows the user's choices on its rows (`rows`)
     * @param array<string, array<string, string>> $choices note → account ref → item key, or `new`
     * @return array{0: array, 1: array<string, array>} the breakdowns, and each note's items and accounts for the page
     */
    public static function apply(array $breakdowns, array $accounts, array $file, array $fileRows, array $choices): array
    {
        $doc = $file['document'] ?? null;
        if ($doc === null) {
            return [$breakdowns, []];
        }
        $info = [];
        foreach ($breakdowns as $note => $b) {
            if (in_array($note, self::SKIPPED, true) || ($section = self::section($doc, $file, $fileRows, $b['lines'])) === null
                || ($items = self::items($doc, $section, $file)) === []) {
                continue;
            }
            [$placed, $by] = self::place($accounts[$note] ?? [], $items, $choices[$note] ?? []);
            $rows = [];
            foreach ($items as $key => $item) {
                $on = array_filter($accounts[$note] ?? [], fn ($a) => ($placed[$a['ref']] ?? null) === $key);
                $rows[] = self::row($key, $item['label'], $on, new Figure($item['prior'], 'prior_year_statements', 'ok', [['type' => 'prior_pdf', 'page' => $item['page']]]));
            }
            // Accounts on no line of last year: new items by their class, «-» last year (D3).
            $new = [];
            foreach ($accounts[$note] ?? [] as $a) {
                if (($placed[$a['ref']] ?? 'new') === 'new') {
                    $new[$a['class']][] = $a;
                }
            }
            foreach ($new as $class => $on) {
                $key = isset($items[$class]) ? "{$class} - جديد" : $class;
                $rows[] = self::row($key, $key, $on, new Figure(0.0, 'prior_year_statements'));
            }
            $breakdowns[$note]['rows'] = $rows;
            $breakdowns[$note]['prior_items'] = true;
            $info[$note] = [
                'note' => $doc['sections'][$section]['printed'], 'title' => Wording::apply($doc['sections'][$section]['title'], $doc['corrections'] ?? [], $file['wording_choices'] ?? []),
                'page' => $doc['sections'][$section]['page'],
                'items' => array_values(array_map(fn ($k, $i) => ['key' => $k, 'label' => $i['label'], 'prior' => $i['prior'], 'page' => $i['page']], array_keys($items), $items)),
                'accounts' => array_values(array_map(fn ($a) => ['ref' => $a['ref'], 'label' => $a['label'], 'class' => $a['class'], 'current' => $a['current'],
                    'opening' => $a['opening'], 'item' => $placed[$a['ref']] ?? 'new', 'by' => $by[$a['ref']] ?? 'new',
                    // A difference from last year's approved closing (Step B), placed by it: not an account to move.
                    'approved_opening' => ctype_digit((string) $a['ref']) && ApprovedOpening::isRow($a['ref'])], $accounts[$note] ?? [])),
            ];
        }

        return [$breakdowns, $info];
    }

    /**
     * A note last year's file prints no table for (its line had no note, or the note's table can't be found): last year's
     * figures from the approved statement line where they follow from it (P2 c): the line «-» last year, every row «-»;
     * one row, the line's amount. Several rows of a line that had an amount stay missing (the accountant's to give) unless
     * their accounts' openings add up to the line. A note with last year's lines a riyal off its
     * line takes the riyal on its largest line (remainder).
     *
     * @param array<string, Figure> $comparative last year's statement lines (the approved figures)
     */
    public static function fromLines(array $breakdowns, array $comparative, LineCatalogue $catalogue): array
    {
        foreach ($breakdowns as $note => $b) {
            $rows = $b['rows'];
            $parts = array_map(fn ($l) => $comparative[$l] ?? Figure::missing(), $b['lines']);
            if ($rows === [] || array_filter($parts, fn (Figure $f) => $f->isMissing())) {
                continue;
            }
            // As the notes list them: the income statement's expenses positive.
            $line = round(array_sum(array_map(fn ($l, Figure $f) => str_starts_with($l, 'is.') && $catalogue->side($l) === 'debit' ? -$f->amount : $f->amount,
                $b['lines'], $parts)), 2) + 0.0;
            if ($b['prior_items'] ?? false) {
                $breakdowns[$note] = self::remainder($b, $line);
                continue;
            }
            if (array_filter($rows, fn ($r) => ! ($r['comparative'] ?? Figure::missing())->isMissing())) {
                continue;
            }
            $refs = array_map(fn ($l) => ['type' => 'line', 'key' => $l], $b['lines']);
            if (abs($line) < 0.005) {
                foreach ($rows as $i => $r) {
                    $rows[$i]['comparative'] = new Figure(0.0, 'prior_year_statements', 'ok', $refs);
                }
            } elseif (count($rows) === 1) {
                $rows[0]['comparative'] = new Figure($line, 'prior_year_statements', 'ok', $refs);
            } elseif (! array_filter($rows, fn ($r) => $r['opening'] === null)
                && abs(array_sum(array_map(fn ($r) => $r['opening']->amount, $rows)) - $line) <= Tables::TOLERANCE) {
                // Several rows whose openings (last year's closing by account) add up to the approved line, a riyal aside.
                foreach ($rows as $i => $r) {
                    $rows[$i]['comparative'] = new Figure($r['opening']->amount, 'prior_year_statements', 'ok', $refs);
                }
                $breakdowns[$note] = self::remainder(['rows' => $rows] + $b, $line);
                continue;
            } elseif (str_starts_with($b['lines'][0], 'is.')) {
                // Last year's statements print the line without a note (no split): its amount on this year's main row (the
                // largest), the others «-», said by the info check `prior_line_on_main_row` (P3), never left «[ناقص]».
                $main = array_key_first(array_filter($rows, fn ($r) => abs($r['current']->amount ?? 0.0) === max(array_map(fn ($x) => abs($x['current']->amount ?? 0.0), $rows))));
                foreach ($rows as $i => $r) {
                    $rows[$i]['comparative'] = new Figure($i === $main ? $line : 0.0, 'prior_year_statements', 'ok', $i === $main ? $refs : []);
                }
                $breakdowns[$note]['rows'] = $rows;
                $breakdowns[$note]['prior_on_main_row'] = ['item' => $rows[$main]['label_ar'], 'amount' => $line];
                continue;
            } else {
                continue;   // a balance-sheet line whose accounts' openings don't add up to it: the accountant's to split
            }
            $breakdowns[$note]['rows'] = $rows;
        }

        return $breakdowns;
    }

    /**
     * Last year's note lines a riyal off their approved statement line (the file's own rounding): the difference on the
     * largest line, kept in `comparative_remainder` to be shown. A larger difference stays (the tie check flags it).
     */
    private static function remainder(array $b, float $line): array
    {
        $rows = $b['rows'];
        $prior = array_map(fn ($r) => $r['comparative'] ?? Figure::missing(), $rows);
        if (array_filter($prior, fn (Figure $f) => $f->isMissing())) {
            return $b;
        }
        $rest = round($line - array_sum(array_map(fn (Figure $f) => $f->amount, $prior)), 2);
        if (abs($rest) < 0.005 || abs($rest) > Tables::TOLERANCE) {
            return $b;
        }
        $largest = array_key_first(array_filter($prior, fn (Figure $f) => abs($f->amount) === max(array_map(fn (Figure $g) => abs($g->amount), $prior))));
        $f = $prior[$largest];
        $b['rows'][$largest]['comparative'] = new Figure(round($f->amount + $rest, 2) + 0.0, $f->origin, $f->status, $f->refs);
        $b['comparative_remainder'] = ['amount' => $rest, 'item' => $rows[$largest]['label_ar']];

        return $b;
    }

    /** A note's row: its accounts' figures this year and at the start of it, last year's as given. */
    private static function row(string $key, string $label, array $on, Figure $prior): array
    {
        $refs = array_merge([], ...array_map(fn ($a) => $a['refs'], array_values($on)));
        $openings = array_filter(array_map(fn ($a) => $a['opening'], $on), fn ($o) => $o !== null);

        return ['key' => $key, 'label_ar' => $label, 'prior_item' => $prior->origin === 'prior_year_statements' && $prior->refs !== [],
            'current' => new Figure(round(array_sum(array_column($on, 'current')), 2) + 0.0, 'trial_balance', 'ok', $refs),
            'opening' => $openings === [] ? null : new Figure(round(array_sum($openings), 2) + 0.0, 'trial_balance_opening', 'ok', $refs),
            'comparative' => $prior, 'rows' => array_values(array_unique(array_merge([], ...array_map(fn ($a) => $a['rows'], array_values($on)))))];
    }

    /**
     * Each account's line: the accountant's choice; its name; its opening = the line's amount last year (one account,
     * one line); most of the line's words. Else `new`.
     *
     * @return array{0: array<string, string>, 1: array<string, string>} ref → item key (or `new`), ref → why (choice, name, amount, words, new)
     */
    private static function place(array $accounts, array $items, array $choices): array
    {
        $placed = $by = [];
        $wording = array_map(fn ($i) => StatementSynonyms::wording($i['label']), $items);
        foreach ($accounts as $a) {
            $ref = $a['ref'];
            if (isset($choices[$ref]) && ($choices[$ref] === 'new' || isset($items[$choices[$ref]]))) {
                [$placed[$ref], $by[$ref]] = [$choices[$ref], 'choice'];
                continue;
            }
            foreach ([$a['label'], $a['class']] as $name) {
                if (($key = array_search(StatementSynonyms::wording($name), $wording, true)) !== false) {
                    [$placed[$ref], $by[$ref]] = [$key, 'name'];
                    continue 2;
                }
            }
        }
        // By amount: an account's opening equal to one line's amount last year, and no other account's.
        $open = array_filter($accounts, fn ($a) => ! isset($placed[$a['ref']]) && $a['opening'] !== null && abs($a['opening']) > Tables::TOLERANCE);
        foreach ($open as $a) {
            $lines = array_keys(array_filter($items, fn ($i) => abs($i['prior'] - $a['opening']) <= Tables::TOLERANCE));
            $twins = array_filter($open, fn ($o) => abs($o['opening'] - $a['opening']) <= Tables::TOLERANCE);
            if (count($lines) === 1 && count($twins) === 1) {
                [$placed[$a['ref']], $by[$a['ref']]] = [$lines[0], 'amount'];
            }
        }
        // By amount, a class's accounts together: their openings add up to one line's amount (the banks of «النقد بالبنوك»).
        $byClass = [];
        foreach ($accounts as $a) {
            if (! isset($placed[$a['ref']]) && $a['opening'] !== null) {
                $byClass[$a['class']][] = $a;
            }
        }
        foreach ($byClass as $group) {
            $sum = array_sum(array_column($group, 'opening'));
            $lines = array_keys(array_filter($items, fn ($i) => abs($i['prior'] - $sum) <= Tables::TOLERANCE));
            if (count($group) > 1 && abs($sum) > Tables::TOLERANCE && count($lines) === 1) {
                foreach ($group as $a) {
                    [$placed[$a['ref']], $by[$a['ref']]] = [$lines[0], 'amount'];
                }
            }
        }
        // By words: most of the line's words in the account's name, clearly ahead of the next line.
        foreach ($accounts as $a) {
            if (isset($placed[$a['ref']])) {
                continue;
            }
            $words = explode(' ', StatementSynonyms::wording($a['label']));
            $scores = array_map(function ($w) use ($words) {
                $mine = array_filter(explode(' ', $w), fn ($x) => mb_strlen($x) > 2);

                return $mine === [] ? 0.0 : count(array_intersect($mine, $words)) / count($mine);
            }, $wording);
            arsort($scores);
            $top = array_slice($scores, 0, 2, true);
            $best = array_key_first($top);
            if ($best !== null && $top[$best] >= 0.5 && $top[$best] - (array_values($top)[1] ?? 0.0) >= 0.25) {
                [$placed[$a['ref']], $by[$a['ref']]] = [$best, 'words'];
            }
        }
        // Merged by meaning (the accountant's rule: merge items where it can be done): an account of the same family as
        // exactly one of last year's lines («رسوم وتراخيص» on «مصروفات حكومية», «سكن العاملين» on «أجور ورواتب وما في
        // حكمها»), its family's parent when the family has no line of its own.
        $families = (array) config('financial_statements.note_merge.families', []);
        $itemFamily = array_map(fn ($i) => self::family($i['label']), $items);
        foreach ($accounts as $a) {
            if (isset($placed[$a['ref']])) {
                continue;
            }
            // The class first (the classification's own wording), then the account's name.
            for ($f = self::family((string) $a['class']) ?? self::family($a['label']), $seen = []; $f !== null && ! isset($seen[$f]); $f = $families[$f]['falls_to'] ?? null) {
                $seen[$f] = true;
                $lines = array_keys($itemFamily, $f, true);
                if (count($lines) === 1) {
                    [$placed[$a['ref']], $by[$a['ref']]] = [$lines[0], 'family'];
                    break;
                }
                if (count($lines) > 1) {
                    break;   // two of last year's lines of its kind: the accountant's choice
                }
            }
        }
        // Small items still on no line: on last year's «متنوعة» / «أخرى» line, when the note has one. Small by their kind
        // together (their family, else their class), so a material kind split over many accounts (travel) stays an item.
        $misc = array_keys($itemFamily, 'misc', true);
        $total = array_sum(array_map(fn ($a) => abs((float) $a['current']), $accounts));
        $share = (float) config('financial_statements.note_merge.misc_share', 0.05);
        if (count($misc) === 1 && $total > 0) {
            $groups = [];
            foreach ($accounts as $a) {
                if (! isset($placed[$a['ref']])) {
                    $groups[self::family((string) $a['class']) ?? self::family($a['label']) ?? 'class:'.$a['class']][] = $a;
                }
            }
            foreach ($groups as $group) {
                if (array_sum(array_map(fn ($a) => abs((float) $a['current']), $group)) <= $share * $total) {
                    foreach ($group as $a) {
                        [$placed[$a['ref']], $by[$a['ref']]] = [$misc[0], 'misc'];
                    }
                }
            }
        }

        return [$placed, $by];
    }

    /**
     * The note of a statement line in last year's PDF: by the note number printed on the file's row placed on the line,
     * else by the link the reading made from that row (number, title and amount).
     */
    public static function section(array $doc, array $file, array $fileRows, array $lines): ?int
    {
        foreach ($file['blocks'] ?? [] as $b => $block) {
            foreach ($block['rows'] as $r) {
                if (! in_array(PriorYearResolver::rowLine(['rows' => $fileRows], $b, $r), $lines, true)) {
                    continue;
                }
                if (($r['note'] ?? null) !== null) {
                    $number = Sections::number($r['note']);
                    foreach ($doc['sections'] as $s) {
                        if ($s['number'] === $number && $s['parent'] === null && ! $s['slip']) {
                            return $s['id'];
                        }
                    }
                }
                foreach ($doc['links'] ?? [] as $l) {
                    if ($l['statement'] === $block['statement'] && $l['row'] === ($r['statement_row'] ?? null) && $l['section'] !== null) {
                        return $l['section'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Last year's lines of a note: its first table of amounts by year (not a movement table), each line (headings and
     * totals are never items, R10) with its wording corrected (R16) and last year's amount (the PDF's current year).
     *
     * @return array<string, array{label: string, prior: float, page: int}> by key (the label; repeated labels numbered)
     */
    public static function items(array $doc, int $section, array $file): array
    {
        $tables = array_values(array_filter(Sections::tablesOf($section, $doc['sections'], $doc['tables']),
            fn ($t) => ! ($t['movement'] ?? false) && in_array('current', $t['roles']['roles'] ?? [], true)));
        if ($tables === []) {
            return [];
        }
        $t = $tables[0];
        $at = array_search('current', $t['roles']['roles'], true);
        $items = [];
        $sum = 0.0;
        foreach ($t['rows'] as $i => $r) {
            if ($r['kind'] !== 'line' || trim($r['label']) === '') {
                continue;
            }
            // A total read as a line («الإجمالي» last, or a row equal to the lines above it): never an item (R10).
            $amount = (float) ($r['cells'][$at]['amount'] ?? 0.0);
            if (self::isTotal((string) $r['label']) || (count($items) >= 2 && abs($amount) > Tables::TOLERANCE && abs($amount - $sum) <= Tables::TOLERANCE
                && ! array_filter(array_slice($t['rows'], $i + 1), fn ($x) => $x['kind'] === 'line' && trim($x['label']) !== ''))) {
                continue;
            }
            $sum += $amount;
            $label = Wording::apply(trim($r['label']), $doc['corrections'] ?? [], $file['wording_choices'] ?? []);
            $key = $label;
            for ($n = 2; isset($items[$key]); $n++) {
                $key = "{$label} ({$n})";
            }
            $items[$key] = ['label' => $label, 'prior' => (float) ($r['cells'][$at]['amount'] ?? 0.0), 'page' => $t['page']];
        }

        return $items;
    }

    /** A total's wording: «الإجمالي», «المجموع», «إجمالي المصروفات», «الصافي» (with a year or a currency after it). */
    public static function isTotal(string $label): bool
    {
        $text = trim((string) preg_replace('/[\d\x{0660}-\x{0669}\x{06F0}-\x{06F9}\/\-–:.,()]+|ريال(\s+سعودي)?|م$/u', ' ', $label));

        return preg_match('/^(ال)?(إجمالي|اجمالي|الاجمالي|مجموع|المجموع|صافي|الصافي)(\s+(ال)?(كلي|عام|مصروفات|المصروفات|مصاريف|المصاريف|الإيضاح|البند))?\s*$/u', $text) === 1;
    }

    /**
     * A name's family of meaning (config financial_statements.note_merge.families, the first that matches): salaries,
     * government fees, rent, utilities, … ; null when none does.
     */
    public static function family(string $name): ?string
    {
        foreach ((array) config('financial_statements.note_merge.families', []) as $key => $f) {
            if (preg_match($f['pattern'], $name) === 1) {
                return $key;
            }
        }

        return null;
    }
}
