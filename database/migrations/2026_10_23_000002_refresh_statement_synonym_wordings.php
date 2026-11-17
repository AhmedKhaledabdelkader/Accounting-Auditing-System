<?php

use App\Services\Statements\LineCatalogue;
use App\Services\Statements\StatementSynonyms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One name per thing (the accountant's rule R6): a synonym's wording now reads «الموجودات» as «الأصول», «حقوق الشركاء» as
 * «حقوق الملكية», «استهلاك» as «إهلاك» and a joined «و» as the conjunction (StatementSynonyms::wording). Every saved
 * wording is worked out again; one that now is a line's own wording, or another synonym's, is no longer needed and goes.
 * The starting list's new wordings (depreciation and amortisation, R5) are added.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalogue = new LineCatalogue;
        $own = [];
        $seen = [];
        foreach (DB::table('statement_synonyms')->orderBy('id')->get() as $row) {
            $own[$row->statement] ??= array_values(StatementSynonyms::own($catalogue, $row->statement));
            $wording = StatementSynonyms::wording($row->label);
            if (in_array($wording, $own[$row->statement], true) || isset($seen[$row->statement][$wording])) {
                DB::table('statement_synonyms')->where('id', $row->id)->delete();
                continue;
            }
            $seen[$row->statement][$wording] = true;
            DB::table('statement_synonyms')->where('id', $row->id)->update(['wording' => $wording]);
        }
        $now = now();
        foreach (StatementSynonyms::initial() as $s) {
            if (! isset($seen[$s['statement']][$s['wording']])) {
                DB::table('statement_synonyms')->insert($s + ['created_at' => $now, 'updated_at' => $now]);
                $seen[$s['statement']][$s['wording']] = true;
            }
        }
    }

    public function down(): void
    {
        // The wordings stay as worked out: the older rule read them the same or less.
    }
};
