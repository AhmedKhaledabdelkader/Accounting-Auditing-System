<?php

namespace Tests\Feature;

use App\Models\StatementSynonym;
use App\Services\Statements\StatementSynonyms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 17: the synonyms of the statements' lines, a list the user sees and edits, started with common wordings. Each
 * wording once per statement, never a line's own label. Made-up wordings.
 */
class StatementSynonymsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/statement-synonyms';

    public function test_the_list_starts_with_common_wordings_and_names_its_lines(): void
    {
        $json = $this->getJson(self::URL)->assertOk()->json();

        $cash = collect($json['data'])->firstWhere('label', 'النقدية وما في حكمها');
        $this->assertSame(['financial_position', 'sofp.cash', 'النقد ومعادلات النقد'], [$cash['statement'], $cash['line_key'], $cash['line_label_ar']]);
        $this->assertGreaterThan(80, count($json['data']));
        $this->assertSame(['financial_position', 'comprehensive_income', 'cash_flows'], array_keys($json['lines']));
        $this->assertContains('sofp.total_assets', array_column($json['lines']['financial_position'], 'key'));
    }

    public function test_words_issued_statements_use_for_the_same_thing_read_as_one(): void
    {
        // R6: the file's word on the left reads as the template's; a joined «و» is the conjunction, not part of the word.
        $same = fn (string $a, string $b) => $this->assertSame(StatementSynonyms::wording($b), StatementSynonyms::wording($a), "{$a} = {$b}");
        $same('إجمالي الموجودات', 'إجمالي الأصول');
        $same('مجموع المطلوبات المتداولة', 'مجموع الالتزامات المتداولة');
        $same('إجمالي حقوق الشركاء', 'إجمالي حقوق الملكية');
        $same('إستهلاك ممتلكات وآلات ومعدات', 'اهلاك الموجودات الثابتة');
        $same('الممتلكات والآلات والمعدات', 'ممتلكات وآلات ومعدات');
        $this->assertNotSame(StatementSynonyms::wording('ودائع مرابحة'), StatementSynonyms::wording('دائع مرابحة'));   // a word that starts with «و»

        // A synonym that is now a line's own wording is not needed; the starting list's new ones come in.
        StatementSynonym::query()->delete();
        StatementSynonym::forceCreate(['statement' => 'financial_position', 'line_key' => 'sofp.total_assets', 'label' => 'إجمالي الموجودات', 'wording' => 'old']);
        (include database_path('migrations/2026_10_23_000002_refresh_statement_synonym_wordings.php'))->up();
        $this->assertNull(StatementSynonym::where('label', 'إجمالي الموجودات')->first());
        $this->assertSame('is.amortisation', StatementSynonym::where('label', 'إطفاء أصول أخرى')->value('line_key'));
    }

    public function test_due_to_a_joined_and_and_a_missing_space_read_as_the_template(): void
    {
        $same = fn (string $a, string $b) => $this->assertSame(StatementSynonyms::wording($b), StatementSynonyms::wording($a), "{$a} = {$b}");
        // Phase 4: «إلى أطراف» is «لأطراف» (never «من أطراف»), «و» before an equivalent word, «غير» printed joined, «الشرعية».
        $same('مستحق إلى أطراف ذات علاقة', 'مستحق لأطراف ذات علاقة');
        $this->assertNotSame(StatementSynonyms::wording('مستحق من أطراف ذات علاقة'), StatementSynonyms::wording('مستحق إلى أطراف ذات علاقة'));
        $same('إجمالي المطلوبات وحقوق الشركاء', 'إجمالي حقوق الملكية والالتزامات');
        $same('إجمالي الموجودات غيرالمتداولة', 'إجمالي الأصول غير المتداولة');
        $same('مخصص الزكاة الشرعية', 'مخصص الزكاة');

        // A user's deleted synonym from the older starting list stays deleted; only the new wordings come in.
        StatementSynonym::where('label', 'الأصول الثابتة')->delete();
        StatementSynonym::where('label', 'نقدية وأرصدة لدى البنوك')->delete();
        (include database_path('migrations/2026_10_24_000001_statement_synonyms_phase4.php'))->up();
        $this->assertNull(StatementSynonym::where('label', 'الأصول الثابتة')->first());
        $this->assertSame('sofp.cash', StatementSynonym::where('label', 'نقدية وأرصدة لدى البنوك')->value('line_key'));
    }

    public function test_a_synonym_is_added_edited_and_deleted(): void
    {
        $id = $this->postJson(self::URL, ['line_key' => 'is.general_admin', 'label' => '  مصاريف   إدارية عامة '])->assertCreated()
            ->assertJsonPath('label', 'مصاريف إدارية عامة')->assertJsonPath('statement', 'comprehensive_income')->json('id');
        // Kept by its wording: normalised words, in order (word order doesn't matter).
        $this->assertSame(\App\Services\Statements\StatementSynonyms::wording('عامة إدارية مصاريف'), StatementSynonym::find($id)->wording);

        $this->putJson(self::URL."/{$id}", ['line_key' => 'is.other_expenses', 'label' => 'مصاريف إدارية عامة'])->assertOk()
            ->assertJsonPath('line_key', 'is.other_expenses')->assertJsonPath('line_label_ar', 'مصروفات أخرى');
        // Saving its own wording again isn't a duplicate.
        $this->putJson(self::URL."/{$id}", ['line_key' => 'is.other_expenses', 'label' => 'عامة مصاريف إدارية'])->assertOk();

        $this->deleteJson(self::URL."/{$id}")->assertNoContent();
        $this->deleteJson(self::URL."/{$id}")->assertNotFound()->assertJson(['message' => 'هذا المرادف غير موجود.']);
        $this->putJson(self::URL."/{$id}", ['line_key' => 'is.revenue', 'label' => 'إيراد'])->assertNotFound();
    }

    public function test_a_wording_is_kept_once_and_never_a_lines_own_label(): void
    {
        // A line's own label (spelling and «ال» aside): no synonym needed.
        $this->postJson(self::URL, ['line_key' => 'sofp.cash', 'label' => 'نقد ومعادلات نقد'])->assertStatus(422)
            ->assertJsonValidationErrors(['label' => 'هذا النص هو اسم السطر «النقد ومعادلات النقد» نفسه، ولا يحتاج إلى مرادف.']);
        // The same wording twice in a statement, even for another line, word order aside.
        $this->postJson(self::URL, ['line_key' => 'sofp.murabaha_deposits', 'label' => 'في حكمها وما النقدية'])->assertStatus(422)
            ->assertJsonValidationErrors(['label' => 'هذا المرادف موجود بالفعل للسطر «النقد ومعادلات النقد» («النقدية وما في حكمها»).']);
        // The same wording in another statement is fine.
        $this->postJson(self::URL, ['line_key' => 'cf.closing_cash', 'label' => 'النقدية وما في حكمها'])->assertCreated();
        // An unknown line, a heading, a label with no Arabic.
        $this->postJson(self::URL, ['line_key' => 'sofp.nope', 'label' => 'بند'])->assertStatus(422)->assertJsonValidationErrors('line_key');
        $this->postJson(self::URL, ['line_key' => 'cf.noncash', 'label' => 'بند'])->assertStatus(422)->assertJsonValidationErrors('line_key');
        $this->postJson(self::URL, ['line_key' => 'sofp.cash', 'label' => 'Cash'])->assertStatus(422)->assertJsonValidationErrors('label');
        $this->postJson(self::URL, ['line_key' => 'sofp.cash'])->assertStatus(422)->assertJsonValidationErrors('label');
    }

    public function test_the_page_lists_them_in_arabic(): void
    {
        $this->get('/statement-synonyms')->assertOk()->assertSee('مرادفات بنود القوائم')->assertSee('/js/financial-report/synonyms.js', false);
    }
}
