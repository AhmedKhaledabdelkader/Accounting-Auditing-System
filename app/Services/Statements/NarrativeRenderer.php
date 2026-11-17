<?php

namespace App\Services\Statements;

use App\Support\Amounts;

/**
 * The notes' text and tables (PR 11, plan Q11): the template's wording with its placeholders filled, its blocks kept or
 * dropped, and the user's own wording where they replaced a paragraph. No AI: every word is the template's or the user's.
 *
 * - A paragraph is keyed `<note>.<n>`: its place among the note's paragraphs in the template (stable per template version).
 * - A heading the template numbers under its note gets its number, «5-1», counting the note's shown headings (as Word does).
 * - A placeholder with no value is shown as «[ناقص]» and reported; nothing is made up.
 * - Tables: a row holding an item placeholder (e.g. `${inventory_label}`) is repeated per item; a row whose amounts are all
 *   0 in both years is hidden, and so is a column whose amounts are all 0 (Q13). In a first year a table has one year: its
 *   prior-year column (headed `${py}`) and the empty spacer beside it go (plan §5).
 * - An edited paragraph keeps the user's text; `outdated` says the template's text has changed since (figures moved).
 */
final class NarrativeRenderer
{
    public const MISSING = '[ناقص]';

    /**
     * @param array                         $template  NotesTemplate::load()
     * @param list<string>                  $keys      the notes' keys, in template order
     * @param array<string, array>          $meta      note key → {visible, number, visibility, references}
     * @param array<string, bool>           $blocks    block name → kept
     * @param array<string, string|Figure|false|null> $values placeholder → value (null: missing; false: drop its paragraph or row)
     * @param array<string, list<array>>    $items     item placeholder → the items' values
     * @param array<string, array{text: string, base: string}> $overrides paragraph key → the user's text
     * @param bool                          $firstYear no prior year: the tables' prior-year columns go
     * @return array{notes: list<array>, missing: array<string, list<string>>, note_refs: list<array{paragraph: string, note: string}>}
     *         missing: note key → placeholders without a value in its shown content
     */
    public function render(array $template, array $keys, array $meta, array $blocks, array $values, array $items, array $overrides, bool $firstYear = false): array
    {
        $notes   = [];
        $missing = [];
        $refs    = [];
        foreach ($template['notes'] as $i => $t) {
            $key  = $keys[$i];
            $m    = $meta[$key];
            $out  = [];
            $p    = 0;
            $sub  = 0;
            foreach ($t['items'] as $item) {
                $shown = $m['visible'] && array_reduce($item['blocks'], fn ($ok, $b) => $ok && ($blocks[$b] ?? false), true);
                if ($item['type'] === 'paragraph') {
                    $p++;
                    if (! $shown || $this->dropped($item['text'], $values)) {
                        continue;
                    }
                    $pkey = "{$key}.{$p}";
                    [$text, $gaps] = $this->fill($item['text'], $values);
                    foreach ($this->noteRefs($item['text']) as $note) {
                        $refs[] = ['paragraph' => $pkey, 'note' => $note];
                    }
                    $override = $overrides[$pkey] ?? null;
                    if ($override === null) {
                        $missing[$key] = [...($missing[$key] ?? []), ...$gaps];
                    }
                    $out[] = ['type' => 'text', 'key' => $pkey, 'style' => $item['style'],
                        'number' => ($item['sub'] ?? false) && $m['number'] !== null ? $m['number'].'-'.++$sub : null,
                        'text_ar' => $override['text'] ?? $text, 'template_ar' => $text,
                        'edited' => $override !== null, 'outdated' => $override !== null && $override['base'] !== $text];
                    continue;
                }
                if ($shown) {
                    [$table, $gaps] = $this->table($item, $values, $items, $firstYear);
                    $missing[$key] = [...($missing[$key] ?? []), ...$gaps];
                    if ($table['rows'] !== []) {
                        $out[] = $table;
                    }
                }
            }
            $notes[] = ['key' => $key, 'number' => $m['number'], 'visible' => $m['visible'], 'visibility' => $m['visibility'],
                'title_ar' => $t['title'], 'references' => $m['references'], 'blocks' => $out];
            if (isset($missing[$key])) {
                $missing[$key] = array_values(array_unique($missing[$key]));
            }
        }

        return ['notes' => $notes, 'missing' => array_filter($missing), 'note_refs' => $refs];
    }

    /** Paragraph keys of the template: note key → number of paragraphs (for validating the user's overrides). */
    public static function paragraphCounts(array $template, array $keys): array
    {
        $out = [];
        foreach ($template['notes'] as $i => $t) {
            $out[$keys[$i]] = count(array_filter($t['items'], fn ($x) => $x['type'] === 'paragraph'));
        }
        return $out;
    }

    /** Whole riyals as written everywhere (Amounts: «-» for zero, the negatives setting), Western digits (Q27). */
    public static function money(Figure $f): string
    {
        return $f->isMissing() ? self::MISSING : Amounts::whole($f->amount);
    }

    /** @return array{0: string, 1: list<string>} the text, and the placeholders without a value */
    private function fill(string $text, array $values): array
    {
        $gaps = [];
        $out  = preg_replace_callback('/\$\{([a-z0-9_]+)\}/', function ($m) use ($values, &$gaps) {
            $v = $values[$m[1]] ?? null;
            if ($v instanceof Figure) {
                if ($v->isMissing()) {
                    $gaps[] = $m[1];
                }
                return self::money($v);
            }
            if ($v === null) {
                $gaps[] = $m[1];
                return self::MISSING;
            }
            return $v;
        }, $text);

        return [$out, $gaps];
    }

    /** A placeholder whose value is false drops its paragraph or table row (e.g. the useful life of a category the company doesn't have). */
    private function dropped(string $text, array $values): bool
    {
        preg_match_all('/\$\{([a-z0-9_]+)\}/', $text, $m);
        return array_filter($m[1], fn ($p) => ($values[$p] ?? null) === false) !== [];
    }

    /** Notes a paragraph refers to by number (`${note_no_x}`). */
    private function noteRefs(string $text): array
    {
        preg_match_all('/\$\{note_no_([a-z_]+)\}/', $text, $m);
        return $m[1];
    }

    /**
     * The prior-year columns of a first year's table: each column headed `${py}`, and the empty spacer next to it on the
     * side of the current year's. They go from the rows as wide as that header; rows of another width (merged cells)
     * keep their cells.
     *
     * @return array<int, true> column → dropped
     */
    private function oneYear(array $item): array
    {
        $header = collect($item['rows'])->first(fn ($r) => str_contains(implode(' ', $r), '${py}'));
        if ($header === null) {
            return [];
        }
        $cy   = collect($header)->search(fn ($c) => str_contains($c, '${cy}'));
        $drop = [];
        foreach ($header as $i => $cell) {
            if (str_contains($cell, '${py}')) {
                $drop[$i] = true;
                $spacer = $cy !== false && $cy < $i ? $i - 1 : $i + 1;
                if (isset($header[$spacer]) && trim($header[$spacer]) === '') {
                    $drop[$spacer] = true;
                }
            }
        }

        return $drop;
    }

    /**
     * A table's repeated columns (Phase 9): a cell holding just `${name}`, where the values have `name__1`, `name__2`, …
     * (not `name`), is a column repeated per set, in every row as wide as its row; each copy's placeholders take the
     * set's number (`${x}` → `${x__2}`). Rows of another width (a title across the table) keep their cells.
     *
     * @param list<list<string>> $rows the table's cells' texts
     * @return list<list<array{0: int, 1: ?int}>> per row, its cells: [the template's cell, the copy's number or null]
     */
    public static function repeats(array $rows, array $values): array
    {
        $repeat = [];   // row width → cell → copies
        foreach ($rows as $cells) {
            foreach ($cells as $c => $text) {
                if (preg_match('/^\$\{([a-z0-9_]+)\}$/', trim($text), $m) && ! array_key_exists($m[1], $values) && array_key_exists("{$m[1]}__1", $values)) {
                    for ($n = 1; array_key_exists("{$m[1]}__".($n + 1), $values); $n++);
                    $repeat[count($cells)][$c] = $n;
                }
            }
        }

        return array_map(fn ($cells) => array_merge(...array_map(fn ($c) => isset($repeat[count($cells)][$c])
            ? array_map(fn ($i) => [$c, $i], range(1, $repeat[count($cells)][$c])) : [[$c, null]], array_keys($cells)) ?: [[]]), $rows);
    }

    /** A repeated column's copy: its placeholders numbered (`${x}` → `${x__2}`). */
    public static function numbered(string $text, int $copy): string
    {
        return (string) preg_replace('/\$\{([a-z0-9_]+)\}/', '${$1__'.$copy.'}', $text);
    }

    /**
     * A table as shown, in the template's terms (the docx export lays out the file's table the same way): each shown row
     * with the template row it comes from (`source`) and the item it repeats for (`set`), and its shown cells keyed by
     * the template's column, in the template's order.
     *
     * - A column of a set's placeholders is repeated per set (repeats); a row holding an item placeholder is repeated per
     *   item; a row with a placeholder whose value is false goes.
     * - A first year's table loses its prior-year columns (oneYear).
     * - A row whose amounts are all 0 goes; in a table of categories (no year columns) so does a column whose amounts
     *   are all 0 (Q13); a table left with headings only is empty.
     *
     * @return array{rows: list<array{source: int, set: array, cells: array<int, array{amount: Figure}|array{text_ar: string}>}>, gaps: list<string>}
     */
    public function layout(array $item, array $values, array $items, bool $firstYear): array
    {
        $plan = self::repeats($item['rows'], $values);
        $item['rows'] = array_map(fn ($cells, $r) => array_map(fn ($p) => $p[1] === null ? $cells[$p[0]] : self::numbered($cells[$p[0]], $p[1]), $plan[$r]),
            $item['rows'], array_keys($item['rows']));
        $gaps = [];
        $rows = [];
        $yearDrop = $firstYear ? $this->oneYear($item) : [];
        $headerWidth = $yearDrop ? count(collect($item['rows'])->first(fn ($r) => str_contains(implode(' ', $r), '${py}'))) : 0;
        foreach ($item['rows'] as $source => $cells) {
            preg_match_all('/\$\{([a-z0-9_]+)\}/', implode(' ', $cells), $m);
            $itemKey = collect($m[1])->first(fn ($p) => array_key_exists($p, $items));
            $sets    = $itemKey !== null ? $items[$itemKey] : [[]];
            foreach ($sets as $set) {
                if ($this->dropped(implode(' ', $cells), $set + $values)) {
                    continue;
                }
                $row = [];
                foreach ($cells as $c => $cell) {
                    if (count($cells) === $headerWidth && isset($yearDrop[$c])) {
                        continue;
                    }
                    $vals = $set + $values;
                    if (preg_match('/^\$\{([a-z0-9_]+)\}$/', $cell, $one) && ($vals[$one[1]] ?? null) instanceof Figure) {
                        $f = $vals[$one[1]];
                        if ($f->isMissing()) {
                            $gaps[] = $one[1];
                        }
                        $row[$c] = ['amount' => $f];
                        continue;
                    }
                    [$text, $g] = $this->fill($cell, $vals);
                    $gaps = [...$gaps, ...$g];
                    $row[$c] = ['text_ar' => $text];
                }
                $rows[] = ['source' => $source, 'set' => $set, 'cells' => $row];
            }
        }

        $amounts = fn (array $r) => array_filter($r['cells'], fn ($c) => isset($c['amount']));
        $hadAmounts = array_filter($rows, fn ($r) => $amounts($r) !== []) !== [];
        // Zero in both years: the row goes, and a column whose amounts are all zero.
        $zero = fn (array $c) => isset($c['amount']) && ! $c['amount']->isMissing() && round($c['amount']->amount) == 0;
        $rows = array_values(array_filter($rows, fn ($r) => $amounts($r) === [] || array_filter($amounts($r), fn ($c) => ! $zero($c)) !== []));
        // Columns: only a table of categories (no year columns) hides a column of zeros.
        $all   = implode(' ', array_merge(...$item['rows']));
        $years = str_contains($all, '${cy}') || str_contains($all, '${py}');
        $drop  = [];
        if (! $years) {
            foreach (array_unique(array_merge(...array_map(fn ($r) => array_keys($r['cells']), $rows ?: [['cells' => []]]))) as $c) {
                $column = array_filter(array_map(fn ($r) => $r['cells'][$c] ?? null, $rows), fn ($x) => isset($x['amount']));
                if (count($column) >= 2 && array_filter($column, fn ($x) => ! $zero($x)) === []) {
                    $drop[$c] = true;
                }
            }
        }
        if ($drop) {
            $rows = array_map(fn ($r) => ['cells' => array_diff_key($r['cells'], $drop)] + $r, $rows);
        }
        // Rows only of headings left (every amount row gone) mean an empty table.
        if ($hadAmounts && ! array_filter($rows, fn ($r) => $amounts($r) !== [])) {
            $rows = [];
        }

        return ['rows' => $rows, 'gaps' => array_values(array_unique($gaps))];
    }

    /** @return array{0: array, 1: list<string>} */
    private function table(array $item, array $values, array $items, bool $firstYear): array
    {
        ['rows' => $rows, 'gaps' => $gaps] = $this->layout($item, $values, $items, $firstYear);
        // The template's cells run left to right unless the table is right to left; the page is right to left.
        $rows = array_map(fn ($r) => ['cells' => $item['rtl'] ? array_values($r['cells']) : array_reverse(array_values($r['cells']))], $rows);

        return [['type' => 'table', 'rows' => $rows], $gaps];
    }
}
