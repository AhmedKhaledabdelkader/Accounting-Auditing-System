<?php

namespace App\Services\Statements;

use App\Support\Phrase;

/**
 * What a report asks the user (plan §4.5 intake): only the fields the run's layout and balances make relevant.
 * Field: {key, type: choice|choices|number|boolean|text|date|table, required, label_ar, help_ar, options, option_labels (Arabic), default, check,
 * source, value; columns for a table: [{key, type: text|number|rows, required, label_ar}]}.
 * A required field whose emptiness has its own check names it in `check` (zakat, Q38); the others feed
 * `empty_required_input` (check 28).
 */
final class ReportIntake
{
    /**
     * Questions no longer asked, still accepted and ignored so an older page or client isn't refused (deprecated
     * 2026-10-23): the proposed-dividends amount (R4: no such note) and the shares in words (R7: written by code).
     */
    public const RETIRED = ['dividends.proposed', 'capital.shares_in_words', 'ppe.comparative_table'];

    public const OCI = [
        'oci.fvoci'                 => 'is.oci_fvoci',
        'oci.actuarial'             => 'is.oci_actuarial',
        'oci.revaluation'           => 'is.oci_revaluation',
        'oci.translation'           => 'is.oci_translation',
        'oci.cash_flow_hedge'       => 'is.oci_cash_flow_hedge',
        'oci.hedge_reclassification' => 'is.oci_hedge_reclassification',
        'oci.associates'            => 'is.oci_associates',
    ];

    private const PARTNERS_LINES = ['sofp.partners_current_liability', 'sofp.partners_current_equity'];

    /** The revenue policies of the template (blocks revenue_<stream>). */
    public const REVENUE_STREAMS = ['goods', 'services', 'contracting', 'leasing', 'hospitality', 'murabaha', 'property_sales', 'investment_income', 'education'];

    /** Answers that decide which other fields are asked: validated first, so a request may answer them and their fields together. */
    public const GATES = ['oci.has_items' => 'boolean', 'first_application.this_period' => 'boolean', 'first_application.effect' => 'choice',
        'going_concern.decision' => 'choice', 'supplier_finance.has' => 'boolean'];

    /**
     * @param array  $mapping           LineMapper::map()
     * @param float  $tbZakat           the trial balance's zakat charge (before any input or adjustment)
     * @param float  $entriesZakat      zakat charged by the report's adjusting entries
     * @param ?float $profitBeforeZakat null when the income statement can't be built
     * @param list<array> $schedules      MovementSchedules::build()['schedules']
     * @param float  $longTermLoans       the long-term loans line before the current portion moves out of it
     * @param bool   $overdraft           the balance sheet has an overdraft, this year or at its start
     * @param ?string $priorPartners      where last year's balance sheet carried the partners' current account (liability | equity)
     * @return list<array>
     */
    public function fields(LayoutProfile $profile, array $mapping, float $tbZakat, float $entriesZakat, ?float $profitBeforeZakat, array $inputs, LineCatalogue $catalogue,
        array $schedules = [], float $longTermLoans = 0.0, bool $overdraft = false, float $retainedMovement = 0.0, ?string $priorPartners = null): array
    {
        $tolerance = (float) config('classification.balance_tolerance', 0.01);
        $fields    = [];
        // What retained earnings lost besides the result and the transfers: the user names it (never a dividend by guess).
        if (abs($retainedMovement) >= 0.005) {
            $fields[] = $this->field('soce.retained_movement', 'choice', false, ['options' => array_keys(EquityStatementBuilder::RETAINED_NATURES),
                'check' => 'retained_unexplained']);
        }

        // Q23: only when the file has a partners' current account.
        $partners = array_filter($mapping['rows'], fn ($m) => array_intersect_key($m['lines']['closing'], array_flip(self::PARTNERS_LINES)) !== []);
        if ($partners) {
            $fields[] = $this->field('partners_current', 'choice', false, ['options' => ['liability', 'equity'], 'default' => $priorPartners ?? 'liability']);
        }

        if (! $profile->postClosing) {
            // Q38: the trial balance has no zakat charge. Required when there is a profit: the charge, or zero with a reason.
            if (abs($tbZakat) <= $tolerance) {
                $required = $profitBeforeZakat !== null && $profitBeforeZakat > $tolerance && ($inputs['zakat.zero_reason'] ?? null) === null && abs($entriesZakat) <= $tolerance;
                $fields[] = $this->field('zakat.charge', 'number', $required, ['check' => 'zakat_charge_missing']);
                $fields[] = $this->field('zakat.zero_reason', 'text', false);
            }

            // OCI is never in a trial balance: the user says whether there is any, then enters the items.
            $fields[] = $this->field('oci.has_items', 'boolean', true);
            if (($inputs['oci.has_items'] ?? null) === true) {
                foreach (self::OCI as $key => $line) {
                    $label = collect($catalogue->statement('comprehensive_income'))->firstWhere('key', $line)['label_ar'];
                    $fields[] = $this->field($key, 'number', false, ['label' => Phrase::text(['ar' => $label]), 'help' => Phrase::make('report_messages.intake.oci.item_help')]);
                }
            }
        }

        // Check 18: disposal proceeds are never in a trial balance.
        foreach ($schedules as $s) {
            $disposals = $s['kind'] === 'asset' ? MovementSchedules::cell($s, 'disposals') : null;
            if ($disposals !== null && ! $disposals->isMissing() && $disposals->amount > $tolerance) {
                $fields[] = $this->field("{$s['key']}.disposal_proceeds", 'number', true, ['label' => Phrase::make('report_messages.intake.disposal_proceeds.label', ['schedule' => $s['title_ar']]),
                    'help' => Phrase::make('report_messages.intake.disposal_proceeds.help'), 'check' => 'disposal_without_proceeds']);
            }
        }
        // Q36: the part of long-term loans due within 12 months.
        if ($longTermLoans > $tolerance) {
            $fields[] = $this->field('loans.current_portion', 'number', true);
        }

        // Q25: an overdraft that is part of cash management counts in cash in the cash flow (no by default).
        if ($overdraft) {
            $fields[] = $this->field('cash_flow.overdraft_in_cash', 'boolean', false, ['default' => false]);
        }

        return array_map(fn ($f) => $f + ['value' => $inputs[$f['key']] ?? null], $fields);
    }

    /**
     * The notes' questions (PR 11), driven by what the notes show. `default` is the prior year's answer when the prior
     * year is a report here (Q12), else the template's default wording choice; a default is never taken as an answer
     * where an answer is required.
     *
     * @param array $f      facts from NotesBuilder: first_year, same_edition, indicators, policy_inventory, revenue, policy_ppe,
     *                      policy_intangibles, useful_lives, related_parties, proposals, other_provisions, trade_payables, fx
     * @param array $prior  the prior report's inputs ([] without one)
     * @return list<array>
     */
    public function noteFields(array $f, array $inputs, array $prior): array
    {
        $in     = fn (string $k) => $inputs[$k] ?? null;
        $fields = [];

        // Approval (always shown); check 22 compares the date with the period end. The body: last year's, else «الإدارة» (R3).
        $fields[] = $this->field('approval.body', 'text', false, ['default' => $prior['approval.body'] ?? __('statements.defaults.approval_body', [], 'ar')]);
        $fields[] = $this->field('approval.date_hijri', 'text', false);
        $fields[] = $this->field('approval.date', 'date', true);

        // Capital (always shown; check 20).
        // Asked only when neither last year's statements nor the articles of association give the partners (notes 10-12).
        $fields[] = $this->field('partners', 'table', ! is_array($prior['partners'] ?? null) || $prior['partners'] === [], [
            'default' => $prior['partners'] ?? null, 'columns' => [
                $this->column('partners', 'name', 'text', true),
                $this->column('partners', 'shares', 'number', true),
                $this->column('partners', 'share_value', 'number', true),
            ]]);

        // First application of the edition (E2); not asked when last year's report here already applied it.
        if (! $f['same_edition']) {
            $fields[] = $this->field('first_application.this_period', 'boolean', true);
            if ($in('first_application.this_period') === true) {
                $fields[] = $this->field('first_application.effect', 'choice', true, ['options' => ['none', 'effect']]);
                if ($in('first_application.effect') === 'effect') {
                    $fields[] = $this->field('first_application.effect_text', 'text', true);
                }
                $fields[] = $this->field('first_application.reliefs', 'text', false);
            }
        }

        // Going concern (Q14): asked when an indicator is hit.
        if ($f['indicators']) {
            // Included by itself until the accountant excludes it (with a reason): flagged, never blocking.
            $fields[] = $this->field('going_concern.decision', 'choice', false, ['options' => ['include', 'exclude'], 'default' => 'include']);
            if ($in('going_concern.decision') === 'exclude') {
                $fields[] = $this->field('going_concern.exclude_reason', 'text', true);
            } else {
                $fields[] = $this->field('going_concern.mitigation', 'text', false);
            }
        }

        // The zakat base's lines this year's statements don't give (Phase 5.5): under last year's wording, not required.
        foreach ($f['zakat_base'] ?? [] as $r) {
            $fields[] = $this->field("zakat.base.{$r['n']}", 'number', false, ['label' => Phrase::text(['ar' => $r['label']]),
                'help' => Phrase::make('report_messages.intake.zakat_base.help'),
                // Proposed from this year's zakat calculation, or from last year's components when they explain last year's figure.
                'default' => in_array($r['from'], ['derived', 'calculation'], true) ? $r['current']->amount : null]);
        }

        // Policies.
        if ($f['policy_inventory']) {
            $fields[] = $this->field('policies.inventory', 'choice', false, ['options' => ['general', 'manufacturing'], 'default' => $prior['policies.inventory'] ?? 'general']);
        }
        $fields[] = $this->field('policies.employee_benefits', 'choice', false, ['options' => ['labour_law', 'actuarial'], 'default' => $prior['policies.employee_benefits'] ?? 'labour_law']);
        if ($f['revenue']) {
            // Asked only when neither last year's policy nor the activity and the revenue accounts say (note 14).
            $fields[] = $this->field('policies.revenue', 'choices', ($f['revenue_default'] ?? []) === [], ['options' => self::REVENUE_STREAMS,
                'default' => ($f['revenue_default'] ?? []) ?: null]);
        }
        $fields[] = $this->field('policies.lessor', 'boolean', false, ['default' => $prior['policies.lessor'] ?? false]);
        $fields[] = $this->field('policies.subsidiaries', 'boolean', false, ['default' => $prior['policies.subsidiaries'] ?? false]);
        $fields[] = $this->field('policies.foreign_currency', 'boolean', false, ['default' => $prior['policies.foreign_currency'] ?? $f['fx']]);
        // Each row of the useful-life table (key → last year's category as printed, or null: one of the template's).
        foreach ($f['useful_lives'] as $k => $printed) {
            $shown = in_array($k, ['software', 'licences', 'other_intangibles'], true) ? $f['policy_intangibles'] : $f['policy_ppe'];
            if ($shown) {
                // Last year's statements give the default (100 / the rate); it is confirmed by saving it, never taken silently (P3).
                $fields[] = $this->field("useful_life.{$k}", 'text', true, ['label' => Phrase::make('report_messages.intake.useful_life.label', ['asset' => $printed ?? Phrase::make("report_messages.intake.useful_life.assets.{$k}")]),
                    'help' => Phrase::make('report_messages.intake.useful_life.help'), 'default' => $prior["useful_life.{$k}"] ?? null]);
            }
        }

        // The notes' own data.
        if ($f['related_parties']) {
            $names = [];
            foreach ((array) ($prior['related_parties'] ?? []) as $p) {
                $names[\App\Support\ImportNormalizer::text((string) ($p['name'] ?? ''))] = $p;
            }
            $default = array_map(fn ($p) => array_merge($p, array_intersect_key($names[\App\Support\ImportNormalizer::text($p['name'])] ?? [], ['relationship' => 1, 'nature' => 1])), $f['proposals']);
            $fields[] = $this->field('related_parties', 'table', true, [
                'default' => $default, 'columns' => [
                    $this->column('related_parties', 'name', 'text', true),
                    $this->column('related_parties', 'relationship', 'text', true),
                    $this->column('related_parties', 'nature', 'text', true),
                    $this->column('related_parties', 'rows', 'rows', false),
                    $this->column('related_parties', 'volume_cy', 'number', true),
                    ...($f['first_year'] ? [] : [$this->column('related_parties', 'volume_py', 'number', true),
                        $this->column('related_parties', 'balance_py', 'number', false)]),
                ]]);
        }
        if ($f['other_provisions']) {
            $fields[] = $this->field('other_provisions.description', 'text', true, ['default' => $prior['other_provisions.description'] ?? null]);
        }
        if ($f['trade_payables']) {
            // «نعم» suggested when an account of supplier finance has a balance; else «لا».
            $fields[] = $this->field('supplier_finance.has', 'boolean', false, ['default' => $f['supplier_finance'] ?? false]);
            if ($in('supplier_finance.has') === true) {
                $fields[] = $this->field('supplier_finance.provider', 'text', true);
                $fields[] = $this->field('supplier_finance.terms', 'text', true);
                $fields[] = $this->field('supplier_finance.carrying_amount', 'number', true);
                $fields[] = $this->field('supplier_finance.paid_by_provider', 'number', true);
                $fields[] = $this->field('supplier_finance.due_range', 'text', true);
            }
        }
        $fields[] = $this->field('events.subsequent', 'text', false);
        $fields[] = $this->field('events.significant', 'text', false);

        return array_map(fn ($x) => $x + ['value' => $inputs[$x['key']] ?? null], $fields);
    }

    /**
     * Errors of a partial update (field → messages); empty when valid. Every key must be a field of the report once the
     * update is applied (`oci.has_items: true` with OCI amounts in the same request works); null removes a value.
     *
     * @param array<string, mixed> $patch
     * @param list<array>          $fields fields() computed with the merged inputs
     */
    public function errors(array $patch, array $fields, array $merged): array
    {
        $byKey  = array_column($fields, null, 'key');
        $errors = [];
        foreach ($patch as $key => $value) {
            $field = $byKey[$key] ?? null;
            if ($field === null) {
                $errors[$key][] = self::error('field_not_asked');
                continue;
            }
            if ($value === null) {
                continue;
            }
            $error = match ($field['type']) {
                'choice'  => in_array($value, $field['options'], true) ? null : self::error('choose_one', ['options' => self::optionNames($key, $field)]),
                'choices' => is_array($value) && array_is_list($value) && $value !== [] && count(array_unique($value)) === count($value)
                    && array_diff($value, $field['options']) === [] ? null : self::error('choose_some', ['options' => self::optionNames($key, $field)]),
                'table'   => $this->tableError($value, $field['columns']),
                'boolean' => is_bool($value) ? null : self::error('boolean'),
                'number'  => is_int($value) || is_float($value) ? null : self::error('number'),
                'date'    => is_string($value) && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) && $d->format('Y-m-d') === $value ? null : self::error('date'),
                'text'    => str_starts_with($key, 'useful_life.')
                    ? (is_string($value) && mb_strlen(trim($value)) >= 1 && mb_strlen($value) <= 20 ? null : self::error('useful_life'))
                    : (is_string($value) && mb_strlen(trim($value)) >= 3 && mb_strlen($value) <= ($max = self::maxLength($key)) ? null : self::error('text_length', ['max' => $max])),
            };
            if ($error === null && (str_ends_with($key, '.disposal_proceeds') || in_array($key, ['loans.current_portion', 'supplier_finance.carrying_amount', 'supplier_finance.paid_by_provider'], true)) && $value < 0) {
                $error = self::error('not_negative');
            }
            if ($error === null && $key === 'zakat.charge' && $value <= 0) {
                $error = self::error('zakat_positive');
            }
            if ($error !== null) {
                $errors[$key][] = $error;
            }
        }
        if (($merged['zakat.charge'] ?? null) !== null && ($merged['zakat.zero_reason'] ?? null) !== null) {
            $errors['zakat.zero_reason'][] = self::error('zakat_both');
        }

        return $errors;
    }

    /** A validation message in the request's language (lang/{ar,en}/report_messages.php `errors`). */
    public static function error(string $key, array $params = []): string
    {
        return Phrase::make("report_messages.errors.{$key}", $params)->current();
    }

    /** A choice field's options, named in the request's language, in the order of their names. */
    private static function optionNames(string $key, array $field): array
    {
        return str_starts_with($key, 'rounding.') ? array_values((array) $field['option_labels']) : array_values((array) __("statements.choices.{$key}"));
    }

    /** Narrative answers may be long; the rest up to 500 characters. */
    public static function maxLength(string $key): int
    {
        foreach (['events.', 'first_application.', 'going_concern.', 'other_provisions.', 'supplier_finance.terms'] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return 2000;
            }
        }
        return 500;
    }

    /** A list of rows, each with the columns' types; null when valid. */
    private function tableError(mixed $value, array $columns): ?string
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 200) {
            return self::error('table_rows');
        }
        $byKey = array_column($columns, null, 'key');
        foreach ($value as $i => $row) {
            $n = $i + 1;
            if (! is_array($row) || array_is_list($row) && $row !== []) {
                return self::error('table_row_shape', ['n' => $n]);
            }
            if ($unknown = array_diff(array_keys($row), array_keys($byKey))) {
                return self::error('table_unknown_columns', ['n' => $n]);
            }
            foreach ($columns as $c) {
                $v = $row[$c['key']] ?? null;
                if ($v === null) {
                    if ($c['required']) {
                        return self::error('table_required', ['n' => $n, 'column' => $c['label'] ?? $c['label_ar']]);
                    }
                    continue;
                }
                $ok = match ($c['type']) {
                    'text'   => is_string($v) && mb_strlen(trim($v)) >= 2 && mb_strlen($v) <= 255,
                    // Last year's balance is signed (owed by the party +, owed to it −), as it is proposed.
                    'number' => (is_int($v) || is_float($v)) && ($v >= 0 || $c['key'] === 'balance_py') && ($c['key'] !== 'shares' && $c['key'] !== 'share_value' || $v > 0),
                    'rows'   => is_array($v) && array_is_list($v) && count($v) <= 500 && array_filter($v, fn ($r) => ! is_int($r) || $r < 1) === [],
                };
                if (! $ok) {
                    return self::error('table_invalid', ['n' => $n, 'column' => $c['label'] ?? $c['label_ar']]);
                }
            }
        }

        return null;
    }

    /** Required fields still empty. */
    /**
     * A total whose rounding difference went on a line (or would, but is above the limit): which line takes it, among the
     * lines under it (optional; see WholeRiyals; never a line of WholeRiyals::NEVER). One choice for both years.
     *
     * @param list<array> $settled WholeRiyals::settle() results
     * @param list<array> $statementRows the statements' rows (their labels)
     */
    public function roundingFields(array $settled, array $statementRows, array $inputs): array
    {
        $label  = array_column(array_filter($statementRows, fn ($r) => isset($r['key'])), 'label_ar', 'key');
        $fields = [];
        foreach ($settled as $s) {
            foreach ([...$s['plugs'], ...$s['over']] as $p) {
                $key = "rounding.{$p['total']}";
                if (isset($fields[$key])) {
                    continue;
                }
                $lines = array_values(array_diff($s['lines'][$p['total']], WholeRiyals::NEVER));
                $fields[$key] = $this->field($key, 'choice', false, ['options' => $lines, 'option_labels' => array_intersect_key($label, array_flip($lines)),
                    'default' => $p['line'] ?? null,
                    'label' => Phrase::make('report_messages.intake.rounding.label', ['total' => $label[$p['total']]]),
                    'help' => Phrase::make('report_messages.intake.rounding.help')]);
            }
        }

        return array_map(fn ($f) => $f + ['value' => $inputs[$f['key']] ?? null], array_values($fields));
    }

    public function requiredMissing(array $fields): array
    {
        return array_values(array_map(fn ($f) => $f['key'], array_filter($fields, fn ($f) => $f['required'] && $f['value'] === null)));
    }

    /**
     * A question. Its label and help come from lang/{ar,en}/report_messages.php `intake.<key>` unless given (`label`, `help`
     * as Phrases): `label_ar` / `help_ar` in Arabic, `label` / `help` in the request's language.
     */
    private function field(string $key, string $type, bool $required, array $extra = []): array
    {
        $label  = $extra['label'] ?? Phrase::make("report_messages.intake.{$key}.label");
        $help   = $extra['help'] ?? Phrase::make("report_messages.intake.{$key}.help");
        $locale = app()->getLocale();

        return ['key' => $key, 'type' => $type, 'required' => $required, 'label_ar' => $label->in('ar'), 'help_ar' => $help->in('ar'),
            'options' => $extra['options'] ?? null,
            'option_labels' => isset($extra['options']) ? (object) ($extra['option_labels'] ?? __("statements.choices.{$key}", [], 'ar')) : null,
            'default' => $extra['default'] ?? null, 'check' => $extra['check'] ?? null, 'source' => 'user_input']
            + (isset($extra['columns']) ? ['columns' => $extra['columns']] : [])
            + ['label' => $label->in($locale), 'help' => $help->in($locale)];
    }

    /** A column of a table question: `label_ar` and `label` (request's language). */
    private function column(string $table, string $key, string $type, bool $required): array
    {
        $label = Phrase::make("report_messages.intake.{$table}.columns.{$key}");

        return ['key' => $key, 'type' => $type, 'required' => $required, 'label_ar' => $label->in('ar'), 'label' => $label->current()];
    }
}
