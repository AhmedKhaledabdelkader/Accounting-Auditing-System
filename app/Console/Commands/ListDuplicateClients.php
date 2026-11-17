<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Companies of one office that share a commercial registration number: a company is one client, so these need one of them fixed. */
class ListDuplicateClients extends Command
{
    protected $signature = 'clients:duplicates';

    protected $description = 'List companies of one office that share a commercial registration number';

    public function handle(): int
    {
        $groups = DB::table('companies')->whereNotNull('cr_number')->where('cr_number', '<>', '')->select('office_id', 'cr_number')
            ->groupBy('office_id', 'cr_number')->havingRaw('count(*) > 1')->get();
        if ($groups->isEmpty()) {
            $this->info('No company shares its registration number.');

            return self::SUCCESS;
        }
        foreach ($groups as $g) {
            $this->warn("Office {$g->office_id}, registration {$g->cr_number}:");
            foreach (Company::withoutGlobalScopes()->where('office_id', $g->office_id)->where('cr_number', $g->cr_number)->get() as $c) {
                $this->line("  {$c->id}  {$c->clientName()} / {$c->name_ar}  (created {$c->created_at})");
            }
        }
        $this->line('Fix the number of one of them from its client page (Edit details). The application already refuses a duplicate; once none is left, add the index by hand:');
        $this->line('  ALTER TABLE companies ADD UNIQUE companies_office_cr_unique (office_id, cr_number);');

        return self::FAILURE;
    }
}
