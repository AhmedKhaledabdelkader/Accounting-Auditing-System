<?php

use App\Services\Statements\LineCatalogue;
use App\Services\Statements\StatementSynonyms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reading last year's statements (Phase 4): a synonym's wording now reads «إلى أطراف» as «لأطراف», «غيرالمتداولة» as
 * «غير المتداولة», «الزكاة الشرعية» as «الزكاة», and a joined «و» before the equivalent words too
 * (StatementSynonyms::wording). Every saved wording is worked out again; one that now is a line's own wording, or another
 * synonym's, goes. The starting list's new wordings are added; the older ones a user deleted stay deleted.
 */
return new class extends Migration
{
    /** The starting list's wordings added with this migration. */
    private const NEW = ['نقدية وأرصدة لدى البنوك', 'النقد والأرصدة لدى البنوك', 'تكاليف التشغيل', 'تكلفة التشغيل', 'صافي الخسارة قبل الزكاة',
        'صافي خسارة قبل الزكاة', 'صافي الربح (الخسارة) قبل الزكاة', 'صافي خسارة السنة', 'صافي الخسارة للسنة', 'صافي ربح (خسارة) السنة',
        'إجمالي الخسائر الشاملة للسنة', 'إجمالي الخسارة الشاملة للسنة', 'صافي التدفق النقدي من النشاط التشغيلي', 'صافي التدفق النقدي من الأنشطة التشغيلية',
        'صافي التدفق النقدي من الأنشطة التمويلية', 'صافي التغير في النقد', 'رصيد النقد في بداية السنة',
        'رصيد النقدية في بداية السنة', 'رصيد النقد في نهاية السنة', 'رصيد النقدية في نهاية السنة', 'صافي ربح (خسارة) العام قبل الزكاة', 'صافي الربح قبل الزكاة'];

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
            if (in_array($s['label'], self::NEW, true) && ! isset($seen[$s['statement']][$s['wording']])) {
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
