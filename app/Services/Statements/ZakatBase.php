<?php

namespace App\Services\Statements;

use App\Services\Statements\PriorPdf\Sections;
use App\Services\Statements\PriorPdf\Wording;

/**
 * The zakat base as last year's zakat note computed it (Phase 5.5, R11): its lines in order and as printed, last year's
 * amounts, and this year's where the statements give them: the profit before zakat, the provisions formed in the year
 * (the provisions' movement tables, zakat's own aside), the zakat due. The rest (the additions to the base and the
 * deductions from it) are asked under last year's wording (`zakat.base.<n>`), proposed when last year's own figures
 * explain them: the balance-sheet components (capital, reserves, accumulated losses or retained earnings at the start
 * of the year, non-current assets, provisions, long-term liabilities) whose sum is last year's line (within a riyal; the
 * nearest when more than one set fits, none when still more) are taken again with this year's figures (`derived`, with
 * its components). The totals are the sums of their rows, as the arithmetic found them last year (R10).
 */
final class ZakatBase
{
    /** Provisions whose charge is a provision formed in the year (the base adds them back). */
    private const PROVISIONS = ['employee_benefits', 'other_provisions', 'receivables_impairment'];

    /**
     * @param ?array $file the confirmed file of last year's statements (with its document), null without one
     * @param array<string, Figure> $cur this year's statement figures
     * @param list<array> $schedules the movement tables (CurrentYearStatements)
     * @param array $inputs the report's answers
     * @return ?array{note: ?string, title: string, page: int, rows: list<array{n: int, label: string, kind: string, prior: float, current: Figure, from: string}>}
     */
    /** The balance-sheet lines a zakat base adds or deducts, at the end of the year or (`opening`) at its start. */
    private const COMPONENTS = [
        ['sofp.share_capital', 'closing'], ['sofp.statutory_reserve', 'closing'], ['sofp.other_reserves', 'closing'],
        ['sofp.retained_earnings', 'opening_positive'], ['sofp.retained_earnings', 'opening_negative'],
        ['sofp.ppe', 'closing'], ['sofp.other_assets', 'closing'], ['sofp.intangible_assets', 'closing'], ['sofp.investment_property', 'closing'],
        ['sofp.associates', 'closing'], ['sofp.fvoci_investments', 'closing'], ['sofp.amortised_cost_non_current', 'closing'],
        ['sofp.employee_benefits', 'closing'], ['sofp.employee_benefits', 'opening'], ['sofp.zakat_provision', 'opening'], ['sofp.other_provisions', 'closing'],
        ['sofp.due_to_related_parties_non_current', 'closing'], ['sofp.murabaha_loans_non_current', 'closing'], ['sofp.lease_liabilities_non_current', 'closing'],
    ];

    private const MOST = 4;   // components in a line, at most

    /**
     * The components with last year's figure (from last year's statements: its year and the one before) and this year's
     * (this year's statements; this year's start is last year's end).
     *
     * @return list<array{label: string, last: float, this: ?float}>
     */
    private static function components(array $file, LineCatalogue $catalogue, array $cur, array $cmp): array
    {
        $columns = [];
        foreach ($file['blocks'] ?? [] as $b => $block) {
            $other = array_values(array_filter($block['columns'], fn ($c) => $c['column'] !== $block['column'] && $c['year'] !== null));
            if ($block['column'] !== null && $other !== []) {
                $columns[$b] = $other[0]['column'];
            }
        }
        $end = PriorYearResolver::fromFile($catalogue, ['file' => $file])['figures'];
        $start = $columns === [] ? [] : PriorYearResolver::fromFile($catalogue, ['file' => $file, 'columns' => $columns])['figures'];
        $value = fn (array $figures, string $key) => isset($figures[$key]) && ! $figures[$key]->isMissing() ? $figures[$key]->amount : null;
        $labels = array_column(array_filter($catalogue->statement('financial_position'), fn ($i) => isset($i['key'])), 'label_ar', 'key');
        $out = [];
        foreach (self::COMPONENTS as [$key, $when]) {
            [$last, $now] = match ($when) {
                'closing' => [$value($end, $key), $value($cur, $key)],
                'opening' => [$value($start, $key), $value($cmp, $key)],
                'opening_positive' => [max(0.0, (float) $value($start, $key)), $value($cmp, $key) === null ? null : max(0.0, $value($cmp, $key))],
                'opening_negative' => [max(0.0, -(float) $value($start, $key)), $value($cmp, $key) === null ? null : max(0.0, -$value($cmp, $key))],
            };
            if ($last !== null && abs($last) > 0.5) {
                $out[] = ['label' => match ($when) {
                    'opening' => __('report_messages.zakat_components.opening', ['line' => $labels[$key]], 'ar'),
                    'opening_negative' => __('report_messages.zakat_components.accumulated_losses', [], 'ar'),
                    'opening_positive' => __('report_messages.zakat_components.retained_earnings', [], 'ar'),
                    default => $labels[$key],
                }, 'last' => abs($last), 'this' => $now === null ? null : abs($now)];
            }
        }

        return $out;
    }

    /**
     * Last year's line as a sum of components (within a riyal; the nearest when several sets fit, none when the nearest
     * are more than one), and this year's figure of the same components, signed as last year's line.
     *
     * @return ?array{current: Figure, components: list<string>}
     */
    private static function derive(float $prior, array $components): ?array
    {
        if (abs($prior) <= 0.5 || $components === []) {
            return null;
        }
        $fits = [];
        $walk = function (int $from, array $set, float $sum) use (&$walk, &$fits, $components, $prior) {
            if ($set !== [] && abs($sum - abs($prior)) <= PriorPdf\Tables::TOLERANCE) {
                $fits[] = ['set' => $set, 'off' => abs($sum - abs($prior))];
            }
            if (count($set) === self::MOST) {
                return;
            }
            for ($i = $from; $i < count($components); $i++) {
                $walk($i + 1, [...$set, $i], $sum + $components[$i]['last']);
            }
        };
        $walk(0, [], 0.0);
        if ($fits === []) {
            return null;
        }
        $best = min(array_column($fits, 'off'));
        $nearest = array_values(array_filter($fits, fn ($f) => $f['off'] === $best));
        if (count($nearest) !== 1) {
            return null;
        }
        $parts = array_map(fn ($i) => $components[$i], $nearest[0]['set']);
        if (in_array(null, array_column($parts, 'this'), true)) {
            return null;
        }
        $sum = array_sum(array_column($parts, 'this')) * ($prior < 0 ? -1 : 1);

        return ['current' => new Figure(round($sum, 2) + 0.0, 'derived', 'pending_confirmation'), 'components' => array_column($parts, 'label')];
    }

    /** A base line of last year's note as this year's calculation gives it: additions, deductions, the net base; else null. */
    private static function fromCalculation(string $label, ?array $calc, float $prior): ?Figure
    {
        $base = $calc['base'] ?? null;
        if ($base === null) {
            return null;
        }
        $sign = $prior < 0 ? -1 : 1;
        $amount = match (true) {
            (bool) preg_match('/(الإضافات|الاضافات|اضافات|إضافات)/u', $label) => abs(($base['equity'] ?? 0) + ($base['non_current_liabilities'] ?? 0)
                + max(0, $base['profit_difference'] ?? 0) + ($base['current_excess'] ?? 0) + ($base['extra_additions'] ?? 0)),
            (bool) preg_match('/(الحسميات|حسميات|الحسومات|الخصومات|المحسوم|الاستبعادات|يخصم)/u', $label) => abs(($base['deducted_assets'] ?? 0)
                + min(0, $base['profit_difference'] ?? 0) - abs($base['extra_deductions'] ?? 0)),
            (bool) preg_match('/(صافي|وعاء)\s*(ال)?وعاء|الوعاء\s+(الزكوي|الخاضع)/u', $label) => abs((float) ($calc['final_base'] ?? 0)),
            default => null,
        };

        return $amount === null ? null : new Figure(round($sign * $amount, 2) + 0.0, 'derived', 'ok', [['type' => 'zakat_calculation']]);
    }

    /** No zakat for the year, with its reason (`zakat.zero_reason`): the one switch for the base, the note and the provision's checks. */
    public static function none(array $inputs, array $cur): bool
    {
        return ($inputs['zakat.zero_reason'] ?? null) !== null && abs($cur['is.zakat']->amount ?? 0.0) <= (float) config('classification.balance_tolerance', 0.01);
    }

    /**
     * @param ?array $calc this year's zakat calculation (ZakatCalculator::of): the base's additions, deductions and net base
     *                     where last year's note prints them as lines, so the note shows this year's figures, never «[ناقص]»
     */
    public static function of(?array $file, array $cur, array $schedules, array $inputs, array $cmp = [], ?LineCatalogue $catalogue = null, ?array $calc = null): ?array
    {
        $doc = $file['document'] ?? null;
        if ($doc === null) {
            return null;
        }
        // The zakat note: titled so, with a table of the base (the policy «الزكاة» has none); the first such note.
        $section = null;
        $tables = [];
        foreach ($doc['sections'] as $s) {
            if ($s['parent'] !== null || $s['slip'] || ! preg_match('/الزكاة|الزكاه/u', $s['title'])) {
                continue;
            }
            $tables = array_values(array_filter(Sections::tablesOf($s['id'], $doc['sections'], $doc['tables']),
                fn ($t) => ! ($t['movement'] ?? false) && in_array('current', $t['roles']['roles'] ?? [], true)));
            if ($tables !== []) {
                $section = $s;
                break;
            }
        }
        if ($section === null) {
            return null;
        }
        $t = $tables[0];
        $at = array_search('current', $t['roles']['roles'], true);
        $provisions = [];
        foreach ($schedules as $sc) {
            if (in_array($sc['key'], self::PROVISIONS, true)) {
                $provisions[] = collect($sc['rows'])->firstWhere('key', $sc['increase'])['cells']['amount'] ?? new Figure(0.0, 'derived');
            }
        }

        // No zakat for the year: a line not answered is «-», never asked or proposed.
        $none = self::none($inputs, $cur);
        $rows = [];
        $n = 0;
        foreach ($t['rows'] as $i => $r) {
            if ($r['kind'] === 'heading') {
                continue;
            }
            $label = Wording::apply(trim($r['label']), $doc['corrections'] ?? [], $file['wording_choices'] ?? []);
            $n++;
            [$current, $from] = match (true) {
                $r['kind'] === 'total' => [null, 'total'],
                (bool) preg_match('/قبل\s+الزكا/u', $label) => [$cur['is.profit_before_zakat'] ?? Figure::missing(), 'statements'],
                (bool) preg_match('/مخصصات\s+(مكونة|مكونه)|المخصصات\s+المكونة/u', $label) => [$provisions === [] ? new Figure(0.0, 'derived') : Figure::sum($provisions), 'schedules'],
                (bool) preg_match('/الزكا[ةه]\s+(المستحق|المحمل)|زكا[ةه]\s+السنة/u', $label) => [isset($cur['is.zakat']) ? AssetNotesBuilder::neg($cur['is.zakat']) : Figure::missing(), 'statements'],
                default => [isset($inputs["zakat.base.{$n}"]) ? new Figure((float) $inputs["zakat.base.{$n}"], 'user_input', 'ok', [['type' => 'input', 'key' => "zakat.base.{$n}"]])
                    : ($none ? new Figure(0.0, 'derived') : Figure::missing()), 'input'],
            };
            $prior = (float) ($r['cells'][$at]['amount'] ?? 0.0);
            // The additions to the base, the deductions from it and the net base: this year's zakat calculation, signed as
            // last year's line (the accountant's answer still wins).
            if ($from === 'input' && ! $none && ! isset($inputs["zakat.base.{$n}"]) && ($fromCalc = self::fromCalculation($label, $calc, $prior)) !== null) {
                [$current, $from] = [$fromCalc, 'calculation'];
            }
            $rows[$i] = ['n' => $n, 'label' => $label, 'kind' => $r['kind'], 'sum_of' => $r['sum_of'] ?? [], 'prior' => $prior, 'current' => $current, 'from' => $from];
            // Not answered: proposed from last year's components, taken with this year's figures.
            if ($from === 'input' && $current->isMissing() && $catalogue !== null && ($d = self::derive($prior, self::components($file, $catalogue, $cur, $cmp))) !== null) {
                $rows[$i] = ['current' => $d['current'], 'from' => 'derived', 'components' => $d['components']] + $rows[$i];
            }
        }
        // The totals: the sums of their rows this year, as last year.
        foreach ($rows as $i => $r) {
            if ($r['kind'] === 'total') {
                $rows[$i]['current'] = Figure::sum(array_map(fn ($p) => $rows[$p]['current'] ?? new Figure(0.0, 'derived'), $r['sum_of']));
            }
        }

        return ['note' => $section['printed'], 'title' => Wording::apply($section['title'], $doc['corrections'] ?? [], $file['wording_choices'] ?? []),
            'page' => $t['page'], 'rows' => array_values(array_map(function ($r) {
                // Profit or loss by this year's and last year's signs (the line «صافي الخسارة قبل الزكاة» in a profitable year).
                $w = ProfitLossWording::label($r['label'], [$r['current']->isMissing() ? null : $r['current']->amount, $r['prior']]);

                return ['label' => $w['label'], 'label_by_sign' => $w['changed']] + array_diff_key($r, ['sum_of' => 1]);
            }, $rows))];
    }
}
