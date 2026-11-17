<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A request is for a period, the company's fiscal year: `period_start` to `period_end` (`year` stays the year the period ends in).
 * Earlier requests take the period their year and their company's fiscal year give.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->date('period_start')->nullable()->after('year');
            $table->date('period_end')->nullable()->after('period_start');
        });
        $fiscal = DB::table('companies')->get(['id', 'fiscal_year_end_month', 'fiscal_year_end_day'])->keyBy('id');
        foreach (DB::table('document_requests')->distinct()->get(['company_id', 'year']) as $row) {
            $c = $fiscal[$row->company_id] ?? null;
            if ($c === null) {
                continue;
            }
            $end = new DateTimeImmutable(sprintf('%d-%02d-%02d', $row->year, $c->fiscal_year_end_month, min($c->fiscal_year_end_day, cal_days_in_month(CAL_GREGORIAN, $c->fiscal_year_end_month, $row->year))));
            DB::table('document_requests')->where('company_id', $row->company_id)->where('year', $row->year)
                ->update(['period_start' => $end->modify('-1 year +1 day')->format('Y-m-d'), 'period_end' => $end->format('Y-m-d')]);
        }
    }

    public function down(): void
    {
        Schema::table('document_requests', fn (Blueprint $table) => $table->dropColumn(['period_start', 'period_end']));
    }
};