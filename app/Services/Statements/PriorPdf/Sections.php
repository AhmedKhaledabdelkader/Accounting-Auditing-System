<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\AmountParser;
use App\Services\Statements\StatementSynonyms;

/**
 * The notes as printed (brief §8.4–8.5): numbered notes and sub-notes in order across the notes pages, each with its
 * paragraphs and tables, an unnumbered heading inside a note kept as a nested part (a note's sub-table «مصاريف
 * تشغيلية*»). A number out of sequence inside a note («5 النقد وما في حكمه» between 4.5 and 4.6) is a slip: the part
 * stays in that note, flagged, and is never taken for note 5. A number with a slash is read the way that puts it in the
 * note it sits in («4/1» in note 4 is 4.1, «1/16» in note 16 is 16.1).
 *
 * A statement line is linked to its note by the note number, the title and the amount together; a line without a note
 * number by its title and amount.
 */
final class Sections
{
    /**
     * @param array<int, array> $pages extracted notes pages by page (blocks with tables marked, `table` ids)
     * @return array{sections: list<array>, flags: list<array>}
     */
    public static function build(array $pages): array
    {
        $sections = [];
        $flags = [];
        $current = null;   // the section blocks go to
        foreach ($pages as $page) {
            $started = false;   // a block of the page's own content seen (its header lines come first)
            foreach ($page['blocks'] as $b) {
                if ($b['type'] === 'heading' && $b['number'] === null && ! $started) {
                    continue;   // the page's header: company, legal form, the notes' title
                }
                $started = true;
                if ($b['type'] === 'heading' && $b['number'] !== null) {
                    $sections[] = ['id' => count($sections), 'number' => self::number($b['number']), 'printed' => $b['number'], 'title' => self::title($b['text']),
                        'page' => $page['page'], 'parent' => null, 'paragraphs' => [], 'tables' => [], 'slip' => false];
                    $current = count($sections) - 1;
                    continue;
                }
                if ($current === null) {
                    continue;   // before the first note
                }
                if ($b['type'] === 'heading') {
                    // An unnumbered part of the note (a sub-table's title, «التكلفة»): nested under the note.
                    $parent = $sections[$current]['parent'] ?? $current;
                    $sections[] = ['id' => count($sections), 'number' => null, 'printed' => null, 'title' => self::title($b['text']), 'page' => $page['page'],
                        'parent' => $parent, 'paragraphs' => [], 'tables' => [], 'slip' => false];
                    $current = count($sections) - 1;
                } elseif ($b['type'] === 'paragraph') {
                    $sections[$current]['paragraphs'][] = $b['text'];
                } else {
                    $sections[$current]['tables'][] = $b['table'];
                }
            }
        }

        // Numbered parents: «4.1» under «4»; a number out of sequence inside a note stays in it, flagged.
        $main = null;   // the last top-level note
        foreach ($sections as $i => $s) {
            if ($s['number'] === null) {
                continue;
            }
            // «4/1» is part 1 of note 4 in one file and part 4 of note 1 in another: the note it sits in decides.
            $other = self::swapped($s['printed']);
            $mainNumber = $main === null ? null : self::parts($sections[$main]['number'])[0];
            if ($other !== null && $mainNumber !== null && self::parts($s['number'])[0] !== $mainNumber && self::parts($other)[0] === $mainNumber) {
                $sections[$i]['number'] = $s['number'] = $other;
            }
            $parts = self::parts($s['number']);
            if (count($parts) > 1) {
                $sections[$i]['parent'] = self::find($sections, implode('.', array_slice($parts, 0, -1)), $i) ?? $main;
                continue;
            }
            $next = self::nextNumbered($sections, $i);
            $mainNumber = $main === null ? null : self::parts($sections[$main]['number'])[0];
            // Still inside note N (its number again, or its sub-notes going on after it): a slip of a sub-note of N.
            $nextParts = $next === null ? [] : self::parts($next);
            if ($main !== null && ($parts[0] === $mainNumber || (count($nextParts) > 1 && $nextParts[0] === $mainNumber))) {
                $sections[$i]['parent'] = $main;
                $sections[$i]['slip'] = true;
                $flags[] = ['code' => 'note_number_slip', 'page' => $s['page'], 'printed' => $s['printed'], 'title' => $s['title'], 'in' => $sections[$main]['number']];
                continue;
            }
            $main = $i;
        }

        return ['sections' => $sections, 'flags' => $flags];
    }

    /**
     * The note of each statement line: by number, title and amount together. A candidate scores 1 for the number, the
     * share of words in common with the line, and 2 for an amount of its tables equal to the line's in either year.
     *
     * @param list<array{statement: string, row: int, label: string, note: ?string, amounts: list<float>}> $lines
     * @param list<array> $sections Sections::build()
     * @param array<int, array> $tables by id
     * @return list<array{statement: string, row: int, section: ?int, by: list<string>, certain: bool}>
     */
    public static function link(array $lines, array $sections, array $tables): array
    {
        $out = [];
        foreach ($lines as $line) {
            $best = null;
            foreach ($sections as $s) {
                if ($s['parent'] !== null) {
                    continue;   // a note itself, not a part of one
                }
                $by = [];
                $score = 0.0;
                if ($line['note'] !== null && $s['number'] === self::number($line['note'])) {
                    $by[] = 'number';
                    $score += 1;
                }
                $words = self::overlap($line['label'], $s['title']);
                if ($words > 0.0) {
                    $by[] = 'title';
                    $score += $words;
                }
                if ($line['amounts'] && self::hasAmount(self::tablesOf($s['id'], $sections, $tables), $line['amounts'])) {
                    $by[] = 'amount';
                    $score += 2;
                }
                // A line with a note number links only to that number; a line without one needs its title and an amount.
                $ok = $line['note'] !== null ? in_array('number', $by, true) : in_array('amount', $by, true) && in_array('title', $by, true);
                if ($ok && ($best === null || $score > $best['score'])) {
                    $best = ['section' => $s['id'], 'by' => $by, 'score' => $score];
                }
            }
            $out[] = ['statement' => $line['statement'], 'row' => $line['row'], 'section' => $best['section'] ?? null, 'by' => $best['by'] ?? [],
                'certain' => $best !== null && in_array('amount', $best['by'], true)];
        }
        // A note a line names by its number is that line's: another line without a number isn't linked to it by its
        // words and an amount alone, unless it prints the same figures (the line printed again).
        $claimed = [];
        foreach ($out as $k => $l) {
            if (in_array('number', $l['by'], true)) {
                $claimed[$l['section']] = $lines[$k]['amounts'];
            }
        }
        foreach ($out as $k => $l) {
            if ($l['section'] !== null && isset($claimed[$l['section']]) && ! in_array('number', $l['by'], true) && $lines[$k]['amounts'] != $claimed[$l['section']]) {
                $out[$k] = ['section' => null, 'by' => [], 'certain' => false] + $l;
            }
        }

        return $out;
    }

    /**
     * The statement line a note explains (its link), by year: [year => amount]; null without a link.
     *
     * @param int $section the note, or a part of it
     */
    public static function lineOf(array $doc, int $section): ?array
    {
        for ($top = $doc['sections'][$section]; $top['parent'] !== null; $top = $doc['sections'][$top['parent']]);
        $link = collect($doc['links'] ?? [])->first(fn ($l) => $l['section'] === $top['id']);
        if ($link === null) {
            return null;
        }
        $st = $doc['statements'][$link['statement']];
        $out = [];
        foreach ($st['rows'][$link['row']]['cells'] as $i => $c) {
            $role = $st['roles']['roles'][$i] ?? null;
            if ($role !== null && $c['amount'] !== null && ($year = $doc['years'][$role] ?? null) !== null) {
                $out[$year] = $c['amount'];
            }
        }

        return $out;
    }

    /** The tables of a note and of its parts. */
    public static function tablesOf(int $id, array $sections, array $tables): array
    {
        return array_values(array_intersect_key($tables, array_flip(self::tableIds($id, $sections))));
    }

    private static function tableIds(int $id, array $sections): array
    {
        $ids = $sections[$id]['tables'];
        foreach ($sections as $s) {
            if ($s['parent'] === $id) {
                $ids = [...$ids, ...self::tableIds($s['id'], $sections)];
            }
        }

        return $ids;
    }

    /** A note number as compared: Western digits, «1/16» and «16.1» alike as 16.1, without brackets or dashes. */
    public static function number(?string $printed): ?string
    {
        if ($printed === null) {
            return null;
        }
        $n = str_replace(' ', '', AmountParser::asciiDigits(trim($printed)));
        $n = trim($n, "()[]-–:.");
        if (preg_match('/^(\d+)\/(\d+)$/u', $n, $m)) {
            return "{$m[2]}.{$m[1]}";   // «1/16»: the first part of note 16
        }
        if (preg_match('/^(\d+)\/(.+)$/u', $n, $m)) {
            return "{$m[1]}.{$m[2]}";   // «15/أ»
        }

        return $n;
    }

    /** A number printed as two numbers with a slash, read the other way round («1/16» as 1.16); else null. */
    private static function swapped(?string $printed): ?string
    {
        $n = self::number($printed);

        return $n !== null && preg_match('/^\d+\/\d+$/u', str_replace(' ', '', AmountParser::asciiDigits(trim((string) $printed))))
            ? implode('.', array_reverse(explode('.', $n))) : null;
    }

    private static function parts(string $number): array
    {
        return explode('.', $number);
    }

    private static function find(array $sections, string $number, int $before): ?int
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            if ($sections[$i]['number'] === $number) {
                return $i;
            }
        }

        return null;
    }

    private static function nextNumbered(array $sections, int $i): ?string
    {
        for ($j = $i + 1; $j < count($sections); $j++) {
            if ($sections[$j]['number'] !== null) {
                return $sections[$j]['number'];
            }
        }

        return null;
    }

    private static function title(?string $text): string
    {
        return trim((string) preg_replace('/[\s:：\-ـ]+$/u', '', (string) $text));
    }

    /** The share of the line's words found in the title (normalised words; «الموجودات» = «الأصول» …). */
    private static function overlap(string $label, string $title): float
    {
        $a = array_filter(explode(' ', StatementSynonyms::wording($label)), fn ($w) => mb_strlen($w) > 2);
        $b = explode(' ', StatementSynonyms::wording($title));

        return $a === [] ? 0.0 : count(array_intersect($a, $b)) / count($a);
    }

    private static function hasAmount(array $tables, array $amounts): bool
    {
        foreach ($tables as $t) {
            foreach ($t['rows'] as $r) {
                foreach ($r['cells'] as $c) {
                    foreach ($amounts as $a) {
                        if ($c['amount'] !== null && abs($a) > Tables::TOLERANCE && abs(abs($c['amount']) - abs($a)) <= Tables::TOLERANCE) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}
