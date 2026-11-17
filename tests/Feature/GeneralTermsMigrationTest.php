<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The cleanup of the starting rules and synonyms (2026_10_24_000003) removes only what was seeded and never touched:
 * what a user created or edited stays, the same words included.
 */
class GeneralTermsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const LABEL = 'مجموع النقد المتوفر من جميع الأنشطة';

    private const SEEDED_WORDS = ['قبل التشغيل', 'قبل التشغييل', 'تأسيس'];

    private function migration(): object
    {
        return require database_path('migrations/2026_10_24_000003_general_terms_only.php');
    }

    /** The database as before the cleanup, with the synonym as given (created, updated), then the cleanup run. */
    private function cleanup(?string $created, ?string $updated): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::table('statement_synonyms')->where('label', self::LABEL)->delete();
        if ($created !== null) {
            DB::table('statement_synonyms')->insert(['statement' => 'cash_flows', 'line_key' => 'cf.net_change', 'label' => self::LABEL,
                'wording' => \App\Services\Statements\StatementSynonyms::wording(self::LABEL), 'created_at' => $created, 'updated_at' => $updated]);
        }
        $migration->up();
    }

    private function synonym(): ?object
    {
        return DB::table('statement_synonyms')->where('label', self::LABEL)->first();
    }

    public function test_only_the_seeded_untouched_synonym_goes(): void
    {
        $seededAt = (string) DB::table('statement_synonyms')->value('created_at');

        // Seeded with the starting list, never edited: removed.
        $this->cleanup($seededAt, $seededAt);
        $this->assertNull($this->synonym());

        // The same wording added by a user later (another time than the starting list's): kept, theirs.
        $this->cleanup('2026-11-02 10:00:00', '2026-11-02 10:00:00');
        $this->assertFalse((bool) $this->synonym()->seeded);

        // Seeded, then edited on the synonyms page: kept.
        $this->cleanup($seededAt, '2026-11-02 10:00:00');
        $this->assertNotNull($this->synonym());

        // The other starting synonyms are marked seeded; a user's edit of one makes it theirs.
        $this->assertSame(0, DB::table('statement_synonyms')->where('label', '!=', self::LABEL)->where('seeded', false)->count());
        $row = DB::table('statement_synonyms')->where('label', '!=', self::LABEL)->first();
        $this->putJson("/api/v1/statement-synonyms/{$row->id}", ['line_key' => $row->line_key, 'label' => $row->label.' المعدل'])->assertOk();
        $this->assertFalse((bool) DB::table('statement_synonyms')->find($row->id)->seeded);
    }

    public function test_only_the_seeded_words_of_a_rule_lose_the_misspelling(): void
    {
        $words = fn (string $name) => json_decode(DB::table('classification_rules')->where('name', $name)->value('name_words'), true);
        $set = fn (string $name, array $w) => DB::table('classification_rules')->where('name', $name)->update(['name_words' => json_encode($w, JSON_UNESCAPED_UNICODE)]);
        $mine = ['قبل التشغيل', 'قبل التشغييل', 'تأسيس', 'مصاريف تأسيس'];
        // As seeded; edited by a user; and a user's own rule with the same word.
        $set('مجمع إطفاء ما قبل التشغيل', self::SEEDED_WORDS);
        $set('مصروفات ما قبل التشغيل', $mine);
        DB::table('classification_rules')->insert(['name' => 'تكاليف تشغيل تجريبي', 'sort' => 999, 'name_words' => json_encode(['قبل التشغييل'], JSON_UNESCAPED_UNICODE),
            'also_words' => '[]', 'exclude_words' => '[]', 'heading_words' => '[]', 'review_side' => 'none', 'ai_classes' => '[]', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now()]);

        $this->migration()->down();
        $this->migration()->up();

        $this->assertSame(['قبل التشغيل', 'تأسيس'], $words('مجمع إطفاء ما قبل التشغيل'));
        $this->assertSame($mine, $words('مصروفات ما قبل التشغيل'));
        $this->assertSame(['قبل التشغييل'], $words('تكاليف تشغيل تجريبي'));
    }
}
