<?php

use App\Services\Statements\StatementSynonyms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The starting rules and synonyms keep general accounting terms only (P4): a client's own spelling or wording is learned
 * from the user (the rules page, the synonyms page), not seeded. Out: a misspelling of «قبل التشغيل» in the pre-operating
 * rules' words, and one client's wording of the cash flow's net change from the starting synonyms.
 *
 * Only what was seeded and never touched goes; what a user created or edited stays:
 * - a rule loses the word only while its words are exactly the ones seeded;
 * - a synonym goes only when it has the seeded values, was never edited (created = updated: the synonyms page moves the
 *   update time; the wording migrations don't), and came in with the other seeded synonyms (the same creation time: a
 *   user adding the same wording later has another).
 * Synonyms now say whether they were seeded (`seeded`), set here for the rows the same way; an edit on the page clears it.
 */
return new class extends Migration
{
    private const RULES = ['مجمع إطفاء ما قبل التشغيل', 'مصروفات ما قبل التشغيل'];

    private const SEEDED_WORDS = ['قبل التشغيل', 'قبل التشغييل', 'تأسيس'];

    private const WORD = 'قبل التشغييل';

    /** The synonym seeded once (2026_10_24_000001) and no longer: [statement, line, label]. */
    private const SYNONYM = ['cash_flows', 'cf.net_change', 'مجموع النقد المتوفر من جميع الأنشطة'];

    public function up(): void
    {
        foreach (DB::table('classification_rules')->whereIn('name', self::RULES)->get(['id', 'name_words']) as $rule) {
            if ((json_decode($rule->name_words, true) ?: []) === self::SEEDED_WORDS) {
                DB::table('classification_rules')->where('id', $rule->id)->update([
                    'name_words' => json_encode(array_values(array_diff(self::SEEDED_WORDS, [self::WORD])), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            }
        }

        Schema::table('statement_synonyms', function (Blueprint $table) {
            $table->boolean('seeded')->default(false);
        });
        $seeds = [implode('|', self::SYNONYM) => true];
        foreach (StatementSynonyms::initial() as $s) {
            $seeds["{$s['statement']}|{$s['line_key']}|{$s['label']}"] = true;
        }
        $rows = DB::table('statement_synonyms')->get(['id', 'statement', 'line_key', 'label', 'created_at', 'updated_at']);
        $untouched = $rows->filter(fn ($r) => isset($seeds["{$r->statement}|{$r->line_key}|{$r->label}"]) && $r->created_at !== null && $r->created_at === $r->updated_at);
        $batches = $untouched->countBy('created_at');
        $seeded = $untouched->filter(fn ($r) => $batches[$r->created_at] > 1);
        DB::table('statement_synonyms')->whereIn('id', $seeded->pluck('id'))->update(['seeded' => true]);
        DB::table('statement_synonyms')->where('seeded', true)
            ->where('statement', self::SYNONYM[0])->where('line_key', self::SYNONYM[1])->where('label', self::SYNONYM[2])->delete();
    }

    public function down(): void
    {
        // The word and the synonym stay out (data decisions); the marker goes.
        Schema::table('statement_synonyms', function (Blueprint $table) {
            $table->dropColumn('seeded');
        });
    }
};
