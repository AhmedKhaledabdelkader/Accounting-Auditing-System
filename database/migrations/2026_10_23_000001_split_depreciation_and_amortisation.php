<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Depreciation and amortisation are separate lines (the accountant's rule R5): what was on «مصروف الإهلاك والإطفاء»
 * (`is.depreciation_amortisation`) moves to «مصروف الإهلاك» (`is.depreciation`) in every report's own data, the user's
 * synonyms and the taxonomy. Saved versions stay as saved; their files read the old key (FinancialReportVersion).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'financial_reports'    => ['inputs', 'account_overrides', 'adjustments', 'prior_figures', 'movement_decisions', 'notes', 'acknowledgements', 'prior_upload'],
        'statement_synonyms'   => ['line_key'],
        'taxonomy_sub_classes' => ['line_key'],
    ];

    public function up(): void
    {
        $this->rename('is.depreciation_amortisation', 'is.depreciation');
    }

    public function down(): void
    {
        // Amortisation goes back on the shared line too.
        $this->rename('is.depreciation', 'is.depreciation_amortisation');
        $this->rename('is.amortisation', 'is.depreciation_amortisation');
    }

    private function rename(string $from, string $to): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->where($column, 'like', '%'.$from.'%')->orderBy('id')->each(function ($row) use ($table, $column, $from, $to) {
                    // The key whole, as a JSON string or the column's value; never part of a longer key.
                    $value = preg_replace('/(?<![\w.])'.preg_quote($from, '/').'(?![\w.])/', $to, $row->{$column});
                    DB::table($table)->where('id', $row->id)->update([$column => $value]);
                });
            }
        }
    }
};
