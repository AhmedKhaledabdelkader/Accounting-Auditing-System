<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\AmountParser;
use App\Services\Statements\PriorNoteItems;
use App\Services\Statements\ScheduleContext;
use App\Support\ImportNormalizer;

/**
 * The partners as last year's capital note lists them (Phase 7, R14): the note explaining the capital line (else the note
 * titled so), its table whose columns are told apart by their arithmetic (shares × share value = amount, in any order;
 * the share value from the note's text when the table has none), each row a partner, its name as printed (corrected,
 * R11). The numbers checked: each row's product, their sum against the capital line.
 *
 * Which name is on which row the arithmetic can't tell (every name moved a row down still adds up): the trial balance
 * can, by a capital account in a partner's name holding that partner's amount. Without that, or with a name read
 * without its row (or a row without its name), the table is for the accountant to review before the final version, and
 * no name is put on a row by guessing.
 */
final class Partners
{
    /** Words of a name that tell no one apart. */
    private const COMMON = ['بن', 'بنت', 'ابن', 'ال', 'ابو', 'أبو', 'عبد', 'شركه', 'شركة', 'مؤسسه', 'مؤسسة'];

    /** Rows of a partners' table that are its header, not a name. */
    private const HEADER = '/الشركاء|الشريك|الاسم|البيان|اسم/u';

    /**
     * @return ?array{note: ?string, page: int, rows: list<array{name: string, shares: float, share_value: float, amount: float}>, names: list<string>,
     *         capital: ?float, status: string, sum_ok: bool} status: `read`, or `count_mismatch` (names and share rows don't pair up: no
     *         name is put on a row)
     */
    public static function read(array $file, array $fileRows = []): ?array
    {
        $doc = $file['document'] ?? null;
        $section = $doc === null ? null : PriorNoteItems::section($doc, $file, $fileRows, ['sofp.share_capital']) ?? self::titled($doc);
        if ($section === null) {
            return null;
        }
        $fix = fn (string $text) => trim(Wording::apply($text, $doc['corrections'] ?? [], $file['wording_choices'] ?? []));
        $text = implode(' ', array_merge(...array_map(fn ($s) => $s['paragraphs'], array_filter($doc['sections'], fn ($s) => $s['id'] === $section || $s['parent'] === $section))));
        $best = null;
        foreach (Sections::tablesOf($section, $doc['sections'], $doc['tables']) as $t) {
            if (($t['movement'] ?? false) || ($found = self::table($t, self::valueIn($text)) ?? self::byYear($t, self::valueIn($text))) === null) {
                continue;
            }
            if ($best === null || count($found['rows']) > count($best['rows'])) {
                $best = $found + ['page' => $t['page']];
            }
        }
        if ($best === null) {
            return null;
        }
        $line = Sections::lineOf($doc, $section);
        $capital = $line[$doc['years']['current'] ?? 0] ?? null;
        $sum = array_sum(array_column($best['rows'], 'amount'));
        $names = array_map($fix, $best['names']);
        $paired = $best['paired'];

        return ['note' => $doc['sections'][$section]['printed'], 'page' => $best['page'], 'year' => $best['year'] ?? null, 'former' => array_map($fix, $best['former'] ?? []),
            'rows' => array_map(fn ($r) => ['name' => $paired ? $fix($r['name']) : '', 'shares' => $r['shares'], 'share_value' => $r['share_value'], 'amount' => $r['amount']], $best['rows']),
            'names' => $names, 'capital' => $capital, 'status' => $paired ? 'read' : 'count_mismatch',
            'sum_ok' => $capital === null || abs(abs($capital) - $sum) <= Tables::TOLERANCE];
    }

    /**
     * Each partner's row confirmed by the trial balance: a capital account in the partner's name (most of its telling
     * words, no other partner's as many) holding the row's amount (at the year's start, else its end). A capital account
     * in one partner's name holding another row's amount disagrees.
     *
     * @return array{status: string, confirmed: list<int>, disagree: list<array{row: int, account: string}>} status: `confirmed` (every
     *         row), `count_mismatch`, `disagrees`, or `unconfirmed`
     */
    public static function confirm(array $partners, ?ScheduleContext $ctx): array
    {
        $out = ['status' => 'unconfirmed', 'confirmed' => [], 'disagree' => []];
        if ($partners['status'] === 'count_mismatch') {
            return ['status' => 'count_mismatch'] + $out;
        }
        $words = array_map(fn ($r) => self::telling($r['name']), $partners['rows']);
        foreach ($ctx === null ? [] : $ctx->rowsOn('sofp.share_capital') as $n => $share) {
            $account = (string) ($ctx->rows[$n]['account_name'] ?? '');
            $have = self::telling($account);
            $scores = array_map(fn ($w) => $w === [] ? 0 : count(array_intersect($w, $have)), $words);
            $top = max($scores ?: [0]);
            $who = array_keys($scores, $top, true);
            if ($top < 2 || count($who) !== 1) {
                continue;   // not one partner's name
            }
            $balance = $ctx->sum([$n => $share], 'credit');
            $amount = abs((float) (abs($balance['opening'] ?? 0.0) > Tables::TOLERANCE ? $balance['opening'] : ($balance['closing'] ?? 0.0)));
            $rowsWith = array_keys(array_filter($partners['rows'], fn ($r) => abs($r['amount'] - $amount) <= Tables::TOLERANCE));
            if (in_array($who[0], $rowsWith, true)) {
                $out['confirmed'][] = $who[0];
            } elseif ($rowsWith !== []) {
                $out['disagree'][] = ['row' => $who[0], 'account' => $account];
            }
        }
        $out['confirmed'] = array_values(array_unique($out['confirmed']));
        $out['status'] = $out['disagree'] !== [] ? 'disagrees' : (count($out['confirmed']) === count($partners['rows']) && $partners['rows'] !== [] ? 'confirmed' : 'unconfirmed');

        return $out;
    }

    /**
     * A partners' table: the three columns whose arithmetic holds on every row with amounts (shares × share value =
     * amount), or two (shares, amount) with the share value of the note's text, or the same quotient on every row.
     *
     * @return ?array{rows: list<array>, names: list<string>, paired: bool}
     */
    private static function table(array $t, ?float $valueInText): ?array
    {
        $lines = array_values(array_filter($t['rows'], fn ($r) => $r['kind'] === 'line'));
        $width = count($t['columns']);
        if ($lines === [] || $width < 2) {
            return null;
        }
        $at = fn (array $r, int $c) => $r['cells'][$c]['amount'] ?? null;
        $fits = function (int $s, ?int $v, int $a) use ($lines, $at, $valueInText) {
            foreach ($lines as $r) {
                [$shares, $amount] = [$at($r, $s), $at($r, $a)];
                $value = $v === null ? $valueInText : $at($r, $v);
                if ($shares === null || $amount === null || $value === null || $shares <= 0 || abs($shares * $value - $amount) > Tables::TOLERANCE) {
                    return false;
                }
            }

            return true;
        };
        // Shares × value = amount holds both ways round: the value is the column the same on every row, the text's
        // value, headed «قيمة»; the shares the one headed «عدد».
        $header = fn (?int $c) => $c === null ? '' : $t['columns'][$c]['header'];
        $same = fn (?int $c) => $c === null || count(array_unique(array_map(fn ($r) => $at($r, $c), $lines), SORT_REGULAR)) === 1;
        $score = fn (int $s, ?int $v) => 4 * (int) ($valueInText !== null && ($v === null || array_filter($lines, fn ($r) => abs($at($r, $v) - $valueInText) > 0.005) === []))
            + 2 * (int) ($same($v) && ! $same($s)) + (int) (preg_match('/قيمة/u', $header($v)) === 1 || $v === null) + (int) (preg_match('/عدد/u', $header($s)) === 1);
        $found = null;
        $best = -1;
        foreach (range(0, $width - 1) as $s) {
            foreach (range(0, $width - 1) as $a) {
                if ($s === $a) {
                    continue;
                }
                foreach ([...array_diff(range(0, $width - 1), [$s, $a]), null] as $v) {
                    if ($fits($s, $v, $a) && ($v !== null || $valueInText !== null) && $score($s, $v) > $best) {
                        [$found, $best] = [[$s, $v, $a], $score($s, $v)];
                    }
                }
            }
        }
        // Two columns and no value in the text: the same quotient on every row is the share value.
        if ($found === null && $valueInText === null) {
            foreach (range(0, $width - 1) as $s) {
                foreach (range(0, $width - 1) as $a) {
                    $q = array_unique(array_map(fn ($r) => ($at($r, $s) ?? 0) > 0 && $at($r, $a) !== null ? round($at($r, $a) / $at($r, $s), 2) : null, $lines), SORT_REGULAR);
                    if ($s !== $a && count($q) === 1 && reset($q) !== null && reset($q) > 1 && ! $same($s) && $found === null) {
                        [$found, $valueInText] = [[$s, null, $a], (float) reset($q)];
                    }
                }
            }
        }
        if ($found === null) {
            return null;
        }
        [$s, $v, $a] = $found;
        $rows = array_map(fn ($r) => ['name' => trim($r['label']), 'shares' => (float) $at($r, $s), 'share_value' => (float) ($v === null ? $valueInText : $at($r, $v)),
            'amount' => (float) $at($r, $a)], $lines);
        // Names printed without their row (a row of a name only) or rows without a name: they don't pair up.
        $nameOnly = array_values(array_filter($t['rows'], fn ($r) => $r['kind'] === 'heading' && trim($r['label']) !== '' && preg_match(self::HEADER, $r['label']) !== 1));
        $unnamed = array_filter($rows, fn ($r) => $r['name'] === '');
        $names = array_values(array_filter(array_map(fn ($r) => in_array($r, $lines, true) || in_array($r, $nameOnly, true) ? trim($r['label']) : '', $t['rows'])));   // as printed

        return ['rows' => $rows, 'names' => $names, 'paired' => $nameOnly === [] && $unnamed === []];
    }

    /**
     * A partners' table by year, the shares printed in each name («شركة … (1,200 حصة)») and an amount column per year
     * (an ownership changed during the year: the partners differ between the years). The latest year's amount column
     * whose arithmetic holds (shares × the text's share value, else the same quotient on every row) gives this table's
     * partners: the rows with an amount in it. The rows with none there but an amount in an earlier year are former
     * partners. A percentage column is never an amount.
     *
     * @return ?array{rows: list<array>, names: list<string>, paired: bool, year: int, former: list<string>}
     */
    private static function byYear(array $t, ?float $valueInText): ?array
    {
        $shares = fn (array $r) => preg_match('/\(?\s*([\d,]+)\s*حص[ةهص]|حص[ةهص]\s*:?\s*([\d,]+)/u', AmountParser::asciiDigits($r['label']), $m)
            ? (float) str_replace(',', '', $m[1] !== '' ? $m[1] : $m[2]) : null;
        // A row naming its shares is a partner's, whatever its arithmetic made it (two partners with the same holding
        // look like a line and its total).
        $lines = array_values(array_filter($t['rows'], fn ($r) => $r['kind'] !== 'heading' && $shares($r) !== null));
        if ($lines === [] || count($lines) < count(array_filter($t['rows'], fn ($r) => $r['kind'] === 'line' && trim($r['label']) !== ''))) {
            return null;
        }
        $years = array_map(fn ($c) => Tables::years($c['header']), $t['columns']);
        $money = fn (int $c) => array_filter($lines, fn ($r) => in_array($r['cells'][$c]['status'] ?? 'empty', ['percent', 'range', 'text'], true)) === [];
        $candidates = array_keys(array_filter($years, fn ($y, $c) => count(array_unique($y)) === 1 && $money($c), ARRAY_FILTER_USE_BOTH));
        usort($candidates, fn ($a, $b) => $years[$b][0] <=> $years[$a][0] ?: $a <=> $b);
        foreach ($candidates as $c) {
            $held = array_values(array_filter($lines, fn ($r) => abs($r['cells'][$c]['amount'] ?? 0.0) > Tables::TOLERANCE));
            $quotients = array_unique(array_map(fn ($r) => round(($r['cells'][$c]['amount'] ?? 0.0) / $shares($r), 2), $held));
            $value = $valueInText ?? (count($quotients) === 1 ? (float) reset($quotients) : null);
            if ($held === [] || $value === null || array_filter($held, fn ($r) => abs($shares($r) * $value - $r['cells'][$c]['amount']) > Tables::TOLERANCE) !== []) {
                continue;
            }
            $name = fn (array $r) => trim((string) preg_replace('/\s*\(?\s*[\d,٬٠-٩]+\s*حص[ةهص]\s*\)?.*$|\s*\(?\s*حص[ةهص]\s*:?\s*[\d,٬٠-٩]+\s*\)?.*$/u', '', $r['label']));
            $rows = array_map(fn ($r) => ['name' => $name($r), 'shares' => $shares($r), 'share_value' => $value, 'amount' => (float) $r['cells'][$c]['amount']], $held);
            $former = array_values(array_map($name, array_filter($lines, fn ($r) => ! in_array($r, $held, true))));

            return ['rows' => $rows, 'names' => array_column($rows, 'name'), 'paired' => true, 'year' => $years[$c][0], 'former' => $former];
        }

        return null;
    }

    /** The share value the note's text says («قيمة كل حصة 1,000 ريال», «قيمة الحصة (5000) ريال»). */
    private static function valueIn(string $text): ?float
    {
        $digits = AmountParser::asciiDigits($text);

        return preg_match('/قيمة\s+(?:كل\s+)?(?:ال)?حص[ةه](?:\s+الواحدة)?\s*(?:[:\-]\s*)?\(?\s*([\d,]+(?:\.\d+)?)\s*\)?/u', $digits, $m)
            || preg_match('/حص[ةه]\s+(?:ب|بال)?قيمة\s+(?:اسمية\s+)?(?:قدرها\s+)?\(?\s*([\d,]+(?:\.\d+)?)\s*\)?\s*(?:ريال|ر\.?\s*س)/u', $digits, $m)
            ? (float) str_replace(',', '', $m[1]) : null;
    }

    /** A name's telling words, normalised. */
    public static function telling(string $name): array
    {
        return array_values(array_unique(array_filter(explode(' ', ImportNormalizer::text($name)), fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::COMMON, true))));
    }

    /** The note titled as the capital note, when no statement line links to one. */
    private static function titled(array $doc): ?int
    {
        foreach ($doc['sections'] as $s) {
            if ($s['parent'] === null && preg_match('/^(ال)?(رأس|راس)\s+(ال)?مال/u', $s['title'])) {
                return $s['id'];
            }
        }

        return null;
    }
}
