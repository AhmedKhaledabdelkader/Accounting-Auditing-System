<?php

namespace App\Services\Statements;

use App\Services\Statements\PriorPdf\Tables;

/**
 * Accounts this year's trial balance puts on another line than last year's statements did (Phase 5 follow-up): the
 * opening check shows lines apart from last year's closing; the accounts that explain them are proposed back on last
 * year's line, so the statements stay comparable. Found, on each line with a note last year: an account on another line
 * named as one of the note's lines (its name or class: most of the line's words), or the one account whose opening
 * equals one; then, for a note line no account matches, the one set of accounts whose openings add up to it; then, for
 * what a line is still short, the one set adding up to that. An account is proposed only on the side its balance shows
 * (a debit balance never to a credit line), and only when its own line comes nearer last year's figure; a set (not
 * matched to a note line by name or amount) only when the line it goes to comes nearer too. The accountant accepts a
 * proposal (an account override) or not; nothing moves by itself.
 */
final class ComparableLines
{
    private const MOST = 6;   // accounts added up together, at most

    /**
     * What a balance is, by the words of its name or class, or a note line's: an account matched by its amount alone (its
     * name differs) is never proposed to a line of another nature (deferred revenue to a tax line, a receivable to a
     * payable): the amounts agree by chance there. Unknown on either side: no conflict.
     */
    private const NATURES = [
        'tax'        => '/ضريب|زكا[ةه]|زكوي|القيم[ةه] المضاف/u',
        'revenue'    => '/[اإأ]يراد|مبيعات/u',
        'expense'    => '/مصروف|مصاريف|تكلف[ةه]/u',
        'receivable' => '/مدين|عملاء|مستحق[ةه]? من|[اأ]وراق قبض|دفعات مقدم[ةه] (لل|ل)?موردين/u',
        'payable'    => '/دائن|مورد|مستحق[ةه]? (ل|لل|[اإ]لى)|[اأ]وراق دفع/u',
        'related'    => '/[اأ]طراف ذات علاق|شريك|شركاء/u',
        'employee'   => '/موظف|عامل|عمال|ع[ُ]?هد|س[ُ]?لف|رواتب|[اأ]جور/u',
        'cash'       => '/نقد|بنك|بنوك|صندوق|خزين[ةه]/u',
        'inventory'  => '/مخزون|بضاع/u',
        'fixed'      => '/[اأ]صول ثابت|ممتلكات|مجمع [اإ]هلاك/u',
        'loan'       => '/قرض|قروض|تمويل|مرابح/u',
    ];

    /**
     * @param list<array> $check OpeningCheck::lines()
     * @param ?array $file the file of last year's statements (blocks, document)
     * @param array $fileRows the user's choices on its rows
     * @return list<array{line: string, label: string, short: float, accounts: list<array{row: int, name: string, from: string, from_label: string, amount: float, by: string, item: ?string}>}>
     */
    public static function proposals(LineCatalogue $catalogue, ScheduleContext $ctx, array $check, ?array $file, array $fileRows = []): array
    {
        $diff = array_column($check, null, 'line');
        $shown = fn (string $line, float $raw) => $catalogue->side($line) === 'debit' ? $raw : -$raw;   // as the line shows it
        // Accounts wholly on a line apart from last year's figure, with their opening.
        $candidates = [];
        foreach ($ctx->mapping['rows'] as $n => $m) {
            $on = $m['lines']['opening'] ?? $m['lines']['closing'] ?? [];
            if (ApprovedOpening::isRow($n) || count($on) !== 1 || ($diff[$line = array_key_first($on)]['kind'] ?? 'equal') === 'equal'
                || ($raw = $ctx->profile->opening($ctx->rows[$n])) === null || abs($raw) <= Tables::TOLERANCE) {
                continue;
            }
            $candidates[$n] = ['row' => $n, 'name' => (string) ($ctx->rows[$n]['account_name'] ?? $n), 'class' => (string) ($ctx->rows[$n]['sub_class'] ?? ''), 'from' => $line, 'raw' => $raw];
        }
        if ($candidates === []) {
            return [];
        }
        $doc = $file['document'] ?? null;
        $over = fn (string $line) => $diff[$line]['opening'] - $diff[$line]['prior'] > Tables::TOLERANCE * 1.5;
        // The account's own line nearer last year's figure without it.
        $nearer = fn (array $c) => abs($diff[$c['from']]['opening'] - $shown($c['from'], $c['raw']) - $diff[$c['from']]['prior'])
            < abs($diff[$c['from']]['opening'] - $diff[$c['from']]['prior']);
        // On the line's side: a debit balance to a debit line, a credit balance to a credit one.
        $fits = fn (array $c, string $line) => $c['from'] !== $line && $shown($line, $c['raw']) > 0 && $nearer($c);

        $out = [];
        $taken = [];
        foreach ($diff as $line => $t) {
            $chosen = [];
            // Last year's note lines of this line: accounts named as one, or the one account whose opening equals one.
            $section = $doc === null ? null : PriorNoteItems::section($doc, $file, $fileRows, [$line]);
            $items = $section === null ? [] : PriorNoteItems::items($doc, $section, $file);
            $matched = [];
            foreach ($items as $key => $item) {
                $free = array_filter($candidates, fn ($c) => ! isset($taken[$c['row']]) && $fits($c, $line));
                $named = array_filter($free, fn ($c) => self::named($c, $item['label']) && self::bestItem($c, $items) === $key);
                $equal = array_filter($free, fn ($c) => abs($shown($line, $c['raw']) - $item['prior']) <= Tables::TOLERANCE && ! self::conflicts($c, $item['label']));
                $hits = $named !== [] ? $named : (count($equal) === 1 ? $equal : []);
                foreach ($hits as $c) {
                    $chosen[$c['row']] = $c + ['by' => $named !== [] ? 'name' : 'amount', 'item' => $item['label']];
                    $taken[$c['row']] = true;
                    $matched[$key] = true;
                }
            }
            // A note line no account matches: the one set of accounts from lines over last year adding up to it.
            foreach ($items as $key => $item) {
                if (isset($matched[$key]) || $item['prior'] <= Tables::TOLERANCE) {
                    continue;
                }
                $pool = array_values(array_filter($candidates, fn ($c) => ! isset($taken[$c['row']]) && $over($c['from']) && $fits($c, $line) && $shown($line, $c['raw']) <= $item['prior'] + Tables::TOLERANCE
                    && ! self::conflicts($c, $item['label'])));
                $sets = self::sums($pool, fn ($c) => $shown($line, $c['raw']), $item['prior']);
                if (count($sets) === 1) {
                    foreach ($sets[0] as $c) {
                        $chosen[$c['row']] = $c + ['by' => 'sum', 'item' => $item['label']];
                        $taken[$c['row']] = true;
                    }
                }
            }
            // What the line is still short: the one set adding up to it.
            $need = round($t['prior'] - $t['opening'], 2);
            $left = round($need - array_sum(array_map(fn ($c) => $shown($line, $c['raw']), $chosen)), 2);
            if ($need > Tables::TOLERANCE * 1.5 && $t['opening'] >= 0 && $left > Tables::TOLERANCE) {
                $pool = array_values(array_filter($candidates, fn ($c) => ! isset($taken[$c['row']]) && $over($c['from']) && $fits($c, $line) && $shown($line, $c['raw']) <= $left + Tables::TOLERANCE
                    && ! self::conflicts($c, $t['label'])));
                $sets = self::sums($pool, fn ($c) => $shown($line, $c['raw']), $left);
                if (count($sets) === 1) {
                    foreach ($sets[0] as $c) {
                        $chosen[$c['row']] = $c + ['by' => 'sum', 'item' => null];
                        $taken[$c['row']] = true;
                    }
                }
            }
            if ($chosen === []) {
                continue;
            }
            // Sets not tied to a note line by name or amount: kept only when the line they go to comes nearer too.
            $after = $t['opening'] + array_sum(array_map(fn ($c) => $shown($line, $c['raw']), $chosen));
            if (array_filter($chosen, fn ($c) => $c['by'] === 'sum' && $c['item'] === null) !== [] && abs($after - $t['prior']) >= abs($t['opening'] - $t['prior'])) {
                foreach ($chosen as $c) {
                    unset($taken[$c['row']]);
                }
                continue;
            }
            $out[] = ['line' => $line, 'label' => $t['label'], 'short' => $need, 'accounts' => array_values(array_map(fn ($c) => ['row' => $c['row'], 'name' => $c['name'],
                'from' => $c['from'], 'from_label' => $diff[$c['from']]['label'], 'amount' => round($shown($line, $c['raw']), 2) + 0.0, 'by' => $c['by'], 'item' => $c['item'],
                // Matched by its amount alone, the names differ: accepted only by its own click, never with everything.
                'amount_only' => $c['by'] !== 'name'], $chosen))];
        }

        return $out;
    }

    /** The natures of a text (NATURES), by its words. */
    private static function natures(string $text): array
    {
        $text = StatementSynonyms::wording($text).' '.$text;

        return array_keys(array_filter(self::NATURES, fn ($pattern) => preg_match($pattern, $text) === 1));
    }

    /** An account (its name and class) of another nature than the note line or line it would go to by amount. */
    private static function conflicts(array $c, string $target): bool
    {
        $mine = self::natures($c['name'].' '.$c['class']);
        $theirs = self::natures($target);

        return $mine !== [] && $theirs !== [] && array_intersect($mine, $theirs) === [];
    }

    /** An account named as a note line: most of the line's words (its own name's, or its class's). */
    private static function named(array $c, string $label): bool
    {
        return self::share($c, $label) >= 2 / 3;
    }

    /** The share of a note line's words in the account's name or class (normalised words). */
    private static function share(array $c, string $label): float
    {
        $want = array_filter(explode(' ', StatementSynonyms::wording($label)), fn ($w) => mb_strlen($w) > 2);
        $have = [...explode(' ', StatementSynonyms::wording($c['name'])), ...explode(' ', StatementSynonyms::wording($c['class']))];

        return $want === [] ? 0.0 : count(array_intersect($want, $have)) / count($want);
    }

    /** The note line an account's name says most, when one says it more than the others. */
    private static function bestItem(array $c, array $items): ?string
    {
        $scores = array_map(fn ($i) => self::share($c, $i['label']), $items);
        arsort($scores);
        $top = array_slice($scores, 0, 2, true);

        return count($top) === 1 || reset($top) > next($top) ? array_key_first($top) : null;
    }

    /**
     * A line over last year's figure whose accounts include exactly one set adding up to last year's figure: the other
     * accounts weren't on it last year. Listed for the accountant to send to one of the lines still short (no default:
     * the amounts don't say which).
     *
     * @param list<array> $proposals self::proposals() (their accounts are settled already)
     * @return list<array{from: string, from_label: string, kept: float, accounts: list<array{row: int, name: string, amount: float}>, targets: list<array{line: string, label: string, short: float}>}>
     */
    public static function strays(LineCatalogue $catalogue, ScheduleContext $ctx, array $check, array $proposals): array
    {
        $settled = [];
        foreach ($proposals as $p) {
            foreach ($p['accounts'] as $a) {
                $settled[$a['row']] = true;
            }
        }
        $targets = array_values(array_map(fn ($l) => ['line' => $l['line'], 'label' => $l['label'], 'short' => round($l['prior'] - $l['opening'], 2)],
            array_filter($check, fn ($l) => $l['prior'] - $l['opening'] > Tables::TOLERANCE * 1.5 && $l['opening'] >= 0)));
        if ($targets === []) {
            return [];
        }
        $out = [];
        foreach (array_filter($check, fn ($l) => $l['opening'] - $l['prior'] > Tables::TOLERANCE * 1.5) as $o) {
            $accounts = [];
            foreach ($ctx->mapping['rows'] as $n => $m) {
                $on = $m['lines']['opening'] ?? $m['lines']['closing'] ?? [];
                $raw = $ctx->profile->opening($ctx->rows[$n]);
                if (! ApprovedOpening::isRow($n) && count($on) === 1 && isset($on[$o['line']]) && ! isset($settled[$n]) && $raw !== null && abs($raw) > Tables::TOLERANCE) {
                    $accounts[] = ['row' => $n, 'name' => (string) ($ctx->rows[$n]['account_name'] ?? $n),
                        'amount' => round($catalogue->side($o['line']) === 'debit' ? $raw : -$raw, 2) + 0.0];
                }
            }
            usort($accounts, fn ($a, $b) => abs($b['amount']) <=> abs($a['amount']));
            $kept = self::sums($accounts, fn ($a) => $a['amount'], $o['prior'], count($accounts));
            if (count($kept) !== 1) {
                continue;
            }
            $stay = array_flip(array_column($kept[0], 'row'));
            $out[] = ['from' => $o['line'], 'from_label' => $o['label'], 'kept' => $o['prior'],
                'accounts' => array_values(array_filter($accounts, fn ($a) => ! isset($stay[$a['row']]))), 'targets' => $targets];
        }

        return $out;
    }

    /**
     * The sets of up to MOST items whose values add up to $target (within rounding).
     *
     * @return list<list<array>>
     */
    private static function sums(array $pool, callable $value, float $target, int $most = self::MOST): array
    {
        $found = [];
        $n = count($pool);
        $budget = 500000;   // sets tried, at most: past it, nothing is settled
        $walk = function (int $from, array $set, float $sum) use (&$walk, &$found, &$budget, $pool, $value, $target, $n, $most) {
            if ($set !== [] && abs($sum - $target) <= Tables::TOLERANCE) {
                $found[] = $set;

                return;
            }
            if (count($set) === $most || count($found) > 1 || --$budget < 0) {
                $budget < 0 && $found = [[], []];   // too many to tell: not one

                return;
            }
            for ($i = $from; $i < $n; $i++) {
                $walk($i + 1, [...$set, $pool[$i]], $sum + $value($pool[$i]));
            }
        };
        $walk(0, [], 0.0);

        return $found;
    }
}
