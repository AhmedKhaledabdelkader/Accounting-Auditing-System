<?php

namespace App\Services;

use App\Support\FileRows;
use App\Support\Phrase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Checks the AI's structure (which column holds what) against the file itself.
 *
 * Every issue is {check, severity, field, column, value, threshold, ai, user}:
 * - severity "error": the mapping is certainly wrong; errors are sent back to the AI for the retry.
 * - severity "doubt": plausible but suspicious; shown to the user as a warning, the run continues.
 * An uncertain check is always a doubt: a false doubt only adds a warning, a false error blocks a correct file.
 * Thresholds are in config('classification.structure_checks').
 */
class StructureChecks
{
    private const AMOUNT_FIELD = '/_(debit|credit|net)$/';

    private const DEBIT_WORDS  = ['مدين', 'debit', '/(?<![a-z])dr(?![a-z])/'];
    private const CREDIT_WORDS = ['دائن', 'credit', '/(?<![a-z])cr(?![a-z])/'];

    /** Generous own-group words: the more a group recognises itself, the fewer false contradictions. */
    private const GROUP_WORDS = [
        'opening'    => ['افتتاح', 'بدايه', 'سابق', '/(?<!\p{L})(ال)?اول(?!\p{L})/u', 'opening', 'beginning', 'begin', 'b/f', 'brought forward'],
        'period'     => ['حرك', 'خلال', 'movement', 'activity', 'transaction', 'period', 'during', 'turnover'],
        'adjustment' => ['تسوي', 'تعديل', 'adjust'],
        'closing'    => ['ختام', 'نهاي', 'حالي', 'بعد', '/(?<!\p{L})(ال)?اخر(?!\p{L})/u', 'closing', 'ending', 'end', 'after', 'c/f', 'carried forward', 'final'],
    ];

    private const TOTAL_NAME = '/^\s*(ال)?(اجمالي|مجموع|جمله)(?!\p{L})|^\s*(grand\s+)?(sub)?total(?![a-z])/u';

    public const FIELD_LABELS = [
        'account_number' => 'رقم الحساب', 'account_name' => 'اسم الحساب',
        'opening_debit' => 'مدين أول المدة', 'opening_credit' => 'دائن أول المدة', 'opening_net' => 'رصيد أول المدة', 'opening_side' => 'طبيعة رصيد أول المدة',
        'period_debit' => 'الحركة المدينة', 'period_credit' => 'الحركة الدائنة', 'period_net' => 'صافي الحركة', 'period_side' => 'طبيعة الحركة',
        'adjustment_debit' => 'تسويات مدين', 'adjustment_credit' => 'تسويات دائن', 'adjustment_net' => 'صافي التسويات', 'adjustment_side' => 'طبيعة التسويات',
        'closing_debit' => 'مدين آخر المدة', 'closing_credit' => 'دائن آخر المدة', 'closing_net' => 'رصيد آخر المدة', 'closing_side' => 'طبيعة رصيد آخر المدة',
        'notes' => 'الملاحظات', 'audit_request' => 'طلبات المراجعة',
        'existing_classification' => 'تصنيف الملف', 'existing_note_classification' => 'تصنيف الإيضاحات',
        'note_reference' => 'رقم الإيضاح',
    ];

    private array $issues = [];

    public function __construct(private AccountExtractor $extractor) {}

    /** @return list<array> every issue of the structure against the sheets */
    public function check(array $sheets, array $structure): array
    {
        $this->issues = [];
        $sheet = $sheets[$structure['sheet']] ?? null;

        if ($sheet === null) {
            $this->error('sheet_exists', null, null, "Sheet {$structure['sheet']} does not exist or is empty.", self::p('sheet_missing', ['sheet' => $structure['sheet']]));
            return $this->issues;
        }

        $this->requiredFields($structure);
        $this->columnsExist($sheet, $structure);
        $this->duplicateMapping($structure);
        $this->rows($sheet, $structure);
        $this->otherSheets($sheets, $structure);

        // Value checks need a sensible row range; skip them when it is already wrong.
        if (! array_filter($this->issues, fn ($i) => in_array($i['check'], ['first_data_row', 'header_rows'], true) && $i['severity'] === 'error')) {
            $this->headerContradictions($sheet, $structure);
            $this->values($sheet, $structure);
        }

        return $this->issues;
    }

    /** The run waits for the user: any error, or a doubt that can make the results wrong (config structure_checks.pause_on). */
    public static function pauses(array $issues): bool
    {
        $pausing = (array) config('classification.structure_checks.pause_on', []);

        return (bool) array_filter($issues, fn ($i) => $i['severity'] === 'error' || in_array($i['check'], $pausing, true));
    }

    /** @return list<string> English messages of the errors (sent to the AI on retry) */
    public function errors(array $sheets, array $structure): array
    {
        return array_values(array_column(array_filter($this->check($sheets, $structure), fn ($i) => $i['severity'] === 'error'), 'ai'));
    }

    /**
     * Checks that need the classification: a debit/credit swap across the whole file keeps every total balanced,
     * so only the side most accounts sit on can reveal it; and rows labelled as accounts that are named like totals.
     *
     * @param  list<array>  $rows  ClassificationService rows (sub_class, closing, data_flags)
     */
    public function afterClassification(array $rows): array
    {
        $this->issues = [];
        $cfg          = config('classification.structure_checks.debit_credit_swap');
        $materiality  = (float) config('classification.materiality_threshold', 1.00);

        $balanced = array_filter($rows, fn ($r) => $r['sub_class'] !== null && abs($r['closing_debit'] - $r['closing_credit']) > $materiality);
        $abnormal = array_filter($balanced, fn ($r) => in_array('abnormal_closing', array_column($r['data_flags'], 'type'), true));
        if (count($balanced) >= $cfg['min_rows']) {
            $share = count($abnormal) / count($balanced);
            if ($share >= $cfg['doubt']) {
                $this->doubt('debit_credit_swap', null, null, $share, $cfg['doubt'],
                    self::pct($share).' of classified accounts sit on the side opposite to their normal balance.',
                    self::p('debit_credit_swap', ['share' => self::pct($share)]));
            }
        }

        $totals = [];
        foreach ($rows as $r) {
            if (preg_match(self::TOTAL_NAME, self::normalize($r['account_name']))) {
                $totals[] = (int) $r['row'];
            }
        }
        if ($totals) {
            $this->doubt('total_as_account', 'account_name', null, count($totals), null,
                count($totals).' rows named like totals were labelled as accounts.',
                self::p('total_as_account', ['rows' => FileRows::phrase($totals, 5)]));
        }

        return $this->issues;
    }

    /** Lowercase, no diacritics/tatweel, unified alef / yaa / taa marbuta / hamza seats, single spaces. */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', mb_strtolower($text));
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function requiredFields(array $structure): void
    {
        $c = $structure['columns'];
        if (! $c['account_name']) {
            $this->error('required_fields', 'account_name', null, 'account_name column is required.', self::p('no_name_column'));
        }
        foreach (AccountExtractor::GROUPS as $g) {
            if (($c["{$g}_debit"] xor $c["{$g}_credit"]) && ! $c["{$g}_net"]) {
                $this->error('required_fields', "{$g}_debit", null, "{$g}: map both {$g}_debit and {$g}_credit, or use {$g}_net.",
                    self::p('group_needs_pair', ['group' => self::groupLabel($g)]));
            }
            if ($c["{$g}_side"] && ! $c["{$g}_net"]) {
                $this->error('required_fields', "{$g}_side", $c["{$g}_side"], "{$g}_side requires {$g}_net.",
                    self::p('side_needs_net', ['group' => self::groupLabel($g)]));
            }
        }
        if (! $this->extractor->presentGroups($structure)) {
            $this->error('required_fields', null, null, 'No amount columns mapped: map at least one of opening, period or closing.', self::p('no_amount_columns'));
        }
    }

    private function columnsExist(array $sheet, array $structure): void
    {
        $used = self::usedLetters($sheet);
        foreach ($structure['columns'] as $field => $letter) {
            if ($letter && ! isset($used[$letter])) {
                $this->error('column_exists', $field, $letter, "{$field}: column {$letter} has no values in sheet {$structure['sheet']}.",
                    self::p('column_empty', ['column' => $letter, 'field' => self::fieldLabel($field)]));
            }
        }
    }

    /** One column, one field. Number and name sharing a cell ("1101 - الصندوق") is the only allowed pair. */
    private function duplicateMapping(array $structure): void
    {
        $byLetter = [];
        foreach ($structure['columns'] as $field => $letter) {
            if ($letter) {
                $byLetter[$letter][] = $field;
            }
        }
        foreach ($byLetter as $letter => $fields) {
            if (count($fields) < 2 || $fields === ['account_number', 'account_name']) {
                continue;
            }
            $labels = self::andList(array_map(fn ($f) => self::fieldLabel($f), $fields));
            $ai     = count(preg_grep(self::AMOUNT_FIELD, $fields)) > 1 && count(preg_grep(self::AMOUNT_FIELD, $fields, PREG_GREP_INVERT)) === 0
                ? "Column {$letter} is mapped to more than one amount field."
                : "Column {$letter} is mapped to more than one field: ".implode(', ', $fields).'.';
            $this->error('duplicate_mapping', $fields[0], $letter, $ai, self::p('duplicate_mapping', ['column' => $letter, 'fields' => $labels]));
        }
    }

    private function rows(array $sheet, array $structure): void
    {
        $first = $structure['first_data_row'];
        if ($first < 1 || $first > max(array_keys($sheet))) {
            $this->error('first_data_row', null, null, "first_data_row {$first} is outside the sheet.", self::p('first_row_outside', ['row' => $first]));
            return;
        }
        foreach ($structure['header_rows'] as $h) {
            if ($h >= $first) {
                $this->error('header_rows', null, null, "header row {$h} is not above first_data_row {$first}.", self::p('header_after_first', ['header' => $h, 'row' => $first]));
            }
        }

        // Header cells of the mapped columns should be words, not amounts.
        $cfg    = config('classification.structure_checks.header_numeric');
        $values = [];
        foreach ($structure['header_rows'] as $h) {
            foreach (array_unique(array_filter($structure['columns'])) as $letter) {
                if (($v = $sheet[$h][$letter] ?? null) !== null && trim((string) $v) !== '') {
                    $values[] = $v;
                }
            }
        }
        if (count($values) >= $cfg['min_values']) {
            $share = count(array_filter($values, fn ($v) => self::isNumber($v))) / count($values);
            if ($share > $cfg['doubt']) {
                $this->doubt('header_rows', null, null, $share, $cfg['doubt'], 'The header rows are mostly numbers.',
                    self::p('header_numeric'));
            }
        }
    }

    /**
     * A header that names only the other side (e.g. "دائن" mapped as debit) is an error; headers naming both sides
     * ("الرصيد مدين/دائن") never trigger it. Group words are fuzzier ("الرصيد بعد التسويات" is a closing balance),
     * so a header naming only another group (e.g. "افتتاحي" mapped as closing) is a doubt.
     */
    private function headerContradictions(array $sheet, array $structure): void
    {
        $headers = $structure['header_rows'];
        $last    = $headers ? max($headers) : null;

        foreach ($structure['columns'] as $field => $letter) {
            if (! $letter || ! preg_match('/^(opening|period|adjustment|closing)_(debit|credit|net)$/', $field, $m)) {
                continue;
            }
            [, $group, $side] = $m;

            $own = [];
            $inferred = [];
            foreach ($headers as $h) {
                $text = trim((string) ($sheet[$h][$letter] ?? ''));
                if ($text !== '') {
                    $own[] = $text;
                } elseif ($h !== $last && ($title = self::titleToTheLeft($sheet[$h] ?? [], $letter)) !== null) {
                    $inferred[] = $title;
                }
            }
            $ownText = self::normalize(implode(' ', $own));
            $allText = self::normalize(implode(' ', array_merge($own, $inferred)));
            $shown   = implode(' / ', array_merge($own, $inferred));

            if ($side !== 'net' && $ownText !== '') {
                [$mine, $other] = $side === 'debit' ? [self::DEBIT_WORDS, self::CREDIT_WORDS] : [self::CREDIT_WORDS, self::DEBIT_WORDS];
                if (self::mentions($ownText, $other) && ! self::mentions($ownText, $mine)) {
                    $this->error('header_contradiction', $field, $letter, "{$field}: column {$letter} header \"{$shown}\" names the other side.",
                        self::p('header_contradiction', ['column' => $letter, 'header' => $shown, 'field' => self::fieldLabel($field)]));
                    continue;
                }
            }

            if ($allText === '' || self::mentions($allText, self::GROUP_WORDS[$group])) {   // own group named: fine
                continue;
            }
            $others = array_keys(array_filter(self::GROUP_WORDS, fn ($words, $g) => $g !== $group && self::mentions($allText, $words), ARRAY_FILTER_USE_BOTH));
            if (! $others) {
                continue;
            }
            $this->doubt('group_contradiction', $field, $letter, null, null,
                "{$field}: column {$letter} header \"{$shown}\" names ".implode('/', $others).", not {$group}.",
                self::p('header_contradiction', ['column' => $letter, 'header' => $shown, 'field' => self::fieldLabel($field)]));
        }
    }

    /** Sample values of each mapped column against the type its field expects. */
    private function values(array $sheet, array $structure): void
    {
        $c        = $structure['columns'];
        $data     = $this->extractor->dataRows($sheet, $structure);
        $cfg      = config('classification.structure_checks');
        $min      = $cfg['min_values'];
        $amounts  = array_filter($c, fn ($l, $f) => $l && preg_match(self::AMOUNT_FIELD, $f), ARRAY_FILTER_USE_BOTH);
        $filled   = fn (string $letter) => array_values(array_filter(array_map(fn ($cells) => $cells[$letter] ?? null, $data), fn ($v) => $v !== null && trim((string) $v) !== ''));

        // Amount columns: mostly numbers.
        foreach ($amounts as $field => $letter) {
            $values = $filled($letter);
            if (! $values) {
                continue;
            }
            $numeric = count(array_filter($values, fn ($v) => AmountParser::parse($v) !== null));
            $share   = $numeric / count($values);
            $label   = self::p('amount_numeric', ['column' => $letter, 'field' => self::fieldLabel($field), 'share' => self::pct($share)]);
            if ($share < $cfg['amount_numeric']['error']) {
                $this->error('amount_numeric', $field, $letter, "{$field}: column {$letter} is mostly non-numeric ({$numeric} of ".count($values).' values are numbers).', $label, $share, $cfg['amount_numeric']['error']);
            } elseif ($share < $cfg['amount_numeric']['doubt'] && count($values) >= $min) {
                $this->doubt('amount_numeric', $field, $letter, $share, $cfg['amount_numeric']['doubt'], "{$field}: column {$letter} is only ".self::pct($share).' numeric.', $label);
            }
        }

        // Rows carrying at least one amount, and their names (the number+name cell is split like the extraction does).
        $valued = array_filter($data, fn ($cells) => array_filter($amounts, fn ($l) => isset($cells[$l]) && trim((string) $cells[$l]) !== '' && AmountParser::parse($cells[$l]) !== null));
        $names  = array_map(fn ($cells) => $this->extractor->extract($cells, $structure)['account']['account_name'], $valued);

        if ($c['account_name'] && count($valued) >= $min) {
            $share = count(array_filter($names, fn ($n) => $n !== '')) / count($valued);
            $user  = self::p('name_fill', ['column' => $c['account_name'], 'field' => self::fieldLabel('account_name'), 'share' => self::pct($share)]);
            if ($share < $cfg['name_fill']['error']) {
                $this->error('name_fill', 'account_name', $c['account_name'], 'account_name: column '.$c['account_name'].' is empty on '.self::pct(1 - $share).' of the rows that have amounts.', $user, $share, $cfg['name_fill']['error']);
            } elseif ($share < $cfg['name_fill']['doubt']) {
                $this->doubt('name_fill', 'account_name', $c['account_name'], $share, $cfg['name_fill']['doubt'], 'account_name is filled on only '.self::pct($share).' of the rows that have amounts.', $user);
            }
        }

        $nonEmpty = array_values(array_filter($names, fn ($n) => $n !== ''));
        if ($c['account_name'] && count($nonEmpty) >= $min) {
            $share = count(array_filter($nonEmpty, fn ($n) => ! self::isNumber($n))) / count($nonEmpty);
            $user  = self::p('name_text', ['column' => $c['account_name'], 'field' => self::fieldLabel('account_name'), 'share' => self::pct(1 - $share)]);
            if ($share < $cfg['name_text']['error']) {
                $this->error('name_text', 'account_name', $c['account_name'], 'account_name: column '.$c['account_name'].' holds numbers, not names ('.self::pct($share).' text).', $user, $share, $cfg['name_text']['error']);
            } elseif ($share < $cfg['name_text']['doubt']) {
                $this->doubt('name_text', 'account_name', $c['account_name'], $share, $cfg['name_text']['doubt'], 'account_name is only '.self::pct($share).' text.', $user);
            }
        }

        // Account numbers: code-like and mostly distinct (repeats are allowed: branches stacked in one sheet).
        if ($c['account_number'] && $c['account_number'] !== $c['account_name']) {
            $numbers = array_map(fn ($v) => $this->extractor->cellText($v), $filled($c['account_number']));
            if (count($numbers) >= $min) {
                $code = count(array_filter($numbers, fn ($n) => mb_strlen($n) <= 30 && preg_match('/[0-9٠-٩۰-۹]/u', $n) && ! preg_match('/\p{L}{4,}/u', $n))) / count($numbers);
                if ($code < $cfg['number_code']['doubt']) {
                    $this->doubt('number_code', 'account_number', $c['account_number'], $code, $cfg['number_code']['doubt'], 'account_number: only '.self::pct($code).' of the values look like codes.',
                        self::p('number_code', ['column' => $c['account_number'], 'field' => self::fieldLabel('account_number'), 'share' => self::pct($code)]));
                }
                $distinct = count(array_unique($numbers)) / count($numbers);
                if ($distinct < $cfg['number_distinct']['doubt']) {
                    $this->doubt('number_distinct', 'account_number', $c['account_number'], $distinct, $cfg['number_distinct']['doubt'], 'account_number: only '.self::pct($distinct).' of the values are distinct.',
                        self::p('number_distinct', ['column' => $c['account_number'], 'field' => self::fieldLabel('account_number'), 'share' => self::pct($distinct)]));
                }
            }
        }

        // Side columns: debit/credit words.
        foreach (AccountExtractor::GROUPS as $g) {
            $letter = $c["{$g}_side"];
            if (! $letter || ! ($values = $filled($letter))) {
                continue;
            }
            $share = count(array_filter($values, fn ($v) => self::mentions(self::normalize((string) $v), array_merge(self::DEBIT_WORDS, self::CREDIT_WORDS, ['/^(م|د|مد|دا)$/u'])))) / count($values);
            $user  = self::p('side_values', ['column' => $letter, 'field' => self::fieldLabel("{$g}_side"), 'share' => self::pct($share)]);
            if ($share < $cfg['side_values']['error']) {
                $this->error('side_values', "{$g}_side", $letter, "{$g}_side: column {$letter} does not hold debit/credit indicators (".self::pct($share).').', $user, $share, $cfg['side_values']['error']);
            } elseif ($share < $cfg['side_values']['doubt'] && count($values) >= $min) {
                $this->doubt('side_values', "{$g}_side", $letter, $share, $cfg['side_values']['doubt'], "{$g}_side is only ".self::pct($share).' debit/credit indicators.', $user);
            }
        }

        // Free-text columns shouldn't be mostly numbers.
        foreach (['notes', 'audit_request', 'existing_classification', 'existing_note_classification'] as $field) {
            if (! $c[$field] || count($values = $filled($c[$field])) < $min) {
                continue;
            }
            $share = count(array_filter($values, fn ($v) => self::isNumber($v))) / count($values);
            if ($share > $cfg['text_numeric']['doubt']) {
                $this->doubt('text_numeric', $field, $c[$field], $share, $cfg['text_numeric']['doubt'], "{$field}: column {$c[$field]} is ".self::pct($share).' numbers.',
                    self::p('text_numeric', ['column' => $c[$field], 'field' => self::fieldLabel($field), 'share' => self::pct($share)]));
            }
        }

        // Each group with values somewhere (adjustments touch few accounts by nature: exempt).
        foreach (array_diff($this->extractor->presentGroups($structure), ['adjustment']) as $g) {
            $letters = array_filter([$c["{$g}_debit"], $c["{$g}_credit"], $c["{$g}_net"]]);
            if (count($data) < $min) {
                continue;
            }
            $share = count(array_filter($data, fn ($cells) => array_filter($letters, fn ($l) => isset($cells[$l]) && trim((string) $cells[$l]) !== ''))) / count($data);
            if ($share < $cfg['group_fill']['doubt']) {
                $this->doubt('group_fill', "{$g}_".($c["{$g}_net"] ? 'net' : 'debit'), implode('/', $letters), $share, $cfg['group_fill']['doubt'], "{$g}: values on only ".self::pct($share).' of the data rows.',
                    self::p('group_fill', ['group' => self::groupLabel($g), 'columns' => array_values($letters), 'share' => self::pct($share)]));
            }
        }

        $this->reconciliation($data, $structure, $cfg['row_reconciliation'], $cfg['groups_swapped']);
        $this->firstRows($data, $structure, $amounts, $cfg['first_rows']);
        $this->rowRange($sheet, $structure, $amounts);
    }

    /**
     * The first data row is a header when words fill all its amount cells (≥ 2) — a real account never has that;
     * with a single amount column it is only a doubt.
     * Rows between the header and the first data row that look like accounts (name + amount) would be lost.
     */
    private function rowRange(array $sheet, array $structure, array $amounts): void
    {
        $first = $structure['first_data_row'];
        $cells = array_filter(array_map(fn ($l) => trim((string) ($sheet[$first][$l] ?? '')), array_unique($amounts)), fn ($v) => $v !== '');
        $words = count(array_filter($cells, fn ($v) => preg_match('/\p{L}/u', $v)));
        $ai    = "first_data_row {$first} is a header row: its amount cells hold words (".implode(', ', array_slice($cells, 0, 4)).').';
        $user  = self::p('first_row_is_header', ['row' => $first]);
        if (count($cells) >= 2 && $words === count($cells)) {
            $this->error('first_row_is_header', null, null, $ai, $user);
        } elseif (count($cells) === 1 && $words === 1) {
            $this->doubt('first_row_is_header', null, null, null, null, $ai, $user);   // one amount column: less certain
        }

        $after   = $structure['header_rows'] ? max($structure['header_rows']) : 0;
        $skipped = [];
        foreach ($sheet as $n => $row) {
            if ($n > $after && $n < $first && ! in_array($n, $structure['header_rows'], true)
                && $this->extractor->extract($row, $structure)['account']['account_name'] !== ''
                && array_filter($amounts, fn ($l) => isset($row[$l]) && ! preg_match('/\p{L}/u', (string) $row[$l]) && AmountParser::parse($row[$l]) !== null)) {
                $skipped[] = (int) $n;
            }
        }
        if ($skipped) {
            $this->doubt('rows_skipped', null, null, count($skipped), null, 'Rows '.implode(', ', array_map(fn ($n) => "R{$n}", $skipped)).' before first_data_row look like accounts.',
                self::p(count($skipped) === 1 ? 'rows_skipped_one' : 'rows_skipped', ['rows' => FileRows::phrase($skipped, 5)]));
        }
    }

    /**
     * With opening, movement and closing mapped, closing = opening + movement (+ adjustments) holds on most rows.
     *
     * Swaps: the same test with opening ↔ closing or movement ↔ closing exchanged, or one group's debit ↔ credit.
     * Only rows an exchange decides count (they reconcile one way and not the other); rows that reconcile both ways
     * (e.g. no opening balance) prove nothing. When nearly all deciding rows favour an exchange, the mapping is swapped:
     * an error naming the exchange that fixes the most rows. Opening ↔ movement can't be seen this way (the equation
     * adds them), only from the headers (group_contradiction).
     * Otherwise, a low share is a doubt.
     */
    private function reconciliation(array $data, array $structure, array $cfg, array $swap): void
    {
        if (array_diff(['opening', 'period', 'closing'], $this->extractor->presentGroups($structure))) {
            return;
        }
        $current = $this->reconciledRows($data, $structure);
        $checked = count($current);
        if ($checked < $cfg['min_rows']) {
            return;
        }
        $share = count(array_filter($current)) / $checked;

        // Every plausible explanation of a mismatch: two groups exchanged, or one group's debit and credit exchanged.
        $candidates = [];
        foreach ([['opening', 'closing'], ['period', 'closing']] as [$a, $b]) {
            $alt = $structure;
            foreach (['debit', 'credit', 'net', 'side'] as $k) {
                [$alt['columns']["{$a}_{$k}"], $alt['columns']["{$b}_{$k}"]] = [$structure['columns']["{$b}_{$k}"], $structure['columns']["{$a}_{$k}"]];
            }
            $candidates[] = ['groups', $a, $b, $alt];
        }
        foreach (AccountExtractor::GROUPS as $g) {
            if ($structure['columns']["{$g}_debit"] && $structure['columns']["{$g}_credit"]) {
                $alt = $structure;
                [$alt['columns']["{$g}_debit"], $alt['columns']["{$g}_credit"]] = [$structure['columns']["{$g}_credit"], $structure['columns']["{$g}_debit"]];
                $candidates[] = ['sides', $g, null, $alt];
            }
        }

        $best = null;
        foreach ($candidates as [$kind, $a, $b, $alt]) {
            $other    = $this->reconciledRows($data, $alt);   // same rows, same order
            $onlyThis = count(array_filter(array_keys($current), fn ($i) => $current[$i] && ! $other[$i]));
            $onlyThat = count(array_filter(array_keys($current), fn ($i) => $other[$i] && ! $current[$i]));
            $deciding = $onlyThis + $onlyThat;
            $fixed    = count(array_filter($other));
            // The best explanation is the one that makes the most rows reconcile.
            if ($deciding >= $swap['min_rows'] && $onlyThat / $deciding >= $swap['share'] && ($best === null || $fixed > $best['fixed'])) {
                $best = compact('kind', 'a', 'b', 'fixed', 'onlyThat', 'deciding');
            }
        }

        if ($best !== null) {
            $swappedShare = $best['fixed'] / $checked;
            $tail = self::pct($share)." of {$checked} rows as mapped, and on ".self::pct($swappedShare)." once exchanged ({$best['onlyThat']} of the {$best['deciding']} rows that tell them apart favour the exchange).";
            $shares = self::p('swap_shares', ['share' => self::pct($share), 'swapped' => self::pct($swappedShare)]);
            $en = ['opening' => 'opening', 'period' => 'movement', 'adjustment' => 'adjustments', 'closing' => 'closing'];
            if ($best['kind'] === 'groups') {
                $this->error('groups_swapped', "{$best['a']}_debit", null,
                    ucfirst($en[$best['a']])." and {$en[$best['b']]} columns look swapped: closing = opening + movement holds on ".$tail,
                    self::p('groups_swapped', ['a' => self::groupLabel($best['a']), 'b' => self::groupLabel($best['b']), 'shares' => $shares]),
                    $best['onlyThat'] / $best['deciding'], $swap['share']);
            } else {
                $g = $best['a'];
                $this->error('sides_swapped', "{$g}_debit", $structure['columns']["{$g}_debit"],
                    "The {$en[$g]} debit and credit columns look swapped: closing = opening + movement holds on ".$tail,
                    self::p('sides_swapped', ['group' => self::groupLabel($g), 'shares' => $shares]),
                    $best['onlyThat'] / $best['deciding'], $swap['share']);
            }
            return;
        }
        if ($share < $cfg['doubt']) {
            $this->doubt('row_reconciliation', null, null, $share, $cfg['doubt'], 'closing = opening + movement holds on only '.self::pct($share)." of {$checked} rows.",
                self::p('row_reconciliation', ['share' => self::pct($share)]));
        }
    }

    /**
     * Whether closing = opening + movement (+ adjustments) holds, per row that can tell: named, numeric, not all zero.
     * The rows kept don't depend on which groups are which, so two mappings give aligned lists.
     *
     * @return list<bool>
     */
    private function reconciledRows(array $data, array $structure): array
    {
        $withAdjustments = in_array('adjustment', $this->extractor->presentGroups($structure), true);
        $rows = [];
        foreach ($data as $cells) {
            $e = $this->extractor->extract($cells, $structure);
            $a = $e['account'];
            if ($e['problems'] || ($a['account_name'] === '' && $a['account_number'] === '')) {
                continue;
            }
            $net = fn (string $g) => $a["{$g}_debit"] - $a["{$g}_credit"];
            if ($net('opening') == 0.0 && $net('period') == 0.0 && $net('closing') == 0.0) {
                continue;   // an all-zero row proves nothing
            }
            $expected = $net('opening') + $net('period') + ($withAdjustments ? $net('adjustment') : 0.0);
            $rows[]   = abs($expected - $net('closing')) <= max(0.01, abs($net('closing')) * 0.0001);
        }
        return $rows;
    }

    /** One of the first data rows should look like an account: a name and an amount. */
    private function firstRows(array $data, array $structure, array $amounts, int $count): void
    {
        $first = array_slice(array_filter($data, fn ($cells) => $cells !== []), 0, $count, true);
        if (count($first) < $count) {
            return;
        }
        foreach ($first as $cells) {
            $name = $this->extractor->extract($cells, $structure)['account']['account_name'];
            if ($name !== '' && array_filter($amounts, fn ($l) => isset($cells[$l]) && AmountParser::parse($cells[$l]) !== null && trim((string) $cells[$l]) !== '')) {
                return;
            }
        }
        $this->doubt('first_rows', null, null, null, null, "None of the first {$count} data rows has a name and an amount.",
            self::p('first_rows', ['count' => $count]));
    }

    private function otherSheets(array $sheets, array $structure): void
    {
        foreach ($structure['other_sheets'] as $other) {
            $n = $other['sheet'];
            if (! isset($sheets[$n])) {
                $this->error('other_sheets', null, null, "other_sheets: sheet {$n} does not exist or is empty.", self::p('sheet_missing', ['sheet' => $n]));
                continue;
            }
            if ($n === $structure['sheet']) {
                $this->error('other_sheets', null, null, "other_sheets: sheet {$n} is the trial balance itself.", self::p('other_sheet_is_tb', ['sheet' => $n]));
                continue;
            }
            if ($other['type'] !== 'adjustments') {
                continue;
            }
            $c    = $other['columns'];
            $used = self::usedLetters($sheets[$n]);
            foreach (['account_name', 'debit', 'credit'] as $required) {
                if (! $c[$required]) {
                    $this->error('other_sheets', $required, null, "other_sheets: adjustments sheet {$n} needs a {$required} column.", self::p('adjustments_missing_column', ['sheet' => $n, 'field' => Phrase::make('classification_messages.adjustment_fields.'.$required)]));
                }
            }
            foreach ($c as $field => $letter) {
                if ($letter && ! isset($used[$letter])) {
                    $this->error('other_sheets', $field, $letter, "other_sheets: sheet {$n} {$field}: column {$letter} has no values.", self::p('adjustments_column_empty', ['sheet' => $n, 'column' => $letter]));
                }
            }
            if ($other['first_data_row'] < 1 || $other['first_data_row'] > max(array_keys($sheets[$n]))) {
                $this->error('other_sheets', null, null, "other_sheets: sheet {$n} first_data_row {$other['first_data_row']} is outside the sheet.", self::p('adjustments_first_row_outside', ['sheet' => $n]));
            }
        }
    }

    /** `user` is the Arabic message (as always), `user_phrase` the same message for any language (classification_messages). */
    private function error(string $check, ?string $field, ?string $column, string $ai, Phrase $user, ?float $value = null, ?float $threshold = null): void
    {
        $this->issues[] = ['check' => $check, 'severity' => 'error', 'field' => $field, 'column' => $column, 'value' => $value, 'threshold' => $threshold, 'ai' => $ai,
            'user' => $user->ar(), 'user_phrase' => $user->toArray()];
    }

    private function doubt(string $check, ?string $field, ?string $column, float|int|null $value, ?float $threshold, string $ai, Phrase $user): void
    {
        $this->issues[] = ['check' => $check, 'severity' => 'doubt', 'field' => $field, 'column' => $column, 'value' => $value === null ? null : round((float) $value, 3), 'threshold' => $threshold, 'ai' => $ai,
            'user' => $user->ar(), 'user_phrase' => $user->toArray()];
    }

    private static function p(string $key, array $params = []): Phrase
    {
        return Phrase::make('classification_messages.structure.'.$key, $params);
    }

    /** A field's label (FIELD_LABELS in Arabic; classification_messages.fields in every language). */
    public static function fieldLabel(string $field): Phrase
    {
        return Phrase::make('classification_messages.fields.'.$field);
    }

    /** «A» و«B» و«C»: each label quoted, joined by the language's «and». */
    private static function andList(array $labels): Phrase
    {
        $quoted = array_map(fn ($l) => Phrase::make('classification_messages.structure.quoted', ['text' => $l]), $labels);

        return Phrase::join(array_map(fn ($q, $i) => $i === 0 ? $q : Phrase::make('classification_messages.structure.and_next', ['text' => $q]), $quoted, array_keys($quoted)), '');
    }

    /** @param  list<string>  $words  plain substrings, or regexes starting with "/" */
    private static function mentions(string $text, array $words): bool
    {
        foreach ($words as $word) {
            $word = str_starts_with($word, '/') ? $word : self::normalize($word);
            if (str_starts_with($word, '/') ? preg_match(str_ends_with($word, '/u') ? $word : $word.'u', $text) : str_contains($text, $word)) {
                return true;
            }
        }
        return false;
    }

    /** Nearest non-empty cell up to 3 columns to the left (a merged group title sits in its first cell). */
    private static function titleToTheLeft(array $cells, string $letter): ?string
    {
        $index = Coordinate::columnIndexFromString($letter);
        for ($i = $index - 1; $i >= max(1, $index - 3); $i--) {
            $text = trim((string) ($cells[Coordinate::stringFromColumnIndex($i)] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }

    private static function usedLetters(array $sheet): array
    {
        $used = [];
        foreach ($sheet as $cells) {
            $used += array_flip(array_keys($cells));
        }
        return $used;
    }

    private static function isNumber(mixed $v): bool
    {
        return is_int($v) || is_float($v) || (is_string($v) && ! preg_match('/\p{L}/u', $v) && AmountParser::parse($v) !== null);
    }

    private static function groupLabel(string $g): Phrase
    {
        return Phrase::make('classification_messages.structure.groups.'.$g);
    }

    private static function pct(float $share): string
    {
        return round($share * 100).'%';
    }
}
