<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\AmountParser;
use App\Services\Statements\PriorNoteItems;
use App\Support\ImportNormalizer;

/**
 * The related parties as last year's note lists them (Phase 8, R15): the note explaining the due-to or due-from line
 * (else the note titled so), whatever its shape: one table (each party with its relationship, the nature of its
 * transactions, their volume and its balances) or several (the balances, the transactions), told apart by their headers'
 * and titles' words; a party's relationship also from the note's text («شركة الواحة (شركة شقيقة)»). Each party: its name
 * as printed (corrected, R11), last year's volume and balance (the file's own year), due from or to it, its page.
 */
final class RelatedParties
{
    private const LINES = ['sofp.due_to_related_parties', 'sofp.due_from_related_parties', 'sofp.due_to_related_parties_non_current'];

    /** Rows of the note that are its headers or totals, not a party. */
    /** A sideways table's column that is no party: a total, a movement, a provision. */
    private const NOT_A_COLUMN = '/^(ال)?(صافي|اجمالي|إجمالي|مجموع|محمل|مكون|مستخدم|مخصص|رصيد)(\s|$)/u';

    private const NOT_A_PARTY = '/^(ال)?(إجمالي|اجمالي|المجموع|مجموع|البيان|الاسم|اسم|الطرف|الأطراف|الاطراف|تكلفة|افتتاحي|رصيد|مدين|دائن|حركة|ختامي|صافي)(\s|$)|^[\d\s\x{0660}-\x{0669}\x{06F0}-\x{06F9}\/\-–.,م]+$/u';

    /** A role or a family tie, by its first word (after «*» in an account's name: «محمد*المدير التنفيذي»). */
    private const ROLE = '/^(ال)?(مدير|رئيس|عضو|نائب|شريك|مالك|مؤسس|مساهم|زوج|زوجة|ابن|ابنة|أخ|اخ|أخت|اخت|والد|والدة)(\s|$)/u';

    /**
     * @return ?array{note: ?string, page: int, parties: list<array{name: string, relationship: ?string, nature: ?string, volume_py: ?float, balance_py: ?float, side: ?string, page: int}>}
     *         side: due_from, due_to or null (not said)
     */
    public static function read(array $file, array $fileRows = []): ?array
    {
        $doc = $file['document'] ?? null;
        $section = $doc === null ? null : PriorNoteItems::section($doc, $file, $fileRows, self::LINES) ?? self::titled($doc);
        if ($section === null) {
            return null;
        }
        $fix = fn (string $text) => trim(Wording::apply($text, $doc['corrections'] ?? [], $file['wording_choices'] ?? []));
        $parts = array_filter($doc['sections'], fn ($s) => $s['id'] === $section || $s['parent'] === $section);
        $text = implode(' ', array_merge(...array_map(fn ($s) => $s['paragraphs'], $parts) ?: [[]]));
        $parties = [];
        foreach ($parts as $part) {
            foreach (array_map(fn ($id) => $doc['tables'][$id], $part['tables']) as $t) {   // each part's own tables, under its own title
                if ($t['movement'] ?? false) {
                    continue;
                }
                $context = $part['title'].' '.($t['title'] ?? '');
                $side = self::side($context);
                $volumes = preg_match('/معاملات|المعاملات|حجم|تعاملات/u', $context) === 1 && preg_match('/أرصدة|ارصدة|رصيد|مستحق/u', $context) !== 1;
                // Printed sideways (the parties as columns, their figures and words as rows): a party per column.
                if (($byColumn = self::sideways($t, $doc['years']['current'] ?? null)) !== null) {
                    foreach ($byColumn as $p) {
                        $p['name'] = $fix($p['name']);
                        $pSide = $side ?? $p['balance_side'];
                        $p['volume_py'] ??= match ($pSide) {
                            'due_to'   => $p['credits_py'],
                            'due_from' => $p['debits_py'],
                            default    => null,
                        };
                        $p = array_diff_key($p, ['debits_py' => 1, 'credits_py' => 1, 'balance_side' => 1]);
                        $key = ImportNormalizer::text($p['name']).'|'.$pSide;   // a party owed and owing: one row a side
                        $parties[$key] = ($parties[$key] ?? []) + array_map(fn ($v) => is_string($v) ? $fix($v) : $v, $p) + ['side' => $pSide, 'page' => $t['page']];
                    }
                    continue;
                }
                $columns = self::columns($t);
                foreach ($t['rows'] as $r) {
                    $name = $fix($r['label']);
                    if ($name === '' || ($r['kind'] ?? null) === 'total' || preg_match(self::NOT_A_PARTY, ImportNormalizer::text($name)) === 1) {
                        continue;
                    }
                    $amount = fn (?int $c) => $c === null ? null : ($r['cells'][$c]['amount'] ?? null);
                    $words = fn (?int $c) => $c === null || ($r['cells'][$c]['status'] ?? null) !== 'text' ? null : $fix((string) $r['cells'][$c]['raw']);
                    $key = ImportNormalizer::text($name);
                    $p = $parties[$key] ?? ['name' => $name, 'relationship' => null, 'nature' => null, 'volume_py' => null, 'balance_py' => null, 'side' => null, 'page' => $t['page']];
                    $p['relationship'] ??= $words($columns['relationship']);
                    $p['nature'] ??= $words($columns['nature']);
                    $p['volume_py'] ??= $amount($columns['volume'] ?? ($volumes ? $columns['year'] : null));
                    $p['balance_py'] ??= $volumes ? null : $amount($columns['year']);
                    $p['side'] ??= $side;
                    $parties[$key] = $p;
                }
            }
        }
        if ($parties === []) {
            return null;
        }
        // A relationship the text gives: the party's name followed by it in brackets.
        foreach ($parties as $k => $p) {
            if ($p['relationship'] === null && preg_match('/'.preg_quote($p['name'], '/').'\s*\(([^)]{3,40})\)/u', $fix($text), $m)) {
                $parties[$k]['relationship'] = trim($m[1]);
            }
        }

        return ['note' => $doc['sections'][$section]['printed'], 'page' => $doc['sections'][$section]['page'], 'parties' => array_values($parties)];
    }

    /**
     * A table printed sideways: its columns are the parties (headers without a year) and its rows what is said of them,
     * at least two of them dated or named so (a balance «31 ديسمبر 2023», the year's transactions «2023», «العلاقة»,
     * «طبيعة المعاملات»). Each party: its relationship and nature from the rows so named, last year's balance from the
     * row dated the file's year end (its year with no 1 January), last year's volume from the row that is only its year.
     *
     * @return ?list<array{name: string, relationship: ?string, nature: ?string, volume_py: ?float, balance_py: ?float}>
     */
    private static function sideways(array $t, ?int $year): ?array
    {
        if ($year === null || array_filter($t['columns'], fn ($c) => Tables::years($c['header']) !== [] || trim($c['header']) === '') !== []) {
            return null;
        }
        $find = fn (callable $test) => collect($t['rows'])->search(fn ($r) => $test(trim(AmountParser::asciiDigits($r['label']))));
        $relationship = $find(fn ($l) => preg_match('/علاقة|العلاقه|العلاقة/u', $l) === 1 && preg_match('/طبيعة\s+المعاملات/u', $l) !== 1);
        $nature = $find(fn ($l) => preg_match('/طبيعة\s+(ال)?(معاملات|تعامل)/u', $l) === 1);
        // The balance at the file's year end: its date («31 ديسمبر 2022»), or the year alone with a mark («2022 م»).
        $balance = $find(fn ($l) => in_array($year, Tables::years($l), true) && ! Tables::yearStart($l) && preg_match('/^\d{4}$/', $l) !== 1);
        $volume = $find(fn ($l) => $l === (string) $year);
        // Printed as the party's account movement: the opening, its debits and credits, then the balance (the year's
        // transactions are the movement that raised the balance: credits due to the party, debits due from it).
        $opening = $find(fn ($l) => preg_match('/^(ال)?(افتتاحي|رصيد\s+(أول|اول|بداية))/u', $l) === 1);
        $debit = $find(fn ($l) => preg_match('/^(ال)?(مدين|حركة\s+مدينة|المدفوع)$/u', $l) === 1);
        $credit = $find(fn ($l) => preg_match('/^(ال)?(دائن|حركة\s+دائنة|المحصل)$/u', $l) === 1);
        $closing = $balance !== false ? $balance : $find(fn ($l) => preg_match('/^(ال)?(رصيد\s+(آخر|اخر|نهاية)|ختامي|الختامي)/u', $l) === 1);
        $movement = $closing !== false && ($debit !== false || $credit !== false || $opening !== false);
        if (! $movement && count(array_filter([$relationship, $nature, $balance, $volume], fn ($i) => $i !== false)) < 2) {
            return null;
        }
        $balance = $closing;
        $cell = fn ($row, int $c) => $row === false ? null : ($t['rows'][$row]['cells'][$c] ?? null);
        $words = fn ($row, int $c) => ($x = $cell($row, $c)) !== null && $x['status'] === 'text' ? trim((string) $x['raw']) : null;
        $out = [];
        foreach ($t['columns'] as $c => $col) {
            // Not a party: a balance or a movement printed beside them (a provision's), or their total.
            if (Tables::isBalance($col['header']) || preg_match(self::NOT_A_COLUMN, ImportNormalizer::text($col['header'])) === 1) {
                continue;
            }
            ['name' => $name, 'role' => $role] = self::fromHeader(trim($col['header']));
            $balancePy = $cell($balance, $c)['amount'] ?? null;
            $out[] = ['name' => $name, 'relationship' => $words($relationship, $c) ?? $role, 'nature' => $words($nature, $c),
                'volume_py' => $cell($volume, $c)['amount'] ?? null, 'balance_py' => $balancePy,
                // The movement's sides, for the caller to take the one that raised the balance (its side).
                'debits_py' => $movement && $debit !== false ? abs((float) ($cell($debit, $c)['amount'] ?? 0.0)) : null,
                'credits_py' => $movement && $credit !== false ? abs((float) ($cell($credit, $c)['amount'] ?? 0.0)) : null,
                // Printed negative (a credit balance in a debit layout): due to the party.
                'balance_side' => $balancePy === null || abs($balancePy) < 0.005 ? null : ($balancePy < 0 ? 'due_to' : null)];
        }

        return $out;
    }

    /**
     * A party's name from a column header that adds its role after it («السيدة / فاطمة الشوا شريك تشغيلي»): the name,
     * and the role (ROLE's first word and what follows) as its relationship.
     *
     * @return array{name: string, role: ?string}
     */
    public static function fromHeader(string $header): array
    {
        $words = preg_split('/\s+/u', trim($header)) ?: [];
        foreach ($words as $i => $w) {
            if ($i > 0 && preg_match(self::ROLE, $w) === 1) {
                return ['name' => trim(implode(' ', array_slice($words, 0, $i)), " \t-–/"), 'role' => implode(' ', array_slice($words, $i))];
            }
        }

        return ['name' => trim($header), 'role' => null];
    }

    /**
     * The notes' related parties, proposed (R15): last year's parties in their order, each with the trial balance's
     * related-party accounts that are its (by its name: its telling words; else the one account whose opening is its
     * balance last year), its relationship and the nature of its transactions, last year's volume and balance (the
     * balance used only without opening balances), this year's volume from the accounts' movements (null without
     * movement columns: typed); then the accounts no party of last year's has, a party each. Without last year's note:
     * a party per account; with one party and no opening balances, last year's balance is the statement line's.
     *
     * @param ?array $read self::read()
     * @param array<int, true> $accounts the related-party accounts (rows)
     * @param array<string, list<string>> $lines due_from / due_to → their lines
     * @param array<string, ?float> $prior last year's statement lines (due_from, due_to → amount)
     * @param bool $approved this year's openings from the approved statements (Step B): last year's balance never from the openings
     * @return array{parties: list<array>, unmatched: list<string>}
     */
    public static function proposals(?array $read, array $accounts, \App\Services\Statements\ScheduleContext $ctx, array $lines, array $prior, bool $approved = false): array
    {
        $tableOf = function (int $n) use ($ctx, $lines) {
            foreach ($lines as $table => $keys) {
                if (array_intersect(array_keys([...$ctx->mapping['rows'][$n]['lines']['closing'], ...($ctx->mapping['rows'][$n]['lines']['opening'] ?? [])]), $keys)) {
                    return $table;
                }
            }

            return 'due_to';
        };
        $volume = function (array $rows) use ($ctx, $tableOf) {
            $sum = null;
            foreach ($rows as $n) {
                $increase = $ctx->sum([$n => 1.0], $tableOf($n) === 'due_from' ? 'debit' : 'credit')['increase'];
                $sum = $increase === null ? null : ($sum ?? 0.0) + $increase;
                if ($increase === null) {
                    break;
                }
            }

            return $sum === null ? null : round($sum, 2) + 0.0;
        };
        // What the accounts themselves say, where last year's note doesn't: a partner's or owner's current account is a
        // partner's («شريك»), its transactions a current account («حساب جاري»).
        $current = fn (array $rows) => array_filter($rows, fn ($n) => preg_match('/جار[يى]\s*(ال)?(شريك|شركاء|مالك|صاحب)|حساب\s*(ال)?مالك|صاحب\s*(ال)?مؤسس/u',
            ($ctx->rows[$n]['sub_class'] ?? '').' '.($ctx->rows[$n]['account_name'] ?? '')) === 1) !== [];
        $party = fn (string $name, array $rows, array $from = []) => ['name' => $name,
            'relationship' => $from['relationship'] ?? ($current($rows) ? 'شريك' : null),
            'nature' => $from['nature'] ?? ($current($rows) ? 'حساب جاري' : null),
            'rows' => $rows, 'volume_cy' => $rows === [] ? null : $volume($rows), 'volume_py' => $from['volume_py'] ?? null,
            'balance_py' => isset($from['balance_py']) ? ($from['side'] === 'due_from' || ($from['side'] === null && $rows !== [] && $tableOf($rows[0]) === 'due_from') ? 1 : -1) * abs($from['balance_py']) : null];

        $left = array_keys($accounts);
        $out = [];
        $unmatched = [];
        foreach ($read['parties'] ?? [] as $p) {
            $want = Partners::telling($p['name']);
            $byName = array_values(array_filter($left, function ($n) use ($ctx, $want, $read, $p) {
                $have = Partners::telling((string) ($ctx->rows[$n]['account_name'] ?? ''));
                $score = count(array_intersect($want, $have));
                if ($score < min(2, count($want)) || $score === 0) {
                    return false;
                }
                // No other party of last year's says it as much.
                foreach ($read['parties'] as $other) {
                    if ($other !== $p && count(array_intersect(Partners::telling($other['name']), $have)) >= $score) {
                        return false;
                    }
                }

                return true;
            }));
            $byAmount = $byName !== [] || $p['balance_py'] === null || abs($p['balance_py']) <= Tables::TOLERANCE ? [] : array_values(array_filter($left, function ($n) use ($ctx, $tableOf, $p) {
                $open = $ctx->sum([$n => 1.0], $tableOf($n) === 'due_from' ? 'debit' : 'credit')['opening'];

                return $open !== null && abs(abs($open) - abs($p['balance_py'])) <= Tables::TOLERANCE;
            }));
            $rows = $byName !== [] ? $byName : (count($byAmount) === 1 ? $byAmount : []);
            $left = array_values(array_diff($left, $rows));
            $rows === [] && $unmatched[] = $p['name'];
            $out[] = $party($p['name'], $rows, $p);
        }
        // The accounts no party of last year's has: a party each (new this year, or the only list without a note), named
        // from the account's name cleaned (to confirm). Last year's balance is the line's (the approved statements',
        // Step B) without opening balances, or with the approved openings.
        $single = $read === null && count($left) === 1 && (! $ctx->profile->has('opening') || $approved);
        $cleaned = [];
        $lineOnOne = [];
        foreach ($left as $n) {
            $table = $tableOf($n);
            $account = (string) ($ctx->rows[$n]['account_name'] ?? '');
            ['name' => $name, 'role' => $role] = self::fromAccount($account);
            if ($name !== trim($account) || $role !== null) {
                $cleaned[] = ['row' => $n, 'account' => $account, 'name' => $name, 'role' => $role];
            }
            $out[] = $party($name, [$n], ($single && ($prior[$table] ?? null) !== null ? ['balance_py' => $prior[$table], 'side' => $table] : []) + ['relationship' => $role]);
            // Last year's whole line on the one account whose own opening is another amount: said (P3), never split by guess.
            $open = $ctx->profile->opening($ctx->rows[$n]);
            if ($single && ($prior[$table] ?? null) !== null && $open !== null && abs(abs($open) - abs((float) $prior[$table])) > Tables::TOLERANCE) {
                $lineOnOne[] = ['row' => $n, 'name' => $name, 'line' => abs((float) $prior[$table]), 'opening' => round(abs($open), 2) + 0.0];
            }
        }

        return ['parties' => $out, 'unmatched' => $unmatched, 'cleaned' => $cleaned, 'line_on_one' => $lineOnOne];
    }

    /**
     * A party's name from a trial balance account's name: without the marks around it (a tree's dots, stars, dashes) and,
     * after a «*», a role (ROLE) as the party's relationship; any other text after a «*» stays in the name.
     *
     * @return array{name: string, role: ?string}
     */
    public static function fromAccount(string $account): array
    {
        $parts = array_map(fn ($p) => trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/^[\s.*\-_ـ•·]+|[\s.*\-_ـ•·]+$/u', '', $p))),
            explode('*', $account));
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
        $role = null;
        if (count($parts) > 1 && preg_match(self::ROLE, end($parts)) === 1) {
            $role = array_pop($parts);
        }

        return ['name' => implode(' ', $parts), 'role' => $role];
    }

    /** Due from or due to the parties, by the words of a table's title or its part's. */
    private static function side(string $text): ?string
    {
        return match (true) {
            preg_match('/(مستحق|مطلوب)[ةه]?\s*من|مدين/u', $text) === 1 => 'due_from',
            preg_match('/(مستحق|مطلوب)[ةه]?\s*(إلى|الى|ل)|دائن/u', $text) === 1 => 'due_to',
            default => null,
        };
    }

    /**
     * A table's columns by their headers: the relationship's, the transactions' nature, their volume, and the file's
     * year (the balances, or the volumes in a table of transactions).
     *
     * @return array{relationship: ?int, nature: ?int, volume: ?int, year: ?int}
     */
    private static function columns(array $t): array
    {
        $out = ['relationship' => null, 'nature' => null, 'volume' => null, 'year' => null];
        foreach ($t['columns'] as $i => $c) {
            $h = $c['header'];
            $role = $t['roles']['roles'][$i] ?? null;
            if (preg_match('/علاق/u', $h)) {
                $out['relationship'] ??= $i;
            } elseif (preg_match('/حجم/u', $h)) {
                $out['volume'] ??= $i;
            } elseif (preg_match('/طبيعة|نوع/u', $h)) {
                $out['nature'] ??= $i;
            } elseif ($role === 'current') {
                $out['year'] ??= $i;
            }
        }
        // No header says the year: the one amount column left.
        if ($out['year'] === null) {
            $amounts = array_values(array_diff(array_keys($t['columns']), array_filter([$out['relationship'], $out['nature'], $out['volume']], fn ($v) => $v !== null)));
            $out['year'] = count($amounts) === 1 ? $amounts[0] : null;
        }

        return $out;
    }

    /** The note titled as the related parties' note, when no statement line links to one. */
    private static function titled(array $doc): ?int
    {
        foreach ($doc['sections'] as $s) {
            if ($s['parent'] === null && preg_match('/(أطراف|اطراف|جهات)\s+ذات\s+(ال)?علاقة/u', $s['title'])) {
                return $s['id'];
            }
        }

        return null;
    }
}
