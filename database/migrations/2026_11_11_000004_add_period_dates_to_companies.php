<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company's fiscal period is kept as real dates, with their years (from 1/7/2025 to 1/7/2026), not only a day and a month: the
 * period its requests and files are for. The recurring end (month, day) stays in step with it for the statements' own defaults.
 * Companies made before get the period of their latest year of requests (else last year) from the fiscal year they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->date('period_start')->nullable()->after('fiscal_year_start_day');
            $table->date('period_end')->nullable()->after('period_start');
        });
        $years = DB::table('document_requests')->select('company_id', DB::raw('max(year) as year'))->groupBy('company_id')->pluck('year', 'company_id');
        foreach (DB::table('companies')->get(['id', 'fiscal_year_end_month', 'fiscal_year_end_day']) as $c) {
            $year = (int) ($years[$c->id] ?? now()->year - 1);
            $day = min((int) $c->fiscal_year_end_day, (int) cal_days_in_month(CAL_GREGORIAN, (int) $c->fiscal_year_end_month, $year));
            $end = new DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $c->fiscal_year_end_month, $day));
            DB::table('companies')->where('id', $c->id)->update(['period_start' => $end->modify('-1 year +1 day')->format('Y-m-d'), 'period_end' => $end->format('Y-m-d')]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['period_start', 'period_end']));
    }
};