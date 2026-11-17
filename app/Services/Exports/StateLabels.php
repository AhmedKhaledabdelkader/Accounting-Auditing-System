<?php

namespace App\Services\Exports;

/**
 * Arabic names of what a report state refers to by key (statement lines, movement-table cells, missing figures), for the
 * exports. The same rules as the wizard's (public/js/financial-report/describe.js); a name that can't be found is never
 * the code.
 */
final class StateLabels
{
    private array $lines = [];

    public function __construct(private array $state)
    {
        foreach (['financial_position', 'comprehensive_income', 'cash_flows'] as $s) {
            foreach ($state['statements'][$s] ?? [] as $r) {
                if (isset($r['key'])) {
                    $this->lines[$r['key']] = $r['label_ar'];
                }
            }
        }
    }

    public static function name(string $path): ?string
    {
        $v = __("statements.{$path}", [], 'ar');

        return is_string($v) && $v !== "statements.{$path}" ? $v : null;
    }

    public function line(?string $key): ?string
    {
        return $key === null ? null : ($this->lines[$key] ?? self::name("notes.{$key}"));
    }

    /** A movement-table cell `<schedule>.<row>[.<column>]`, or a schedule side `<schedule>.cost|accumulated`. */
    public function cell(string $key): ?string
    {
        $parts = explode('.', $key);
        $s = collect($this->state['schedules'] ?? [])->firstWhere('key', $parts[0]);
        if (! $s || ! isset($parts[1])) {
            return null;
        }
        if (in_array($parts[1], ['cost', 'accumulated'], true)) {
            return $s['title_ar'].' — '.($parts[1] === 'cost' ? 'التكلفة' : 'مجمع الإهلاك');
        }
        $row = collect($s['rows'])->firstWhere('key', $parts[1])['label_ar'] ?? self::name("schedule_rows.{$parts[1]}");
        $col = isset($parts[2]) ? (collect($s['columns'])->firstWhere('key', $parts[2])['label_ar'] ?? null) : null;

        return implode(' — ', array_filter([$s['title_ar'], $row, $col]));
    }

    /** @return array{0: string, 1: string} what is missing, and why */
    public function missing(array $m): array
    {
        $k = $m['key'];
        $unknown = __('financial_reports.ui.unknown', [], 'ar');
        $what = match (true) {
            $k === 'notes.company'                  => __('exports.draft.company_details', [], 'ar'),
            str_starts_with($k, 'notes.')           => __('exports.draft.note', ['note' => self::name('notes.'.substr($k, 6)) ?? $unknown], 'ar'),
            str_starts_with($k, 'comparative.')     => (self::name('statements.'.substr($k, 12)) ?? $unknown).' — '.self::name('scopes.comparative'),
            in_array($k, ['comprehensive_income', 'changes_in_equity', 'cash_flows'], true) => self::name("statements.{$k}") ?? $unknown,
            default                                 => $this->cell($k) ?? $this->line($k) ?? $unknown,
        };

        return [$what, self::name("missing_reasons.{$m['reason']}") ?? $unknown];
    }
}
