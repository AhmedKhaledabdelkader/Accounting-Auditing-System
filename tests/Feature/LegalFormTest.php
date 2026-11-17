<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Services\Portal\CommercialRegistrationReader;
use App\Services\Portal\Workspace;
use App\Services\Statements\ArticlesOfAssociation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A company (شركة ذات مسؤولية محدودة) or a sole proprietorship (مؤسسة فردية): read from the commercial registration or chosen by the
 * accountant. A company has articles of association, a sole proprietorship none (its owner, from the registration, gives the zakat
 * share); while the form isn't known the articles are optional. The articles never stop the work.
 */
class LegalFormTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/companies';

    protected function setUp(): void
    {
        parent::setUp();
        // An office's administrator: the client's requests belong to an office.
        $admin = $this->admin();
        $admin->office_id = \App\Models\Office::create(['name_ar' => 'مكتب الاختبار', 'code' => 'test'])->id;
        $this->actingAs($admin);
    }

    public function test_the_legal_form_is_unknown_until_chosen_and_the_articles_follow_it(): void
    {
        $created = $this->postJson(self::URL, ['name_ar' => 'مؤسسة البحر'])->assertCreated()->json();
        $this->assertSame(['llc', false, null], [$created['legal_form'], $created['legal_form_known'], $created['articles_required']]);

        $sole = $this->patchJson(self::URL."/{$created['id']}", ['legal_form' => 'sole_proprietorship'])->assertOk()->json();
        $this->assertSame(['sole_proprietorship', true, false], [$sole['legal_form'], $sole['legal_form_known'], $sole['articles_required']]);
        $this->assertSame('user', $sole['profile']['legal_form_source']);

        $llc = $this->patchJson(self::URL."/{$created['id']}", ['legal_form' => 'llc'])->assertOk()->json();
        $this->assertSame([true, true], [$llc['legal_form_known'], $llc['articles_required']]);

        // Chosen when the company is made.
        $made = $this->postJson(self::URL, ['name_ar' => 'شركة النخيل', 'legal_form' => 'llc'])->assertCreated()->json();
        $this->assertSame([true, true], [$made['legal_form_known'], $made['articles_required']]);

        // Only these two.
        $this->postJson(self::URL, ['name_ar' => 'أ', 'legal_form' => 'jsc'])->assertStatus(422)->assertJsonValidationErrors('legal_form');
    }

    public function test_the_registrations_legal_form_is_placed_by_its_words(): void
    {
        $this->assertSame('llc', CommercialRegistrationReader::legalForm('شركة ذات مسؤولية محدودة'));
        $this->assertSame('llc', CommercialRegistrationReader::legalForm('شركة شخص واحد ذات مسئولية محدودة'));
        $this->assertSame('llc', CommercialRegistrationReader::legalForm('شركة شخص واحد'));
        $this->assertSame('sole_proprietorship', CommercialRegistrationReader::legalForm('مؤسسة فردية'));
        $this->assertSame('sole_proprietorship', CommercialRegistrationReader::legalForm('مؤسسه'));
        $this->assertSame('other', CommercialRegistrationReader::legalForm('شركة مساهمة مبسطة'));
        $this->assertNull(CommercialRegistrationReader::legalForm(null));
        $this->assertNull(CommercialRegistrationReader::legalForm('سجل رئيسي'));

        // A new certificate prints only the unified national number: it is the number.
        $new = CommercialRegistrationReader::normalise(['registration_number' => null, 'unified_number' => '7002965197', 'legal_form' => 'Limited liability company']);
        $this->assertSame(['7002965197', '7002965197', 'llc'], [$new['number'], $new['unified_number'], $new['legal_form']]);
        $this->assertSame('1010123456', CommercialRegistrationReader::normalise(['registration_number' => '١٠١٠١٢٣٤٥٦', 'unified_number' => '7002965197'])['number']);
        $this->assertNull(CommercialRegistrationReader::normalise(['registration_number' => '123', 'unified_number' => null])['number']);

        $read = CommercialRegistrationReader::normalise(['registration_number' => '1010123456', 'legal_form' => ' مؤسسة فردية ', 'owner_name' => 'خالد السالم']);
        $this->assertSame(['مؤسسة فردية', 'sole_proprietorship', 'خالد السالم'], [$read['legal_form_text'], $read['legal_form'], $read['owner_name']]);
    }

    public function test_a_sole_proprietorships_registration_sets_the_form_the_owner_and_closes_the_articles_request(): void
    {
        Storage::fake('local');
        $this->fakeRegistration(['legal_form' => 'مؤسسة فردية', 'owner_name' => 'خالد السالم']);
        $id = $this->postJson(self::URL, ['name_ar' => 'مؤسسة لؤلؤة الطب'])->json('id');
        $company = Company::find($id);
        $articles = DocumentRequest::create(['office_id' => $company->office_id, 'company_id' => $id, 'year' => 2025, 'section' => 'عامة', 'title' => 'صورة من عقد تأسيس الشركة', 'position' => 1]);
        $other = DocumentRequest::create(['office_id' => $company->office_id, 'company_id' => $id, 'year' => 2025, 'section' => 'عامة', 'title' => 'صورة من السجل التجاري الرئيسي والفرع', 'position' => 2]);

        $res = $this->post(self::URL."/{$id}/registration", ['file' => UploadedFile::fake()->createWithContent('cr.pdf', 'CR')], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(['sole_proprietorship', true, false, '1010123456'], [$res['legal_form'], $res['legal_form_known'], $res['articles_required'], $res['cr_number']]);
        $this->assertSame(['registration', 'خالد السالم'], [$res['profile']['legal_form_source'], $res['profile']['registration']['owner_name']]);
        $this->assertSame([DocumentRequest::NOT_APPLICABLE, DocumentRequest::PENDING], [$articles->fresh()->status, $other->fresh()->status]);

        // The owner holds it all, a Saudi: zakat on all of it.
        $owners = Company::find($id)->owners();
        $this->assertSame([['خالد السالم', 'saudi']], array_map(fn ($o) => [$o['name'], $o['group']], $owners));
        $this->assertSame(100.0, ArticlesOfAssociation::zakatShare($owners));
        $this->assertSame('sole_proprietorship', Company::find($id)->ownersSource());
    }

    public function test_a_companys_registration_keeps_the_articles_required_and_another_form_stays_unknown(): void
    {
        Storage::fake('local');
        $this->fakeRegistration(['legal_form' => 'شركة ذات مسؤولية محدودة']);
        $id = $this->postJson(self::URL, ['name_ar' => 'شركة النور'])->json('id');
        $res = $this->post(self::URL."/{$id}/registration", ['file' => UploadedFile::fake()->createWithContent('cr.pdf', 'CR')], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(['llc', true, true], [$res['legal_form'], $res['legal_form_known'], $res['articles_required']]);
        $this->assertSame([], Company::find($id)->owners());   // a company's owners are in its articles

        $this->fakeRegistration(['legal_form' => 'شركة مساهمة مبسطة', 'registration_number' => '1010999999']);
        $id = $this->postJson(self::URL, ['name_ar' => 'شركة الأفق'])->json('id');
        $res = $this->post(self::URL."/{$id}/registration", ['file' => UploadedFile::fake()->createWithContent('cr.pdf', 'CR2')], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([false, null, 'other'], [$res['legal_form_known'], $res['articles_required'], $res['profile']['registration']['legal_form']]);

        // A file that isn't a registration is refused on its own field.
        $this->fakeRegistration(['is_commercial_registration' => false]);
        $this->post(self::URL."/{$id}/registration", ['file' => UploadedFile::fake()->createWithContent('x.pdf', 'X')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('registration_file');
    }

    public function test_the_checklist_marks_the_articles_not_applicable_for_a_sole_proprietorship(): void
    {
        $company = Company::forceCreate(['name_ar' => 'مؤسسة الأمل', 'legal_form' => 'sole_proprietorship', 'profile' => ['legal_form_source' => 'user']]);
        app(Workspace::class)->applyChecklist($company, 2025, null);
        $requests = DocumentRequest::where('company_id', $company->id)->get();
        $articles = $requests->filter(fn ($r) => Workspace::isArticles($r->title));
        $this->assertCount(1, $articles);
        $this->assertSame(DocumentRequest::NOT_APPLICABLE, $articles->first()->status);
        $this->assertTrue($requests->reject(fn ($r) => Workspace::isArticles($r->title))->every(fn ($r) => $r->status === DocumentRequest::PENDING));

        // A company's stays asked.
        $llc = Company::forceCreate(['name_ar' => 'شركة الأمل', 'legal_form' => 'llc', 'profile' => ['legal_form_source' => 'user']]);
        app(Workspace::class)->applyChecklist($llc, 2025, null);
        $this->assertSame(DocumentRequest::PENDING, DocumentRequest::where('company_id', $llc->id)->get()->first(fn ($r) => Workspace::isArticles($r->title))->status);
    }

    /** The AI's reading of a registration, as given (the rest a valid registration's). */
    private function fakeRegistration(array $answer): void
    {
        $answer += ['readable' => true, 'is_commercial_registration' => true, 'registration_number' => '1010123456', 'unified_number' => null, 'company_name' => null,
            'activity' => 'تجارة', 'city' => 'الرياض', 'issue_date' => '2023-01-01', 'issue_date_hijri' => '1444/06/08', 'expiry_date' => '2099-01-01',
            'legal_form' => null, 'owner_name' => null];
        $driver = new class($answer) implements \App\Support\Llm\ClassifierDriver
        {
            public function __construct(private array $answer) {}

            public function classify(\App\Support\Llm\ToolCall $call): \GuzzleHttp\Promise\PromiseInterface
            {
                return \GuzzleHttp\Promise\Create::promiseFor(new \App\Support\Llm\ToolAnswer($this->answer, null));
            }
        };
        config(['financial_statements.prior_pdf.cache' => sys_get_temp_dir().'/cr-'.uniqid()]);
        $this->app->instance(\App\Support\Llm\ClassifierDriver::class, $driver);
        $this->app->forgetInstance(\App\Services\Statements\PriorPdf\Llm::class);
    }
}
