<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A client is a person or firm the office works for, and its company: the client's name and email (the login's contact), the
 * fiscal year as a period (from day/month to day/month; the end was already kept), and the commercial registration number as the
 * company's identity: one company, one client, per office (`unique (office_id, cr_number)`). Companies made before this keep
 * their data: the client's name is the login's name (else the company's), the fiscal start is the day after the end.
 *
 * Two companies of one office already sharing a number cannot take the unique index: it is then left off (said in the log), the
 * application still refuses a duplicate, and `php artisan clients:duplicates` lists them so the accountant can fix them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('client_name')->nullable()->after('name_en');
            $table->string('client_email')->nullable()->after('client_name');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1)->after('branches');
            $table->unsignedTinyInteger('fiscal_year_start_day')->default(1)->after('fiscal_year_start_month');
        });

        foreach (DB::table('companies')->get(['id', 'name_ar', 'fiscal_year_end_month', 'fiscal_year_end_day']) as $c) {
            $login = DB::table('users')->where('company_id', $c->id)->first(['name', 'email']);
            $end = new DateTimeImmutable(sprintf('2024-%02d-%02d', $c->fiscal_year_end_month, $c->fiscal_year_end_day));   // a leap year: 29 Feb exists
            $start = $end->modify('+1 day');
            DB::table('companies')->where('id', $c->id)->update(['client_name' => $login->name ?? $c->name_ar, 'client_email' => $login->email ?? null,
                'fiscal_year_start_month' => (int) $start->format('n'), 'fiscal_year_start_day' => (int) $start->format('j')]);
        }

        $duplicates = DB::table('companies')->whereNotNull('cr_number')->where('cr_number', '<>', '')->select('office_id', 'cr_number')
            ->groupBy('office_id', 'cr_number')->havingRaw('count(*) > 1')->get();
        if ($duplicates->isEmpty()) {
            Schema::table('companies', fn (Blueprint $table) => $table->unique(['office_id', 'cr_number'], 'companies_office_cr_unique'));
        } else {
            Log::warning('companies: the unique index on (office_id, cr_number) was not added, these are shared', ['duplicates' => $duplicates->toArray()]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            try {
                $table->dropUnique('companies_office_cr_unique');
            } catch (Throwable) {
                // never added
            }
            $table->dropColumn(['client_name', 'client_email', 'fiscal_year_start_month', 'fiscal_year_start_day']);
        });
    }
};
