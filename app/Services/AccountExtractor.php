<?php

namespace App\Services;

/**
 * Turns the AI's structure description (which column holds what) into exact values read from the file.
 * Amounts always come from the original cells — never from AI output.
 */
class AccountExtractor
{
    public const GROUPS = ['opening', 'period', 'adjustment', 'closing'];

    /** Every column role the AI can assign. */
    public const FIELDS = [
        'account_number', 'account_name',
        'opening_debit', 'opening_credit', 'opening_net', 'opening_side',
        'period_debit', 'period_credit', 'period_net', 'period_side',
        'adjustment_debit', 'adjustment_credit', 'adjustment_net', 'adjustment_side',
        'closing_debit', 'closing_credit', 'closing_net', 'closing_side',
        'notes', 'audit_request',
        'existing_classification', 'existing_note_classification',
        'note_reference',
    ];

    public const SHEET_TYPES = ['adjustments', 'subsidiary_ledger', 'notes', 'other'];

    /** Column roles in an adjustments sheet. */
    public const ADJUSTMENT_FIELDS = ['account_name', 'account_number', 'debit', 'credit'];

    /** A note number (5, 12, (7), 15/أ, 3-ب, 4a): what a «إيضاح» column holds. */
    private const NOTE_REFERENCE = '/^\(?\s*\d{1,3}\s*(?:[\/\-.]?\s*[\p{L}\d]{1,2})?\s*\)?$/u';
    private const MIN_NOTE_REFERENCES = 3;

    private const DEBIT_SIDE  = '/مدين|^مد$|^م$|\bdr\b|debit/iu';
    private const CREDIT_SIDE = '/دائن|^دا$|^د$|\bcr\b|credit/iu';

    /** Raw tool input → {sheet, header_rows, first_data_row, columns: field => letter|null, other_sheets}. */
    public function normalize(array $raw): array
    {
        $otherSheets = [];
        foreach ((array) ($raw['other_sheets'] ?? []) as $other) {
            if (! is_array($other)) {
                continue;
            }
            $type = in_array($other['type'] ?? null, self::SHEET_TYPES, true) ? $other['type'] : 'other';
            $entry = [
                'sheet'       => (int) ($other['sheet'] ?? 0),
                'type'        => $type,
                'description' => trim((string) ($other['description'] ?? '')),
            ];
            if ($type === 'adjustments') {
                $entry['first_data_row'] = (int) ($other['first_data_row'] ?? 0);
                $entry['columns']        = self::letters((array) ($other['columns'] ?? []), self::ADJUSTMENT_FIELDS);
            }
            $otherSheets[] = $entry;
        }

        return [
            'sheet'          => (int) ($raw['sheet'] ?? 0),
            'header_rows'    => array_values(array_map('intval', (array) ($raw['header_rows'] ?? []))),
            'first_data_row' => (int) ($raw['first_data_row'] ?? 0),
            'columns'        => self::letters((array) ($raw['columns'] ?? []), self::FIELDS),
            'other_sheets'   => $otherSheets,
        ];
    }

    private static function letters(array $raw, array $fields): array
    {
        $columns = [];
        foreach ($fields as $field) {
            $letter = $raw[$field] ?? null;
            $columns[$field] = is_string($letter) && $letter !== '' ? strtoupper(trim($letter)) : null;
        }
        return $columns;
    }

    /** Groups the file actually has (debit+credit pair or a net column). */
    public function presentGroups(array $structure): array
    {
        $c = $structure['columns'];

        return array_values(array_filter(self::GROUPS, fn ($g) =>
            ($c["{$g}_debit"] && $c["{$g}_credit"]) || $c["{$g}_net"]
        ));
    }

    /**
     * @param  array  $sheets  output of SpreadsheetReader::read
     * @return list<string> errors in English (sent back to the AI on retry); empty = valid. Doubts: StructureChecks::check.
     */
    public function validate(array $sheets, array $structure): array
    {
        return (new StructureChecks($this))->errors($sheets, $structure);
    }

    /**
     * Safety net for the remarks column: if the AI didn't map one, an unmapped column with no header
     * whose values are all free text (e.g. "غير مطابق مع التحليلي" next to one row) is used as notes.
     */
    public function withInferredNotes(array $sheet, array $structure): array
    {
        if ($structure['columns']['notes']) {
            return $structure;
        }

        $mapped     = array_filter($structure['columns']);
        $headerCols = [];
        foreach ($structure['header_rows'] as $h) {
            $headerCols += array_flip(array_keys($sheet[$h] ?? []));
        }

        $candidates = [];
        foreach ($this->dataRows($sheet, $structure) as $cells) {
            foreach ($cells as $letter => $value) {
                if (in_array($letter, $mapped, true) || isset($headerCols[$letter])) {
                    continue;
                }
                $isText = is_string($value) && AmountParser::parse($value) === null;
                $candidates[$letter] = ($candidates[$letter] ?? true) && $isText;
            }
        }

        $text = array_keys(array_filter($candidates));
        if (count($text) === 1) {
            $structure['columns']['notes'] = $text[0];
        }
        return $structure;
    }

    /**
     * Safety net for a note-number column («إيضاح»): read as remarks, every numbered row would need a review. A remarks
     * column whose values are all note numbers (at least MIN_NOTE_REFERENCES of them) is the note_reference column.
     */
    public function withNoteReferences(array $sheet, array $structure): array
    {
        $letter = $structure['columns']['notes'] ?? null;
        if (! $letter || ($structure['columns']['note_reference'] ?? null)) {
            return $structure;
        }

        $values = [];
        foreach ($this->dataRows($sheet, $structure) as $cells) {
            $value = $this->cellText($cells[$letter] ?? '');
            if ($value !== '') {
                $values[] = AmountParser::asciiDigits($value);
            }
        }
        if (count($values) < self::MIN_NOTE_REFERENCES || array_filter($values, fn ($v) => ! preg_match(self::NOTE_REFERENCE, $v))) {
            return $structure;
        }

        $structure['columns']['note_reference'] = $letter;
        $structure['columns']['notes']          = null;
        return $structure;
    }

    /**
     * Lines of an adjustments sheet, values read from the cells.
     *
     * @return list<array{row:int, account_name:string, account_number:string, debit:float, credit:float}>
     */
    public function adjustmentLines(array $sheet, array $other): array
    {
        $c     = $other['columns'];
        $lines = [];

        foreach ($sheet as $n => $cells) {
            if ($n < $other['first_data_row']) {
                continue;
            }
            $name   = trim((string) ($cells[$c['account_name']] ?? ''));
            $number = $c['account_number'] ? $this->cellText($cells[$c['account_number']] ?? '') : '';
            $debit  = AmountParser::parse($cells[$c['debit']] ?? null) ?? 0.0;
            $credit = AmountParser::parse($cells[$c['credit']] ?? null) ?? 0.0;

            if (($name === '' && $number === '') || ($debit == 0.0 && $credit == 0.0)) {
                continue;
            }
            $lines[] = ['row' => $n, 'account_name' => $name, 'account_number' => $number, 'debit' => $debit, 'credit' => $credit];
        }

        return $lines;
    }

    /** Rows after the header, excluding header rows. */
    public function dataRows(array $sheet, array $structure): array
    {
        return array_filter(
            $sheet,
            fn ($n) => $n >= $structure['first_data_row'] && ! in_array($n, $structure['header_rows'], true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @return array{account: array, problems: list<string>, note: ?string, request: ?string, existing: ?string, existing_note: ?string, note_reference: ?string}
     *         account = {account_number, account_name, opening_*, period_*, closing_*}; problems are Arabic notes;
     *         note = text of the remarks column, if any.
     */
    public function extract(array $cells, array $structure): array
    {
        $c        = $structure['columns'];
        $problems = [];

        [$number, $name] = $this->numberAndName($cells, $c);

        $groups = [];
        foreach ($this->presentGroups($structure) as $g) {
            $groups[$g] = $this->group($cells, $c, $g, $problems);
        }

        $fallback = $groups['period'] ?? $groups['closing'] ?? $groups['opening'] ?? [0.0, 0.0];

        $account = ['account_number' => $number, 'account_name' => $name];
        foreach (self::GROUPS as $g) {
            // A missing adjustments group means "no adjustments", never a copy of another group.
            [$account["{$g}_debit"], $account["{$g}_credit"]] = $groups[$g] ?? ($g === 'adjustment' ? [0.0, 0.0] : $fallback);
        }

        return [
            'account'  => $account,
            'problems' => array_map(fn ($l) => self::invalidNumber($l)->ar(), $problems),
            'problem_columns' => $problems,   // the columns of `problems`, for the same message in any language
            'note'     => $this->text($cells, $c['notes']),
            'request'  => $this->text($cells, $c['audit_request']),
            // The file's own classification (e.g. بنود الميزانية / إيضاحات); formulas already read as saved text.
            'existing'      => $this->text($cells, $c['existing_classification']),
            'existing_note' => $this->text($cells, $c['existing_note_classification']),
            // The file's note number for the account (e.g. «إيضاح» 5): a reference, never a remark.
            'note_reference' => $c['note_reference'] ? $this->text([$c['note_reference'] => $this->cellText($cells[$c['note_reference']] ?? '')], $c['note_reference']) : null,
        ];
    }

    private function numberAndName(array $cells, array $c): array
    {
        $name   = trim((string) ($cells[$c['account_name']] ?? ''));
        $number = $c['account_number'] ? $this->cellText($cells[$c['account_number']] ?? '') : '';

        // Number and name share one cell, e.g. "1101 - الصندوق".
        if ($c['account_number'] && $c['account_number'] === $c['account_name']) {
            if (preg_match('/^\s*([0-9٠-٩۰-۹][0-9٠-٩۰-۹.\-\/]*)\s*[-–—:|]?\s*(.+)$/u', $name, $m)) {
                return [rtrim($m[1], '.-/'), trim($m[2])];
            }
            return ['', $name];
        }

        return [$number, $name];
    }

    /** @return array{0: float, 1: float} [debit, credit] */
    private function group(array $cells, array $c, string $g, array &$problems): array
    {
        if ($c["{$g}_debit"] && $c["{$g}_credit"]) {
            return [
                $this->amount($cells, $c["{$g}_debit"], $problems),
                $this->amount($cells, $c["{$g}_credit"], $problems),
            ];
        }

        $net  = $this->amount($cells, $c["{$g}_net"], $problems);
        $side = $c["{$g}_side"] ? trim((string) ($cells[$c["{$g}_side"]] ?? '')) : '';

        if ($side !== '' && preg_match(self::CREDIT_SIDE, $side)) {
            return [0.0, abs($net)];
        }
        if ($side !== '' && preg_match(self::DEBIT_SIDE, $side)) {
            return [abs($net), 0.0];
        }
        return $net >= 0 ? [$net, 0.0] : [0.0, -$net];
    }

    private function amount(array $cells, string $letter, array &$problems): float
    {
        $value = AmountParser::parse($cells[$letter] ?? null);
        if ($value === null) {
            $problems[] = $letter;
            return 0.0;
        }
        return $value;
    }

    /** The invalid_number flag's message for a column. */
    public static function invalidNumber(string $letter): \App\Support\Phrase
    {
        return \App\Support\Phrase::make('classification_messages.flags.invalid_number', ['column' => $letter]);
    }

    private function text(array $cells, ?string $letter): ?string
    {
        $text = $letter ? trim((string) ($cells[$letter] ?? '')) : '';
        return $text !== '' ? $text : null;
    }

    public function cellText(mixed $v): string
    {
        return is_float($v) && floor($v) === $v ? (string) (int) $v : trim((string) $v);
    }
}
