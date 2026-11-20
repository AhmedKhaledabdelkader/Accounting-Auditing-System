<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\Statements\StatementSynonyms;
use App\Support\ClassificationKey;

/**
 * The wording read from a PDF, checked before it is copied as printed (R11): an AI's slip of a letter would otherwise be
 * reproduced in this year's statements. Only the terms are checked (row labels, column headers, note titles); the
 * notes' paragraphs count as the rest of the PDF.
 *
 * - wording_differs: a term read twice with one letter different (a PPE category in the note's header and in the
 *   policies' rate table): one of the two readings is likely wrong; both pages are given.
 * - wording_unknown: the words of a term found nowhere else: not in the trial balance's account names, the templates,
 *   the taxonomy, nor anywhere else in the PDF (a joined preposition or ending aside).
 *
 * Words are compared normalised (ClassificationKey: hamza and ta marbuta forms; «ال» and a joined «و» dropped).
 */
final class Wording
{
    private const SHORTEST = 4;

    /** A surface's key marking a word seen with the article (surfaces()). */
    private const WITH_ARTICLE = '@';   // shorter words aren't checked (prepositions, «رأس», …)

    /** @return list<array> the flags of self::check() */
    public static function flags(array $doc, array $vocabulary = [], array $surfaces = []): array
    {
        return self::check($doc, $vocabulary, $surfaces)['flags'];
    }

    /**
     * The flags, and the words corrected (R16's rule for wording): an AI's slip (a term read two ways, the other reading
     * a known word) is corrected to the other reading; a misprint (a word known nowhere, one known word within a
     * letter of it) to that word. Each correction is flagged with its page and the text as read, and the accountant
     * can keep the original instead (PriorPdfIssues, `wording_choices`). A word with no single correction is flagged. A
     * word split in two by the font's spacing is rejoined (`split`), not flagged. A general Arabic word is never flagged,
     * and a person's name (a partner's) isn't checked word by word. A word known only from the PDF (printed the same way
     * twice), or a general word known nowhere outside it (word lists hold common misspellings), is a misprint too when
     * one word known outside the PDF is that close by a slip (a general word: a long vowel left out, «السيارت»; else
     * also two letters swapped) and the corrected term is known outside the PDF (the word alone, or with a word next to
     * it: «رأس المال العامل» stays). Two words known outside the PDF as one term, a short word of them printed with a letter
     * changed («حاسب الى» for «حاسب آلي»), are a misprint of the term.
     *
     * @param array $doc PriorPdfReader::process()
     * @param array<string, true> $vocabulary words known from outside the PDF (self::words), [] to skip wording_unknown
     * @param array<string, string> $surfaces a known word's printed form, by normalised form (self::surfaces)
     * @param array<string, true> $general general Arabic words (Vocabulary::general): never flagged, never a correction's target
     * @param list<string> $names names (the partners', the related parties': self::names): their words aren't checked
     * @return array{flags: list<array>, corrections: list<array{id: string, kind: string, page: int, pages: list<int>, original: string, corrected: string, title: string}>}
     */
    /** The names in last year's file, never checked word by word: its partners (now and before) and its related parties. */
    public static function names(array $file): array
    {
        $partners = Partners::read($file);

        return array_values(array_filter([...$partners['names'] ?? [], ...$partners['former'] ?? [],
            ...array_column(RelatedParties::read($file)['parties'] ?? [], 'name')]));
    }

    public static function check(array $doc, array $vocabulary = [], array $surfaces = [], array $general = [], array $names = []): array
    {
        $isName = array_fill_keys(array_map(fn ($n) => implode(' ', self::normalised($n)), $names), true);
        // A general word, or one with the future's «س» (سيعاد).
        $common = fn (string $w) => array_filter([...self::variants($w), ...(str_starts_with($w, 'س') ? [mb_substr($w, 1)] : [])], fn ($v) => isset($general[$v])) !== [];
        $terms = self::terms($doc);
        $count = self::counts($doc);
        $phrases = array_filter($surfaces, fn ($k) => str_contains($k, ' '), ARRAY_FILTER_USE_KEY);   // known outside the PDF
        $surfaces += self::surfaces(self::texts($doc));
        $known = fn (string $w) => array_filter(self::variants($w), fn ($v) => isset($vocabulary[$v]) || ($count[$v] ?? 0) > 1) !== [];
        // Known outside the PDF (the trial balance, the templates, the taxonomy, the known words): a slip repeated in the
        // file is still a slip of a word known outside it.
        $outside = fn (string $w) => array_filter(self::variants($w), fn ($v) => isset($vocabulary[$v])) !== [];
        $flags = [];
        $corrections = [];
        $corrected = [];   // normalised words corrected

        // The same term read twice, one letter apart.
        $byLength = [];
        foreach ($terms as $text => $t) {
            $byLength[count($t['words'])][$text] = $t;
        }
        $seen = [];
        foreach ($byLength as $group) {
            foreach ($group as $a => $ta) {
                foreach ($group as $b => $tb) {
                    if ($a >= $b || ($i = self::oneLetterApart($ta['words'], $tb['words'])) === null
                        || preg_match('/[0-9٠-٩۰-۹]/u', $ta['words'][$i].$tb['words'][$i]) === 1) {
                        continue;   // a figure or a date (the years of two columns) is no wording
                    }
                    // The reading known outside the file is the printed one; else the one known at all; else the rarer
                    // reading is the suspect.
                    [$wa, $wb] = [$ta['words'][$i], $tb['words'][$i]];
                    [$odd, $usual] = $outside($wb) && ! $outside($wa) ? [$ta, $tb] : ($outside($wa) && ! $outside($wb) ? [$tb, $ta]
                        : ($known($wb) && ! $known($wa) ? [$ta, $tb] : ($known($wa) && ! $known($wb) ? [$tb, $ta]
                        : (count($ta['pages']) <= count($tb['pages']) ? [$ta, $tb] : [$tb, $ta]))));
                    $key = $odd['printed'].'|'.$usual['printed'];
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $pages = array_values(array_unique([$odd['pages'][0], $usual['pages'][0]]));
                    if ($vocabulary !== [] && (($outside($usual['words'][$i]) && ! $outside($odd['words'][$i])) || ($known($usual['words'][$i]) && ! $known($odd['words'][$i])))) {
                        $corrections[] = self::correction('reading_slip', $odd['pages'][0], $pages, $odd['raw'][$i], $usual['raw'][$i], $odd['printed']);
                        $corrected[$odd['words'][$i]] = true;
                    } else {
                        $flags[] = ['code' => 'wording_differs', 'page' => $odd['pages'][0], 'pages' => $pages, 'printed' => $odd['printed'], 'other' => $usual['printed']];
                    }
                }
            }
        }

        // The words of a term known nowhere: corrected to the one known word within a letter, else flagged (one per term).
        if ($vocabulary !== []) {
            $pool = array_keys($vocabulary + array_filter($count, fn ($c) => $c > 1));
            $outsidePool = array_keys($vocabulary);
            $flagged = [];
            foreach ($terms as $key => $t) {
                $unknown = [];
                $joined = isset($isName[$key]) ? [] : self::splits($t['printed'], $t['raw'], $t['words'], $outside, $known);
                foreach ($joined as $original => $whole) {
                    $corrections[$original] ??= self::correction('split', $t['pages'][0], $t['pages'], $original, $whole, $t['printed']);
                }
                $joined = array_flip(array_merge(...array_map(fn ($o) => explode(' ', $o), array_keys($joined) ?: [''])));
                foreach (isset($isName[$key]) ? [] : self::phrases($t['raw'], $t['words'], $phrases) as $original => $to) {
                    $corrections[$original] ??= self::correction('misprint', $t['pages'][0], [$t['pages'][0]], $original, $to, $t['printed']);
                }
                foreach (isset($isName[$key]) ? [] : $t['raw'] as $i => $word) {
                    $w = $t['words'][$i];
                    if (isset($joined[$word]) || mb_strlen($w) < self::SHORTEST || ! self::letters($w) || isset($flagged[$w]) || isset($corrected[$w]) || $outside($w)) {
                        continue;
                    }
                    // Known from the PDF alone, or a general word: only corrected (to a word known outside it), when the
                    // correction is a term known outside it (the word alone, or with a word next to it); never flagged.
                    $general = $common($w);
                    if ($general || $known($w)) {
                        $to = self::nearest($word, $w, $outsidePool, $surfaces, $general ? 'vowel' : 'slip');
                        $n = self::normalised((string) $to)[0] ?? '';
                        if ($to !== null && (count($t['words']) === 1 || isset($phrases[($t['words'][$i - 1] ?? '').' '.$n]) || isset($phrases[$n.' '.($t['words'][$i + 1] ?? '')]))) {
                            $flagged[$w] = true;
                            $corrections[] = self::correction('misprint', $t['pages'][0], [$t['pages'][0]], $word, $to, $t['printed']);
                        }
                        continue;
                    }
                    $flagged[$w] = true;
                    $to = self::nearest($word, $w, $pool, $surfaces);
                    if ($to !== null) {
                        $corrections[] = self::correction('misprint', $t['pages'][0], [$t['pages'][0]], $word, $to, $t['printed']);
                    } else {
                        $unknown[] = $word;
                    }
                }
                if ($unknown) {
                    $flags[] = ['code' => 'wording_unknown', 'page' => $t['pages'][0], 'printed' => implode('، ', $unknown), 'title' => $t['printed'], 'words' => $unknown];
                }
            }
            // The notes' paragraphs: split words rejoined too (their other words aren't checked).
            foreach ($doc['sections'] as $sec) {
                foreach ($sec['paragraphs'] as $text) {
                    foreach (self::splits($text, self::rawWords($text), self::normalised($text), $outside, $known) as $original => $whole) {
                        $corrections[$original] ??= self::correction('split', $sec['page'], [$sec['page']], $original, $whole, $sec['title']);
                    }
                }
            }
        }

        return ['flags' => $flags, 'corrections' => array_values($corrections)];
    }

    /**
     * A text with the document's corrections applied, except those the accountant kept as printed.
     *
     * @param array<string, string> $choices correction id → `original` (kept as printed)
     */
    public static function apply(string $text, array $corrections, array $choices = []): string
    {
        foreach ($corrections as $c) {
            if (($choices[$c['id']] ?? null) !== 'original') {
                // The word alone, or with a joined «و» or preposition («وخزابات»).
                $text = (string) preg_replace('/(?<!\p{L})([وفبلك]?)'.preg_quote($c['original'], '/').'(?!\p{L})/u', '${1}'.$c['corrected'], $text);
            }
        }

        return $text;
    }

    private static function correction(string $kind, int $page, array $pages, string $original, string $corrected, string $title): array
    {
        return ['id' => substr(sha1("{$page}|{$original}"), 0, 12), 'kind' => $kind, 'page' => $page, 'pages' => $pages,
            'original' => $original, 'corrected' => $corrected, 'title' => $title];
    }

    /**
     * The one known word a word known nowhere is a misprint of: within one letter (a letter changed or added), or a letter
     * added with two letters swapped in a long word; the word looked up without its joined preposition, which the
     * correction keeps (without its article: a word known with one). Never shorter than the word. Null when none, or more
     * than one, is that close. $only: 'vowel', a long vowel (ا، و، ي) put back inside the word only; 'slip', that or two
     * letters swapped (no letter changed).
     */
    private static function nearest(string $raw, string $w, array $pool, array $surfaces, ?string $only = null): ?string
    {
        $clean = (string) preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $raw);
        $offset = mb_strlen($clean) - mb_strlen($w);
        $found = [];
        // The word without its longest joined preposition (or the word itself): «للملتكات» is looked up as «ملتكات».
        $forms = self::variants($w, false);
        $p = max(array_keys($forms));
        // A word printed with its article («المتوفر») is a misprint only of a word known with one: never of a verb
        // («تتوفر»).
        $article = self::article(mb_substr($clean, 0, $offset + $p));
        foreach ([$p => $forms[$p]] as $p => $v) {
            $limit = mb_strlen($v) >= 6 ? 1.5 : 1.0;
            foreach ($pool as $known) {
                if (abs(mb_strlen($known) - mb_strlen($v)) > 1 || ! isset($surfaces[$known]) || ($article && ! isset($surfaces[self::WITH_ARTICLE.$known]))
                    || ($d = self::distance($v, $known)) > $limit) {
                    continue;
                }
                $to = mb_substr($clean, 0, $offset + $p).$surfaces[$known];
                // Never by leaving a letter out: a name or a rare word is no shorter word's misprint («مسعود» is not «سعود»).
                if ($to !== $clean && mb_strlen($to) >= mb_strlen($clean) && ($only === null || self::vowelPutBack($clean, $to) || ($only === 'slip' && self::swapped($clean, $to)))) {
                    $found[$to] = min($found[$to] ?? INF, $d);
                }
            }
        }
        if ($found === []) {
            return null;
        }
        $best = min($found);
        $at = array_keys(array_filter($found, fn ($d) => $d === $best));

        return count($at) === 1 ? $at[0] : null;
    }

    /**
     * Words split in two by the font's spacing («المو افقة»): two neighbouring pieces, one not a word known outside the
     * file on its own («عبد الله» stays two), apart by a space only (not a punctuation mark), joining into a known word.
     * A letter alone is never a piece: «ف القيمة» is «في القيمة» printed short, not «فالقيمة».
     *
     * @param string $text the text as printed
     * @param list<string> $raw its words as printed
     * @param list<string> $words the same, normalised
     * @return array<string, string> the two pieces as printed (with their space) → the word joined
     */
    private static function splits(string $text, array $raw, array $words, callable $outside, callable $known): array
    {
        $out = [];
        for ($i = 0; $i < count($raw) - 1; $i++) {
            $whole = self::normalised($raw[$i].$raw[$i + 1]);
            if (count($whole) === 1 && mb_strlen($raw[$i]) >= 2 && mb_strlen($raw[$i + 1]) >= 2 && str_contains($text, $raw[$i].' '.$raw[$i + 1]) && (! $outside($words[$i] ?? '') || ! $outside($words[$i + 1] ?? '')) && $known($whole[0])) {
                $out[$raw[$i].' '.$raw[$i + 1]] = $raw[$i].$raw[$i + 1];
                $i++;
            }
        }

        return $out;
    }

    /** Whether $to is $from with two neighbouring letters swapped (a hamza on an alef aside). */
    private static function swapped(string $from, string $to): bool
    {
        $a = mb_str_split(strtr($from, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']));
        $b = mb_str_split(strtr($to, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']));
        for ($i = 0; $i < count($a) - 1; $i++) {
            if ($a[$i] !== $a[$i + 1] && [...array_slice($a, 0, $i), $a[$i + 1], $a[$i], ...array_slice($a, $i + 2)] === $b) {
                return true;
            }
        }

        return false;
    }

    /** Whether $to is $from with one long vowel (ا، و، ي) put back, neither first nor last. */
    private static function vowelPutBack(string $from, string $to): bool
    {
        $a = mb_str_split($from);
        $b = mb_str_split($to);
        for ($i = 1; $i < count($b) - 1; $i++) {
            if (in_array($b[$i], ['ا', 'و', 'ي'], true) && [...array_slice($b, 0, $i), ...array_slice($b, $i + 1)] === $a) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two neighbouring words known outside the PDF as one term (self::surfaces) but a short word of them printed with a
     * letter changed from the term's (see the code for what isn't a letter changed): the two words as printed → the term's.
     *
     * @param array<string, string> $phrases two normalised words → their printed form
     * @return array<string, string>
     */
    private static function phrases(array $raw, array $words, array $phrases): array
    {
        $fold = fn (string $w) => strtr((string) preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $w), ['أ' => 'ا', 'إ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        $out = [];
        for ($i = 0; $i < count($raw) - 1; $i++) {
            $known = $phrases[($words[$i] ?? '').' '.($words[$i + 1] ?? '')] ?? null;
            if ($known === null || count($parts = explode(' ', $known)) !== 2) {
                continue;
            }
            $to = [];
            foreach ([0, 1] as $j) {
                // Only a short word (the words above check the rest), a letter changed: not a hamza on an alef (a madda is
                // a letter), «ة» for «ه», «ى» for «ي», an article or a joined «و» («غير» in one, «وغير» in the other).
                [$x, $y] = [$fold($raw[$i + $j]), $fold($parts[$j])];
                // A year or a figure («۲۰۲۱» beside «۲۰۲۲») is never a misprint of the other: the balance a year earlier is printed too.
                $to[] = $x === $y || ! self::letters($raw[$i + $j]) || ! self::letters($parts[$j]) || mb_strlen($x) !== mb_strlen($y) || mb_strlen($words[$i + $j]) >= self::SHORTEST ? $raw[$i + $j] : $parts[$j];
            }
            if ($to !== [$raw[$i], $raw[$i + 1]]) {
                $out[$raw[$i].' '.$raw[$i + 1]] = implode(' ', $to);
            }
        }

        return $out;
    }

    /** Letters only: Arabic letters, no digit (the Arabic-Indic digits are Arabic script too: «٢٠٢٢م» is a date, not a word). */
    private static function letters(string $w): bool
    {
        return preg_match('/^\p{Arabic}+$/u', $w) === 1 && preg_match('/[٠-٩۰-۹]/u', $w) === 0;
    }

    /** Whether a word's joined part ends with the article («ال», «بال», «وال», «لل»). */
    private static function article(string $prefix): bool
    {
        return preg_match('/(ال|لل)$/u', ClassificationKey::name($prefix)) === 1;
    }

    /** Letters changed, added or left out count 1; two letters swapped count ½ (a reading slip). */
    private static function distance(string $a, string $b): float
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $d = [];
        for ($i = 0; $i <= count($x); $i++) {
            for ($j = 0; $j <= count($y); $j++) {
                if ($i === 0 || $j === 0) {
                    $d[$i][$j] = (float) max($i, $j);
                    continue;
                }
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1));
                if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 0.5);
                }
            }
        }

        return $d[count($x)][count($y)];
    }

    /**
     * Each known form's printed word, by normalised form (and its forms without a joined preposition), from texts; a
     * form seen with the article also under WITH_ARTICLE; each two neighbouring words' printed form, by the two
     * normalised (with a space).
     *
     * @param iterable<string> $texts
     * @return array<string, string>
     */
    public static function surfaces(iterable $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            $raw = array_map(fn ($r) => (string) preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $r), self::rawWords((string) $text));
            $words = array_map(fn ($r) => self::normalised($r)[0] ?? '', $raw);
            for ($i = 0; $i < count($raw) - 1; $i++) {
                if (preg_match('/^\p{Arabic}+ \p{Arabic}+$/u', $words[$i].' '.$words[$i + 1])) {
                    $out[$words[$i].' '.$words[$i + 1]] ??= $raw[$i].' '.$raw[$i + 1];
                }
            }
            foreach (self::rawWords((string) $text) as $raw) {
                $clean = (string) preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $raw);
                $w = self::normalised($clean)[0] ?? '';
                if (! self::letters($w)) {
                    continue;
                }
                $offset = mb_strlen($clean) - mb_strlen($w);
                foreach (self::variants($w, false) as $p => $v) {
                    $out[$v] ??= mb_substr($clean, $offset + $p);
                    if (self::article(mb_substr($clean, 0, $offset + $p))) {
                        $out[self::WITH_ARTICLE.$v] = $out[$v];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The words of texts, normalised, as a set: the vocabulary the terms are checked against.
     *
     * @param iterable<string> $texts
     * @return array<string, true>
     */
    public static function words(iterable $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            foreach (self::normalised((string) $text) as $w) {
                foreach (self::variants($w) as $v) {
                    $out[$v] = true;
                }
            }
        }

        return $out;
    }

    /** @return list<string> the words of a text, normalised, one for each word printed (self::rawWords) */
    public static function normalised(string $text): array
    {
        return array_map(function ($w) {
            $w = ClassificationKey::name(mb_strtolower($w));
            if (mb_substr($w, 0, 1) === 'و' && mb_strlen($w) > 3 && ! in_array($w, StatementSynonyms::WAW_WORDS, true)) {
                $w = mb_substr($w, 1);
            }

            return str_starts_with($w, 'ال') && mb_strlen($w) > 4 ? mb_substr($w, 2) : $w;
        }, self::rawWords($text));
    }

    /**
     * The terms: row labels, column headers (each part between « / »), note titles; by normalised text, with their
     * pages, the words as printed and normalised.
     *
     * @return array<string, array{printed: string, raw: list<string>, words: list<string>, pages: list<int>}>
     */
    private static function terms(array $doc): array
    {
        $found = [];
        foreach ($doc['tables'] as $t) {
            foreach ($t['rows'] as $r) {
                $found[] = [$r['label'], $t['page']];
            }
            foreach ($t['columns'] as $c) {
                foreach (explode(' / ', $c['header']) as $part) {
                    $found[] = [$part, $t['page']];
                }
            }
        }
        foreach ($doc['sections'] as $s) {
            $found[] = [$s['title'], $s['page']];
        }

        $terms = [];
        foreach ($found as [$text, $page]) {
            $text = trim((string) $text);
            $raw = self::rawWords($text);
            $words = self::normalised($text);
            if ($words === []) {
                continue;
            }
            $key = implode(' ', $words);
            $terms[$key] ??= ['printed' => $text, 'raw' => $raw, 'words' => $words, 'pages' => []];
            $terms[$key]['pages'][] = $page;
        }

        return $terms;
    }

    /** The words as printed, split as the normaliser splits them. */
    private static function rawWords(string $text): array
    {
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[\p{P}\p{S}]+/u', ' ', $text)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn ($w) => $w !== 'و'));
    }

    /** How often each normalised word occurs anywhere in the PDF (terms and paragraphs). */
    private static function counts(array $doc): array
    {
        $count = [];
        foreach (self::texts($doc) as $text) {
            foreach (self::normalised((string) $text) as $w) {
                foreach (self::variants($w) as $v) {
                    $count[$v] = ($count[$v] ?? 0) + 1;
                }
            }
        }

        return $count;
    }

    /** Every text of the PDF: labels, headers, tables' titles, notes' titles and paragraphs. */
    private static function texts(array $doc): array
    {
        $texts = [];
        foreach ($doc['tables'] as $t) {
            foreach ($t['rows'] as $r) {
                $texts[] = $r['label'];
            }
            foreach ($t['columns'] as $c) {
                $texts[] = $c['header'];
            }
            $texts[] = (string) ($t['title'] ?? '');
        }
        foreach ($doc['sections'] as $s) {
            $texts = [...$texts, $s['title'], ...$s['paragraphs']];
        }

        return $texts;
    }

    /**
     * A normalised word and its forms without a joined preposition (بال، لل، ب، ل، ف، ك) or an ending (ه، ها، its ت
     * before a pronoun): «بالبنوك» is «بنوك», «الشامله» «شامل», «أنشطتها» «انشطه».
     *
     * @return list<string>
     */
    public static function variants(string $w, bool $endings = true): array
    {
        $out = [0 => $w];
        foreach (['بال', 'فال', 'كال', 'لل', 'ال', 'ب', 'ل', 'ف', 'ك'] as $prefix) {
            if (str_starts_with($w, $prefix) && mb_strlen($w) - mb_strlen($prefix) >= 3) {
                $out[mb_strlen($prefix)] ??= mb_substr($w, mb_strlen($prefix));
            }
        }
        if (! $endings) {
            return $out;   // prefix length → form
        }
        $all = array_values($out);
        foreach ($out as $form) {
            foreach (['تها' => 'ه', 'ها' => '', 'ه' => ''] as $ending => $instead) {
                if (str_ends_with($form, $ending) && mb_strlen($form) - mb_strlen($ending) >= 3) {
                    $all[] = mb_substr($form, 0, -mb_strlen($ending)).$instead;
                }
            }
        }

        return array_values(array_unique($all));
    }

    /** Same words but one, and that one the same length with one letter different (long enough to tell). */
    private static function oneLetterApart(array $a, array $b): ?int
    {
        $differ = array_keys(array_filter(array_map(fn ($x, $y) => $x !== $y, $a, $b)));
        if (count($differ) !== 1) {
            return null;
        }
        $x = mb_str_split($a[$differ[0]]);
        $y = mb_str_split($b[$differ[0]]);

        return count($x) === count($y) && count($x) >= self::SHORTEST && count(array_diff_assoc($x, $y)) === 1
            && self::letters($a[$differ[0]].$b[$differ[0]]) ? $differ[0] : null;   // letters: a year typed apart is R16's
    }
}
