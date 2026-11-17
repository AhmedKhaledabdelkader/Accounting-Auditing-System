<?php

namespace Tests\Feature;

use App\Models\ClassificationImport;
use App\Models\ClassificationImportMapping;
use App\Models\ClassificationRun;
use App\Services\ClassificationService;
use GuzzleHttp\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ClassifiedRows;
use Tests\Support\FakeAi;
use Tests\Support\OpenApiContract;
use Tests\Support\PdfFiles;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/**
 * Every endpoint, its valid request and every documented error, checked against public/docs/openapi.yaml: the status,
 * the body against the schema for that status, and no undocumented fields.
 *
 * Runs through Laravel's HTTP kernel by default. Against a real running server (real HTTP, same checks):
 *   CONTRACT_BASE_URL=http://127.0.0.1:8011 DB_DATABASE=<the server's sqlite file> php artisan test --filter=ApiContractTest
 * The server must run with QUEUE_CONNECTION=sync, CLASSIFIER_CACHE=true and CLASSIFIER_ANTHROPIC_BASE_URL (or, with
 * CLASSIFIER_PROVIDER=gemini, CLASSIFIER_GEMINI_BASE_URL) pointing at
 * tests/Support/contract/fake-ai-server.php (see docs/08). CONTRACT_REPORT=<file> writes the checked statuses as JSON.
 */
class ApiContractTest extends TestCase
{
    use ClassifiedRows, PdfFiles, TrialBalanceFiles {
        ClassifiedRows::account insteadof TrialBalanceFiles;
    }

    private const IMPORT_HEADER = ['رقم الحساب', 'الميزانية', 'الإيضاحات', 'اسم الحساب', 'أول مدين', 'أول دائن', 'حركة مدينة', 'حركة دائنة',
        'أخر مدين', 'أخر دائن', 'اسم الشركة'];

    /** Statuses a live server can't be made to return (an unexpected crash): checked through the kernel only. */
    private const KERNEL_ONLY = ['POST /api/v1/classify/jobs' => ['500'], 'POST /api/v1/classify/trial-balance' => ['500'],
        'POST /api/v1/financial-reports/{id}/prior-year/statements-file' => ['413']];

    private ?Client $live = null;

    protected function setUp(): void
    {
        parent::setUp();
        if ($base = getenv('CONTRACT_BASE_URL')) {
            $this->live = new Client(['base_uri' => rtrim($base, '/'), 'http_errors' => false, 'timeout' => 120]);
        } else {
            Storage::fake('local');
            config(['classification.cache.enabled' => true]);
            Http::fake(['*' => function ($request) {
                [$status, $body] = FakeAi::respond($request->data());

                return Http::response($body, $status);
            }]);
        }
        Artisan::call('migrate:fresh');   // the live server shares this database file
    }

    public static function tearDownAfterClass(): void
    {
        if ($file = getenv('CONTRACT_REPORT')) {
            file_put_contents($file, json_encode(array_map(fn ($s) => array_keys($s), OpenApiContract::$checked), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        parent::tearDownAfterClass();
    }

    /* ---------- transport: kernel or real HTTP ---------- */

    /** @return array{status: int, type: ?string, body: string} */
    private function send(string $method, string $uri, ?array $json = null, array $files = [], array $form = []): array
    {
        if ($this->live) {
            $options = ['headers' => ['Accept' => 'application/json']];
            if ($files) {
                $options['multipart'] = array_merge(
                    array_map(fn ($k, $f) => ['name' => $k, 'contents' => fopen($f->getRealPath(), 'r'), 'filename' => $f->getClientOriginalName()], array_keys($files), $files),
                    array_map(fn ($k, $v) => ['name' => $k, 'contents' => (string) $v], array_keys($form), $form),
                );
            } elseif ($json !== null) {
                $options['json'] = $json;
            }
            $r = $this->live->request($method, $uri, $options);

            return ['status' => $r->getStatusCode(), 'type' => $r->getHeaderLine('Content-Type') ?: null, 'body' => (string) $r->getBody()];
        }

        $r = $files || $form
            ? $this->call($method, $uri, $form, [], $files, ['HTTP_ACCEPT' => 'application/json'])
            : $this->json($method, $uri, $json ?? []);
        $base = $r->baseResponse;
        $body = match (true) {
            $base instanceof StreamedResponse   => $r->streamedContent(),
            $base instanceof BinaryFileResponse => file_get_contents($base->getFile()->getPathname()),
            default                             => (string) $r->getContent(),
        };

        return ['status' => $r->getStatusCode(), 'type' => $r->headers->get('Content-Type'), 'body' => $body];
    }

    /** Sends, asserts the status, and checks the body against the spec. Returns the decoded JSON. */
    private function expect(string $operation, int $status, string $method, string $uri, ?array $json = null, array $files = [], array $form = []): mixed
    {
        $r = $this->send($method, $uri, $json, $files, $form);
        $this->assertSame($status, $r['status'], "{$operation}: expected {$status}, got {$r['status']}: ".mb_substr($r['body'], 0, 400));
        $this->assertSame([], OpenApiContract::get()->problems($operation, $r['status'], $r['type'], $r['body']), "{$operation} {$status} does not match the spec");

        return json_decode($r['body'], true);
    }

    /** A path that matches no route: the generic 404 body (components/responses/RouteNotFound). */
    private function expectRouteNotFound(string $method, string $uri): void
    {
        $r = $this->send($method, $uri, []);
        $this->assertSame(404, $r['status'], "{$method} {$uri}");
        $this->assertSame([], OpenApiContract::get()->validate(json_decode($r['body']), '/components/responses/RouteNotFound/content/application~1json/schema'), "{$method} {$uri}");
        OpenApiContract::$checked['(no route) '.$method.' '.$uri]['404'] = true;
    }

    /* ---------- fixtures ---------- */

    private function file(string $name = 'الصندوق'): UploadedFile
    {
        return $this->trialBalance([['1101', $name, 0, 0, 100, 0, 100, 0], ['1102', 'البنك', 0, 0, 50, 0, 50, 0]]);
    }

    /** A finished run made through the API (the queue is synchronous). */
    private function doneRun(): string
    {
        $run = $this->expect('POST /api/v1/classify/jobs', 202, 'POST', '/api/v1/classify/jobs', null, ['file' => $this->file()]);
        $this->assertSame('done', ClassificationRun::find($run['id'])->status);

        return $run['id'];
    }

    private function waitingRun(): string
    {
        $run = $this->expect('POST /api/v1/classify/jobs', 202, 'POST', '/api/v1/classify/jobs', null, ['file' => $this->file('PAUSE-ME')]);
        $this->assertSame('needs_confirmation', ClassificationRun::find($run['id'])->status);

        return $run['id'];
    }

    private function seededRun(string $status, array $extra = []): string
    {
        $id = (string) Str::uuid();
        ClassificationRun::forceCreate($extra + ['id' => $id, 'status' => $status, 'original_name' => 'x.xlsx']);

        return $id;
    }

    private function importFile(string $company = 'شركة 1'): UploadedFile
    {
        return $this->xlsxUpload([self::IMPORT_HEADER,
            ['1101', 'النقد ومافي حكمه', 'نقدية بالصندوق', 'الصندوق', 0, 0, 0, 0, 10, 0, $company],
            ['1102', 'النقد ومافي حكمه', 'نقدية لدى البنوك', 'البنك', 0, 0, 0, 0, 20, 0, $company],
        ]);
    }

    private function reviewedImport(): int
    {
        $import = $this->expect('POST /api/v1/classification-cache/import', 202, 'POST', '/api/v1/classification-cache/import', null, ['file' => $this->importFile()]);
        $this->assertSame('pending_review', ClassificationImport::find($import['id'])->status);

        return $import['id'];
    }

    /* ---------- classification jobs ---------- */

    public function test_submit(): void
    {
        $this->doneRun();
        $this->expect('POST /api/v1/classify/jobs', 422, 'POST', '/api/v1/classify/jobs', null, [], ['other' => 'x']);
        $this->expect('POST /api/v1/classify/jobs', 422, 'POST', '/api/v1/classify/jobs', null, ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);

        if (! $this->live) {
            // An unexpected failure → the generic JSON 500 (financial_reports references the table: MySQL needs the checks off).
            Schema::withoutForeignKeyConstraints(fn () => Schema::drop('classification_runs'));
            $this->expect('POST /api/v1/classify/jobs', 500, 'POST', '/api/v1/classify/jobs', null, ['file' => $this->file()]);
        }
    }

    public function test_show_every_status(): void
    {
        $op = 'GET /api/v1/classify/jobs/{id}';
        $done = $this->doneRun();
        $this->assertSame('done', $this->expect($op, 200, 'GET', "/api/v1/classify/jobs/{$done}")['status']);
        $this->assertSame('needs_confirmation', $this->expect($op, 200, 'GET', '/api/v1/classify/jobs/'.$this->waitingRun())['status']);
        $failed = $this->expect('POST /api/v1/classify/jobs', 202, 'POST', '/api/v1/classify/jobs', null, ['file' => $this->file('FAIL-AI')])['id'];
        $this->assertSame('failed', $this->expect($op, 200, 'GET', "/api/v1/classify/jobs/{$failed}")['status']);
        foreach (['queued' => [], 'running' => ['stage' => 'classifying', 'progress_done' => 2, 'progress_total' => 5, 'lease' => 'w', 'heartbeat_at' => now()],
            'cancelled' => [], 'expired' => ['error' => 'انتهت مهلة تأكيد أعمدة الملف فتم حذفه. يرجى رفعه مرة أخرى.']] as $status => $extra) {
            $this->assertSame($status, $this->expect($op, 200, 'GET', '/api/v1/classify/jobs/'.$this->seededRun($status, $extra))['status']);
        }
        $this->expect($op, 404, 'GET', '/api/v1/classify/jobs/'.Str::uuid());
    }

    public function test_confirm_structure(): void
    {
        $op = 'POST /api/v1/classify/jobs/{id}/structure';
        $id = $this->waitingRun();
        $good = ['header_rows' => [1], 'first_data_row' => 2, 'columns' => ['account_number' => 'A', 'account_name' => 'B', 'opening_debit' => 'C',
            'opening_credit' => 'D', 'period_debit' => 'E', 'period_credit' => 'F', 'closing_debit' => 'G', 'closing_credit' => 'H']];

        $this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/structure", ['header_rows' => [1]]);                            // required fields
        $this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/structure", ['first_data_row' => 'x'] + $good);                  // wrong type
        $this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/structure", ['columns' => ['not_a_field' => 'A']] + $good);      // unknown field
        $bad = $good; $bad['columns']['closing_debit'] = 'B';
        $this->assertNotEmpty($this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/structure", $bad)['problems']);           // blocking problems
        $swap = $good; [$swap['columns']['closing_debit'], $swap['columns']['closing_credit']] = ['H', 'G'];
        $this->assertTrue($this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/structure", $swap)['needs_acknowledgement']);   // errors to accept
        $this->expect($op, 202, 'POST', "/api/v1/classify/jobs/{$id}/structure", $good + ['extra' => 'ignored']);
        $this->expect($op, 409, 'POST', "/api/v1/classify/jobs/{$id}/structure", $good);                                              // not waiting any more
        $this->expect($op, 404, 'POST', '/api/v1/classify/jobs/'.Str::uuid().'/structure', $good);
    }

    public function test_cancel(): void
    {
        $op = 'POST /api/v1/classify/jobs/{id}/cancel';
        $this->assertSame('cancelled', $this->expect($op, 200, 'POST', '/api/v1/classify/jobs/'.$this->seededRun('queued').'/cancel')['status']);
        $this->assertSame('cancelled', $this->expect($op, 200, 'POST', '/api/v1/classify/jobs/'.$this->waitingRun().'/cancel')['status']);
        $this->expect($op, 409, 'POST', '/api/v1/classify/jobs/'.$this->doneRun().'/cancel');
        $this->expect($op, 404, 'POST', '/api/v1/classify/jobs/'.Str::uuid().'/cancel');
    }

    public function test_correct_one_row(): void
    {
        $op = 'PATCH /api/v1/classify/jobs/{id}/rows/{row}';
        $id = $this->doneRun();
        $this->assertSame('إيجارات', $this->expect($op, 200, 'PATCH', "/api/v1/classify/jobs/{$id}/rows/2", ['sub_class' => 'إيجارات', 'extra' => 1])['row']['sub_class']);
        $this->expect($op, 422, 'PATCH', "/api/v1/classify/jobs/{$id}/rows/2", []);
        $this->expect($op, 422, 'PATCH', "/api/v1/classify/jobs/{$id}/rows/2", ['sub_class' => 'مش موجود']);
        $this->expect($op, 422, 'PATCH', "/api/v1/classify/jobs/{$id}/rows/2", ['sub_class' => 5]);
        $this->expect($op, 404, 'PATCH', "/api/v1/classify/jobs/{$id}/rows/99", ['sub_class' => 'إيجارات']);
        $this->expect($op, 404, 'PATCH', '/api/v1/classify/jobs/'.Str::uuid().'/rows/2', ['sub_class' => 'إيجارات']);
        $this->expect($op, 409, 'PATCH', '/api/v1/classify/jobs/'.$this->seededRun('failed').'/rows/2', ['sub_class' => 'إيجارات']);
        $this->expectRouteNotFound('PATCH', "/api/v1/classify/jobs/{$id}/rows/abc");
    }

    public function test_rows_added_edited_and_removed(): void
    {
        $id = $this->doneRun();
        $op = 'POST /api/v1/classify/jobs/{id}/rows';
        $added = $this->expect($op, 201, 'POST', "/api/v1/classify/jobs/{$id}/rows", ['account_number' => '1199', 'account_name' => 'عهدة جديدة',
            'closing_debit' => 50, 'period_debit' => 50, 'sub_class' => 'عُهد وسُلف العاملين']);
        $this->assertSame([true, 'عُهد وسُلف العاملين', 'manual_correction'], [$added['row']['added'], $added['row']['sub_class'], $added['row']['classification_source']]);
        $before = (float) $added['meta']['balance']['closing'];   // debit − credit, the new 50 debit in it
        $this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/rows", ['closing_debit' => 5]);
        $this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/rows", ['account_name' => 'x', 'closing_debit' => -5]);
        $this->expect($op, 404, 'POST', '/api/v1/classify/jobs/'.Str::uuid().'/rows', ['account_name' => 'x']);
        $this->expect($op, 409, 'POST', '/api/v1/classify/jobs/'.$this->seededRun('queued').'/rows', ['account_name' => 'x']);

        $op = 'PUT /api/v1/classify/jobs/{id}/rows/{row}/values';
        $n = $added['row']['row'];
        $edited = $this->expect($op, 200, 'PUT', "/api/v1/classify/jobs/{$id}/rows/{$n}/values", ['account_name' => 'عهدة محمد', 'closing_debit' => 0, 'period_debit' => 0]);
        $this->assertSame(['عهدة محمد', true, $before - 50], [$edited['row']['account_name'], $edited['row']['edited'], (float) $edited['meta']['balance']['closing']]);
        $this->expect($op, 422, 'PUT', "/api/v1/classify/jobs/{$id}/rows/{$n}/values", ['closing_debit' => 'abc']);
        $this->expect($op, 404, 'PUT', "/api/v1/classify/jobs/{$id}/rows/999/values", ['account_name' => 'x']);
        $this->expect($op, 409, 'PUT', '/api/v1/classify/jobs/'.$this->seededRun('queued').'/rows/2/values', ['account_name' => 'x']);

        $op = 'DELETE /api/v1/classify/jobs/{id}/rows/{row}';
        $this->expect($op, 200, 'DELETE', "/api/v1/classify/jobs/{$id}/rows/{$n}");
        $this->expect($op, 404, 'DELETE', "/api/v1/classify/jobs/{$id}/rows/{$n}");
        $this->expect($op, 409, 'DELETE', '/api/v1/classify/jobs/'.$this->seededRun('queued').'/rows/2');
        $job = $this->expect('GET /api/v1/classify/jobs/{id}', 200, 'GET', "/api/v1/classify/jobs/{$id}");
        $this->assertSame([['row' => $n, 'account_number' => '1199', 'account_name' => 'عهدة محمد']], $job['result']['meta']['removed_rows']);
    }

    public function test_correct_many_rows(): void
    {
        $op = 'POST /api/v1/classify/jobs/{id}/corrections';
        $id = $this->doneRun();
        $out = $this->expect($op, 200, 'POST', "/api/v1/classify/jobs/{$id}/corrections", ['corrections' => [
            ['row' => 2, 'sub_class' => 'إيجارات', 'extra' => 'ignored'], ['row' => 3, 'sub_class' => 'نقدية لدى البنوك'],
        ]]);
        $this->assertSame([2, 2], [$out['corrected'], $out['remembered']]);
        $this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/corrections", []);
        $this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/corrections", ['corrections' => []]);
        $this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/corrections", ['corrections' => [['row' => 'abc', 'sub_class' => 'إيجارات']]]);
        $this->expect($op, 422, 'POST', "/api/v1/classify/jobs/{$id}/corrections", ['corrections' => [['row' => 2, 'sub_class' => 'x'], ['row' => 2, 'sub_class' => 'x']]]);
        $this->assertSame([99], $this->expect($op, 404, 'POST', "/api/v1/classify/jobs/{$id}/corrections", ['corrections' => [['row' => 99, 'sub_class' => 'إيجارات']]])['rows']);
        $this->expect($op, 404, 'POST', '/api/v1/classify/jobs/'.Str::uuid().'/corrections', ['corrections' => [['row' => 2, 'sub_class' => 'إيجارات']]]);
        $this->expect($op, 409, 'POST', '/api/v1/classify/jobs/'.$this->seededRun('queued').'/corrections', ['corrections' => [['row' => 2, 'sub_class' => 'إيجارات']]]);
    }

    public function test_sync_classification(): void
    {
        $op = 'POST /api/v1/classify/trial-balance';
        $this->assertNotEmpty($this->expect($op, 200, 'POST', '/api/v1/classify/trial-balance', null, ['file' => $this->file()])['data']);
        $this->expect($op, 422, 'POST', '/api/v1/classify/trial-balance', null, [], ['x' => 1]);                                           // no file
        $this->assertArrayHasKey('problems', $this->expect($op, 422, 'POST', '/api/v1/classify/trial-balance', null, ['file' => $this->file('PAUSE-ME')]));
        $this->expect($op, 502, 'POST', '/api/v1/classify/trial-balance', null, ['file' => $this->file('FAIL-AI')]);

    }

    /** Kernel only: an unexpected error in the service → the generic JSON 500 (first request of its test: routes cache the controller). */
    public function test_sync_classification_unexpected_error(): void
    {
        if ($this->live) {
            $this->markTestSkipped('A live server cannot be made to crash.');
        }
        $this->mock(ClassificationService::class)->shouldReceive('classifyFile')->andThrow(new \Error('boom'));
        $this->expect('POST /api/v1/classify/trial-balance', 500, 'POST', '/api/v1/classify/trial-balance', null, ['file' => $this->file()]);
    }

    /* ---------- classification cache ---------- */

    public function test_cache_explain_entries_and_delete(): void
    {
        $this->doneRun();   // AI answers at 95 are stored
        $this->expect('GET /api/v1/classification-cache/explain', 200, 'GET', '/api/v1/classification-cache/explain');

        $op = 'GET /api/v1/classification-cache/entries';
        $page = $this->expect($op, 200, 'GET', '/api/v1/classification-cache/entries?per_page=1&source=ai&q=1101');
        $this->assertSame(1, $page['total']);
        $this->expect($op, 200, 'GET', '/api/v1/classification-cache/entries');
        $this->expect($op, 422, 'GET', '/api/v1/classification-cache/entries?per_page=0');
        $this->expect($op, 422, 'GET', '/api/v1/classification-cache/entries?source=other');
        $this->expect($op, 422, 'GET', '/api/v1/classification-cache/entries?per_page=abc');

        $id = $page['data'][0]['id'];
        $op = 'POST /api/v1/classification-cache/entries';
        $this->expect($op, 201, 'POST', '/api/v1/classification-cache/entries', ['account_code' => '2105', 'account_name' => 'ايجارات مستحقة', 'sub_class' => 'مصروفات مستحقة']);
        $this->expect($op, 422, 'POST', '/api/v1/classification-cache/entries', ['account_name' => 'ايجارات مستحقة', 'sub_class' => 'غير موجود']);
        $op = 'POST /api/v1/classification-cache/entries/reclassify';
        $this->expect($op, 200, 'POST', '/api/v1/classification-cache/entries/reclassify', ['ids' => [$id], 'sub_class' => 'نقدية بالصندوق']);
        $this->expect($op, 404, 'POST', '/api/v1/classification-cache/entries/reclassify', ['ids' => [999999], 'sub_class' => 'نقدية بالصندوق']);
        $this->expect($op, 422, 'POST', '/api/v1/classification-cache/entries/reclassify', ['ids' => [$id], 'sub_class' => 'غير موجود']);
        $op = 'POST /api/v1/classification-cache/entries/delete';
        $this->expect($op, 422, 'POST', '/api/v1/classification-cache/entries/delete', ['ids' => []]);
        $this->expect($op, 200, 'POST', '/api/v1/classification-cache/entries/delete', ['ids' => [999999]]);

        $op = 'DELETE /api/v1/classification-cache/entries/{id}';
        $this->expect($op, 204, 'DELETE', '/api/v1/classification-cache/entries/'.$page['data'][0]['id']);
        $this->expect($op, 404, 'DELETE', '/api/v1/classification-cache/entries/'.$page['data'][0]['id']);
        $this->expectRouteNotFound('DELETE', '/api/v1/classification-cache/entries/abc');
    }

    /* ---------- accountant imports ---------- */

    public function test_import_upload_list_and_show(): void
    {
        $op = 'POST /api/v1/classification-cache/import';
        $id = $this->reviewedImport();
        $dup = $this->expect($op, 409, 'POST', '/api/v1/classification-cache/import', null, ['file' => $this->importFile()]);
        $this->assertSame($id, $dup['duplicate_of']['id']);
        $this->expect($op, 202, 'POST', '/api/v1/classification-cache/import', null, ['file' => $this->importFile()], ['force' => '1']);
        $this->expect($op, 422, 'POST', '/api/v1/classification-cache/import', null, ['file' => $this->xlsxUpload([['اسم الحساب', 'رصيد'], ['الصندوق', 1]])]);
        $this->expect($op, 422, 'POST', '/api/v1/classification-cache/import', null, [], ['x' => 1]);

        $this->expect('GET /api/v1/classification-cache/imports', 200, 'GET', '/api/v1/classification-cache/imports');

        $op = 'GET /api/v1/classification-cache/imports/{id}';
        $this->assertSame('pending_review', $this->expect($op, 200, 'GET', "/api/v1/classification-cache/imports/{$id}")['status']);
        $queued = ClassificationImport::create(['file_name' => 'q.xlsx', 'status' => ClassificationImport::QUEUED]);
        $this->assertNull($this->expect($op, 200, 'GET', "/api/v1/classification-cache/imports/{$queued->id}")['report']);
        $this->expect($op, 404, 'GET', '/api/v1/classification-cache/imports/999999');
        $this->expectRouteNotFound('GET', '/api/v1/classification-cache/imports/abc');
    }

    public function test_import_mappings_review_approve_apply(): void
    {
        $id = $this->reviewedImport();
        $op = 'GET /api/v1/classification-cache/imports/{id}/mappings';
        $list = $this->expect($op, 200, 'GET', "/api/v1/classification-cache/imports/{$id}/mappings?kind=note&per_page=1");
        $this->assertSame(1, $list['per_page']);
        $this->expect($op, 200, 'GET', "/api/v1/classification-cache/imports/{$id}/mappings?flagged=1&unreviewed=1&low=1&q=x&decision=map");
        $this->expect($op, 422, 'GET', "/api/v1/classification-cache/imports/{$id}/mappings?kind=other");
        $this->expect($op, 422, 'GET', "/api/v1/classification-cache/imports/{$id}/mappings?per_page=501");
        $this->expect($op, 404, 'GET', '/api/v1/classification-cache/imports/999999/mappings');
        $this->expectRouteNotFound('GET', '/api/v1/classification-cache/imports/abc/mappings');

        $mapping = $list['data'][0]['id'];
        $running = ClassificationImport::create(['file_name' => 'r.xlsx', 'status' => ClassificationImport::RUNNING]);
        $runningMapping = ClassificationImportMapping::create(['import_id' => $running->id, 'kind' => 'note', 'value' => 'x', 'normalized' => 'x',
            'parent' => 'النقد ومافي حكمه', 'decision' => 'map', 'sub_class' => 'نقدية بالصندوق', 'method' => 'llm', 'confidence' => 50, 'rows' => 1]);

        $op = 'PATCH /api/v1/classification-cache/imports/mappings/{mappingId}';
        $this->expect($op, 200, 'PATCH', "/api/v1/classification-cache/imports/mappings/{$mapping}", []);
        $this->expect($op, 200, 'PATCH', "/api/v1/classification-cache/imports/mappings/{$mapping}", ['decision' => 'map', 'sub_class' => 'نقدية لدى البنوك']);
        $this->expect($op, 422, 'PATCH', "/api/v1/classification-cache/imports/mappings/{$mapping}", ['decision' => 'route']);              // budget only
        $this->expect($op, 422, 'PATCH', "/api/v1/classification-cache/imports/mappings/{$mapping}", ['decision' => 'map', 'sub_class' => 'x']);
        $this->expect($op, 422, 'PATCH', "/api/v1/classification-cache/imports/mappings/{$mapping}", ['decision' => 'other']);
        $this->expect($op, 404, 'PATCH', '/api/v1/classification-cache/imports/mappings/999999', []);
        $this->expect($op, 409, 'PATCH', "/api/v1/classification-cache/imports/mappings/{$runningMapping->id}", []);
        $this->expectRouteNotFound('PATCH', '/api/v1/classification-cache/imports/mappings/abc');

        $op = 'POST /api/v1/classification-cache/imports/{id}/mappings/approve';
        $this->expect($op, 200, 'POST', "/api/v1/classification-cache/imports/{$id}/mappings/approve", ['ids' => [$mapping]]);
        $this->expect($op, 200, 'POST', "/api/v1/classification-cache/imports/{$id}/mappings/approve", ['all' => true, 'kind' => 'note', 'flagged' => true]);
        $this->expect($op, 422, 'POST', "/api/v1/classification-cache/imports/{$id}/mappings/approve", []);
        $this->expect($op, 422, 'POST', "/api/v1/classification-cache/imports/{$id}/mappings/approve", ['ids' => []]);
        $this->expect($op, 422, 'POST', "/api/v1/classification-cache/imports/{$id}/mappings/approve", ['ids' => 'x']);
        $this->expect($op, 422, 'POST', "/api/v1/classification-cache/imports/{$id}/mappings/approve", ['all' => 'maybe']);
        $this->expect($op, 404, 'POST', '/api/v1/classification-cache/imports/999999/mappings/approve', ['ids' => [1]]);
        $this->expect($op, 409, 'POST', "/api/v1/classification-cache/imports/{$running->id}/mappings/approve", ['ids' => [1]]);

        $op = 'POST /api/v1/classification-cache/imports/{id}/apply';
        $this->expect($op, 200, 'POST', "/api/v1/classification-cache/imports/{$id}/apply");
        $this->expect($op, 404, 'POST', '/api/v1/classification-cache/imports/999999/apply');
        $this->expect($op, 409, 'POST', "/api/v1/classification-cache/imports/{$running->id}/apply");
        $this->expectRouteNotFound('POST', '/api/v1/classification-cache/imports/abc/apply');
    }

    /* ---------- export and transfer ---------- */

    public function test_exports_and_import_export(): void
    {
        $this->doneRun();
        $this->reviewedImport();
        foreach (['xlsx', 'json'] as $ext) {
            $op = "GET /api/v1/classification-cache/export.{$ext}";
            $this->expect($op, 200, 'GET', "/api/v1/classification-cache/export.{$ext}");
            $this->expect($op, 200, 'GET', "/api/v1/classification-cache/export.{$ext}?source=ai&from=2020-01-01&to=2100-01-01");
            $this->expect($op, 422, 'GET', "/api/v1/classification-cache/export.{$ext}?from=2026-13-01");
            $this->expect($op, 422, 'GET', "/api/v1/classification-cache/export.{$ext}?source=other");
        }

        $json = $this->send('GET', '/api/v1/classification-cache/export.json')['body'];
        $path = tempnam(sys_get_temp_dir(), 'exp').'.json';
        file_put_contents($path, $json);
        $op = 'POST /api/v1/classification-cache/import-export';
        $report = $this->expect($op, 200, 'POST', '/api/v1/classification-cache/import-export', null, ['file' => new UploadedFile($path, 'export.json', 'application/json', null, true)]);

        // The import it creates (kind export) has its own report shape.
        $this->expect('GET /api/v1/classification-cache/imports/{id}', 200, 'GET', "/api/v1/classification-cache/imports/{$report['import_id']}");

        file_put_contents($path, '{"format": "something-else"}');
        $this->expect($op, 422, 'POST', '/api/v1/classification-cache/import-export', null, ['file' => new UploadedFile($path, 'x.json', 'application/json', null, true)]);
        $this->expect($op, 422, 'POST', '/api/v1/classification-cache/import-export', null, [], ['x' => 1]);
    }

    /* ---------- companies and financial reports ---------- */

    public function test_companies(): void
    {
        $op = 'POST /api/v1/companies';
        $company = $this->expect($op, 201, 'POST', '/api/v1/companies', ['name_ar' => 'شركة المثال', 'branches' => ['جدة'], 'cr_date' => '2019-01-17']);
        $this->expect($op, 422, 'POST', '/api/v1/companies', ['currency' => 'USD']);

        $op = 'GET /api/v1/companies';
        $this->assertSame(1, $this->expect($op, 200, 'GET', '/api/v1/companies?search='.urlencode('المثال').'&per_page=5')['total']);
        $this->expect($op, 422, 'GET', '/api/v1/companies?per_page=0');

        $op = 'GET /api/v1/companies/{id}';
        $this->expect($op, 200, 'GET', "/api/v1/companies/{$company['id']}");
        $this->expect($op, 404, 'GET', '/api/v1/companies/'.Str::uuid());

        $op = 'PATCH /api/v1/companies/{id}';
        $this->expect($op, 200, 'PATCH', "/api/v1/companies/{$company['id']}", ['name_en' => 'Example Co', 'fiscal_year_end_month' => 6, 'fiscal_year_end_day' => 30]);
        $this->expect($op, 422, 'PATCH', "/api/v1/companies/{$company['id']}", ['fiscal_year_end_month' => 2]);
        $this->expect($op, 404, 'PATCH', '/api/v1/companies/'.Str::uuid(), []);

        // The commercial registration: the AI copies, PHP places the legal form (a sole proprietorship here, with its owner).
        $op = 'POST /api/v1/companies/{id}/registration';
        $cr = new UploadedFile($this->pdfFile(['<h1>سجل تجاري</h1>']), 'سجل.pdf', 'application/pdf', null, true);
        $read = $this->expect($op, 200, 'POST', "/api/v1/companies/{$company['id']}/registration", null, ['file' => $cr]);
        $this->assertSame(['sole_proprietorship', true, false], [$read['legal_form'], $read['legal_form_known'], $read['articles_required']]);
        $this->expect($op, 422, 'POST', "/api/v1/companies/{$company['id']}/registration", null, ['file' => UploadedFile::fake()->create('a.txt', 1, 'text/plain')]);
        $this->expect($op, 404, 'POST', '/api/v1/companies/'.Str::uuid().'/registration', null, ['file' => $cr]);
        $this->expect('PATCH /api/v1/companies/{id}', 200, 'PATCH', "/api/v1/companies/{$company['id']}", ['legal_form' => 'llc']);

        // The articles of association: read by the AI (only copying), grouped and shared out by PHP.
        $op = 'POST /api/v1/companies/{id}/articles';
        $pdf = new UploadedFile($this->pdfFile(['<h1>عقد تأسيس</h1>']), 'عقد.pdf', 'application/pdf', null, true);
        $read = $this->expect($op, 200, 'POST', "/api/v1/companies/{$company['id']}/articles", null, ['file' => $pdf]);
        $this->assertSame([60, 40, 60], [$read['articles']['partners'][0]['ownership'], $read['articles']['partners'][1]['ownership'], $read['articles']['zakat_share']]);
        $this->assertSame(['saudi', 'foreign'], array_column($read['articles']['partners'], 'group'));
        $this->expect($op, 422, 'POST', "/api/v1/companies/{$company['id']}/articles", null, ['file' => UploadedFile::fake()->create('a.txt', 1, 'text/plain')]);
        $this->expect($op, 404, 'POST', '/api/v1/companies/'.Str::uuid().'/articles', null, ['file' => $pdf]);

        $op = 'DELETE /api/v1/companies/{id}/articles';
        $this->assertNull($this->expect($op, 200, 'DELETE', "/api/v1/companies/{$company['id']}/articles")['articles']);
        $this->expect($op, 404, 'DELETE', '/api/v1/companies/'.Str::uuid().'/articles');
    }

    public function test_financial_reports(): void
    {
        $company = $this->expect('POST /api/v1/companies', 201, 'POST', '/api/v1/companies', ['name_ar' => 'شركة المثال']);
        $run     = $this->doneRun();

        $op = 'POST /api/v1/financial-reports';
        $body = ['classification_run_id' => $run, 'company_id' => $company['id'], 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'];
        $report = $this->expect($op, 201, 'POST', '/api/v1/financial-reports', $body);
        $this->expect($op, 201, 'POST', '/api/v1/financial-reports', ['company_id' => null] + $body);
        $this->expect($op, 409, 'POST', '/api/v1/financial-reports', ['classification_run_id' => $this->waitingRun()] + $body);
        $this->expect($op, 422, 'POST', '/api/v1/financial-reports', ['period_end' => '2024-12-31'] + $body);
        $this->expect($op, 422, 'POST', '/api/v1/financial-reports', ['classification_run_id' => (string) Str::uuid()] + $body);

        $op = 'GET /api/v1/financial-reports';
        $this->assertSame(1, $this->expect($op, 200, 'GET', "/api/v1/financial-reports?company_id={$company['id']}&classification_run_id={$run}")['total']);
        $this->expect($op, 422, 'GET', '/api/v1/financial-reports?company_id=x');

        $op = 'GET /api/v1/financial-reports/{id}';
        $this->assertFalse($this->expect($op, 200, 'GET', $report['status_url'])['run_changed']);
        $this->expect($op, 404, 'GET', '/api/v1/financial-reports/'.Str::uuid());

        $op = 'PATCH /api/v1/financial-reports/{id}';
        $this->assertSame('2025-02-01', $this->expect($op, 200, 'PATCH', $report['status_url'], ['period_start' => '2025-02-01'])['period_start']);
        $this->expect($op, 200, 'PATCH', $report['status_url'], ['period_start' => '2025-01-01', 'company_id' => $company['id']]);
        $this->expect($op, 422, 'PATCH', $report['status_url'], ['period_end' => '2024-06-30']);
        $this->expect($op, 404, 'PATCH', '/api/v1/financial-reports/'.Str::uuid(), ['period_start' => '2025-01-01']);

        $op = 'GET /api/v1/classify/jobs';
        $this->assertContains($run, array_column($this->expect($op, 200, 'GET', '/api/v1/classify/jobs?status=done')['data'], 'id'));
        $this->expect($op, 422, 'GET', '/api/v1/classify/jobs?status=nope');
        $id   = $report['id'];
        $base = "/api/v1/financial-reports/{$id}";
        $row  = ClassificationRun::find($run)->result['data'][0]['row'];

        $op = 'GET /api/v1/financial-reports/{id}/intake';
        $this->assertContains('oci.has_items', array_column($this->expect($op, 200, 'GET', "{$base}/intake")['fields'], 'key'));
        $this->expect($op, 404, 'GET', '/api/v1/financial-reports/'.Str::uuid().'/intake');

        $op = 'PATCH /api/v1/financial-reports/{id}/inputs';
        $state = $this->expect($op, 200, 'PATCH', "{$base}/inputs", ['inputs' => ['oci.has_items' => true, 'oci.revaluation' => 1500]]);
        $this->assertNotContains('is.oci_total', array_column($state['missing'], 'key'));
        $this->expect($op, 422, 'PATCH', "{$base}/inputs", ['inputs' => ['nope' => 1]]);
        $this->expect($op, 422, 'PATCH', "{$base}/inputs", []);
        $this->expect($op, 404, 'PATCH', '/api/v1/financial-reports/'.Str::uuid().'/inputs', ['inputs' => []]);

        $op = 'PUT /api/v1/financial-reports/{id}/account-overrides';
        $this->expect($op, 200, 'PUT', "{$base}/account-overrides", ['overrides' => [['row' => $row, 'shares' => ['sofp.cash' => 0.5, 'sofp.murabaha_deposits' => 0.5]]]]);
        $this->expect($op, 422, 'PUT', "{$base}/account-overrides", ['overrides' => [['row' => $row, 'line_key' => 'sofp.nope']]]);
        $excluded = $this->expect($op, 200, 'PUT', "{$base}/account-overrides", ['overrides' => [['row' => $row, 'exclude' => true, 'reason' => 'حساب تجميعي']]]);
        $this->assertSame('user', collect($excluded['mapping']['excluded'])->firstWhere('row', $row)['reason']);
        $this->expect($op, 422, 'PUT', "{$base}/account-overrides", ['overrides' => [['row' => $row, 'exclude' => true]]]);
        $this->expect($op, 404, 'PUT', '/api/v1/financial-reports/'.Str::uuid().'/account-overrides', ['overrides' => []]);

        $op = 'PUT /api/v1/financial-reports/{id}/adjustments';
        $entry = ['reason' => 'إعادة تبويب', 'lines' => [['row' => $row, 'debit' => 10, 'credit' => 0], ['line_key' => 'sofp.cash', 'debit' => 0, 'credit' => 10]]];
        $state = $this->expect($op, 200, 'PUT', "{$base}/adjustments", ['entries' => [$entry]]);
        $this->assertSame(1, $state['adjustments'][0]['number']);
        $entry['lines'][1]['credit'] = 9;
        $this->expect($op, 422, 'PUT', "{$base}/adjustments", ['entries' => [$entry]]);
        $this->expect($op, 404, 'PUT', '/api/v1/financial-reports/'.Str::uuid().'/adjustments', ['entries' => []]);

        $op = 'PUT /api/v1/financial-reports/{id}/prior-year';
        $state = $this->expect($op, 200, 'PUT', "{$base}/prior-year", ['source' => 'manual', 'figures' => ['sofp.cash' => 80, 'sofp.share_capital' => 80],
            'restated' => true, 'restatement_note' => 'تصحيح خطأ في أرقام السنة السابقة']);
        $this->assertSame(['manual', true], [$state['comparative']['source'], $state['comparative']['restated']]);
        $this->assertSame('first_year', $this->expect($op, 200, 'PUT', "{$base}/prior-year", ['source' => 'first_year'])['comparative']['source']);
        $this->expect($op, 422, 'PUT', "{$base}/prior-year", ['source' => 'report']);
        $this->expect($op, 422, 'PUT', "{$base}/prior-year", ['source' => 'manual', 'figures' => ['sofp.nope' => 1]]);
        $this->expect($op, 404, 'PUT', '/api/v1/financial-reports/'.Str::uuid().'/prior-year', ['source' => 'none']);

        // The zakat and income tax dialog: the answers, a charge taken in and out, and what is refused.
        $op = 'PUT /api/v1/financial-reports/{id}/zakat-tax';
        $state = $this->expect($op, 200, 'PUT', "{$base}/zakat-tax", ['inputs' => ['saudi_share' => 50]]);
        $this->assertEquals(50, $state['zakat_tax']['inputs']['saudi_share']);
        // Charged where nothing else charges it (200), refused where something does or the amount is nil (422).
        $this->expect($op, $state['zakat_tax']['can_apply']['zakat'] ? 200 : 422, 'PUT', "{$base}/zakat-tax", ['apply' => ['zakat' => true]]);
        $this->expect($op, 200, 'PUT', "{$base}/zakat-tax", ['apply' => ['zakat' => false, 'income_tax' => false]]);
        $this->expect($op, 422, 'PUT', "{$base}/zakat-tax", ['inputs' => ['saudi_share' => 101]]);
        $this->expect($op, 404, 'PUT', '/api/v1/financial-reports/'.Str::uuid().'/zakat-tax', ['inputs' => []]);
        $this->expect($op, 200, 'PUT', "{$base}/zakat-tax", ['inputs' => ['saudi_share' => null]]);

        // Last year's issued statements as Excel (PR 17): read for review, then confirmed as the `statements` source.
        $upload = 'POST /api/v1/financial-reports/{id}/prior-year/statements-file';
        $file = $this->workbook(['تقرير المراجع المستقل' => [['تقرير المراجع المستقل']], 'المركز المالي' => [['قائمة المركز المالي'], ['', '2024'], ['النقد ومعادلات النقد', 80], ['رأس المال', 80]]], 'قوائم 2024.xlsx');
        $withoutReport = $this->workbook(['المركز المالي' => [['قائمة المركز المالي'], ['', '2024'], ['النقد ومعادلات النقد', 80], ['رأس المال', 80]]], 'قوائم 2024.xlsx');
        $this->expect($upload, 422, 'POST', "{$base}/prior-year/statements-file", null, ['file' => $withoutReport]);   // no auditor's report
        $read = $this->expect($upload, 200, 'POST', "{$base}/prior-year/statements-file", null, ['file' => $file])['comparative']['upload'];
        $this->assertSame([false, 'financial_position'], [$read['in_use'], $read['blocks'][0]['statement']]);
        $this->expect($upload, 422, 'POST', "{$base}/prior-year/statements-file", null, ['file' => $this->xlsxUpload([['حساب', 'رصيد'], ['الصندوق', 1]])]);
        $this->expect($upload, 404, 'POST', '/api/v1/financial-reports/'.Str::uuid().'/prior-year/statements-file', null, ['file' => $this->xlsxUpload([['x']])]);
        if (! $this->live) {   // a body over post_max_size: the kernel sees only its declared length
            $r = $this->call('POST', "{$base}/prior-year/statements-file", [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => 1024 ** 3]);
            $this->assertSame(413, $r->getStatusCode());
            $this->assertSame([], OpenApiContract::get()->problems($upload, 413, $r->headers->get('Content-Type'), (string) $r->getContent()));
        }
        $state = $this->expect($op, 200, 'PUT', "{$base}/prior-year", ['source' => 'statements', 'columns' => [0 => 'B']]);
        $this->assertSame(['statements', true], [$state['comparative']['source'], $state['comparative']['upload']['in_use']]);
        // Step B's setting: this year's openings from last year's approved statements, or the trial balance's.
        $approved = 'PUT /api/v1/financial-reports/{id}/prior-year/approved-opening';
        $this->assertFalse($this->expect($approved, 200, 'PUT', "{$base}/prior-year/approved-opening", ['enabled' => false])['comparative']['approved_opening']['enabled']);
        $this->expect($approved, 200, 'PUT', "{$base}/prior-year/approved-opening", ['enabled' => true]);
        $this->expect($approved, 422, 'PUT', "{$base}/prior-year/approved-opening", ['enabled' => 'sometimes']);
        $this->expect($approved, 404, 'PUT', '/api/v1/financial-reports/'.Str::uuid().'/prior-year/approved-opening', ['enabled' => true]);

        // Last year's statements as a PDF: its pages found by the AI (202), confirmed and read (202), the file shown.
        $pages = 'PUT /api/v1/financial-reports/{id}/prior-year/statements-file/pages';
        $this->expect($pages, 409, 'PUT', "{$base}/prior-year/statements-file/pages", ['pages' => ['financial_position' => ['from' => 1, 'to' => 1]]]);
        $pdf = new UploadedFile($this->pdfFile(['<h1>تقرير المراجع المستقل</h1>', '<h1>قائمة المركز المالي</h1>']), 'قوائم 2024.pdf', 'application/pdf', null, true);
        $found = $this->expect($upload, 202, 'POST', "{$base}/prior-year/statements-file", null, ['file' => $pdf])['comparative']['upload'];
        $this->assertSame(['pdf', 'pages'], [$found['kind'], $found['status']]);
        $wording = 'PUT /api/v1/financial-reports/{id}/prior-year/statements-file/wording';
        $this->expect($wording, 409, 'PUT', "{$base}/prior-year/statements-file/wording", ['known_words' => ['كلمة']]);
        $totals = 'PUT /api/v1/financial-reports/{id}/prior-year/statements-file/totals';
        $this->expect($totals, 409, 'PUT', "{$base}/prior-year/statements-file/totals", ['use' => ['t1' => 'sum']]);
        $show = 'GET /api/v1/financial-reports/{id}/prior-year/statements-file';
        $this->expect($show, 200, 'GET', "{$base}/prior-year/statements-file");
        $this->expect($show, 404, 'GET', '/api/v1/financial-reports/'.Str::uuid().'/prior-year/statements-file');
        $this->expect($pages, 422, 'PUT', "{$base}/prior-year/statements-file/pages", ['pages' => ['financial_position' => ['from' => 2, 'to' => 3]]]);
        $this->expect($pages, 404, 'PUT', '/api/v1/financial-reports/'.Str::uuid().'/prior-year/statements-file/pages', ['pages' => []]);
        $read = $this->expect($pages, 202, 'PUT', "{$base}/prior-year/statements-file/pages", ['pages' => ['financial_position' => ['from' => 2, 'to' => 2]]])['comparative']['upload'];
        $this->assertSame(['ready', 2], [$read['status'], $read['blocks'][0]['rows'][0]['page']]);
        $this->expect($wording, 200, 'PUT', "{$base}/prior-year/statements-file/wording", ['known_words' => ['كلمة']]);
        $this->expect($wording, 422, 'PUT', "{$base}/prior-year/statements-file/wording", ['keep_original' => ['nope' => true]]);
        $this->expect($wording, 404, 'PUT', '/api/v1/financial-reports/'.Str::uuid().'/prior-year/statements-file/wording', ['known_words' => ['كلمة']]);
        $this->expect($totals, 422, 'PUT', "{$base}/prior-year/statements-file/totals", ['use' => ['nope' => 'sum']]);
        $this->expect($totals, 404, 'PUT', '/api/v1/financial-reports/'.Str::uuid().'/prior-year/statements-file/totals', ['use' => ['t1' => 'sum']]);
        // A total differing from its columns' sum, as a reading would leave it, chosen.
        $report = \App\Models\FinancialReport::find(basename($base));
        $upload = $report->prior_upload;
        $upload['document']['totals'] = [['id' => 't1', 'table' => 0, 'row' => 0, 'page' => 1, 'title' => 'الرصيد', 'printed' => 11.0, 'sum' => 10.0, 'statement' => null, 'ties' => 'choose']];
        $upload['document']['flags'][] = ['code' => 'total_choice', 'page' => 1, 'table' => 0, 'title' => 'الرصيد', 'total' => 't1'];
        $report->update(['prior_upload' => $upload]);
        $chosen = $this->expect($totals, 200, 'PUT', "{$base}/prior-year/statements-file/totals", ['use' => ['t1' => 'printed']])['comparative']['upload']['issues'];
        $this->assertSame('printed', collect($chosen)->firstWhere('code', 'total_choice')['total']['used']);

        // The synonyms of the lines (PR 17).
        $op = 'GET /api/v1/statement-synonyms';
        $this->assertNotEmpty($this->expect($op, 200, 'GET', '/api/v1/statement-synonyms')['data']);
        $op = 'POST /api/v1/statement-synonyms';
        $synonym = $this->expect($op, 201, 'POST', '/api/v1/statement-synonyms', ['line_key' => 'sofp.inventory', 'label' => 'بضاعة بالمخازن'])['id'];
        $this->expect($op, 422, 'POST', '/api/v1/statement-synonyms', ['line_key' => 'sofp.inventory', 'label' => 'المخزون']);
        $op = 'PUT /api/v1/statement-synonyms/{id}';
        $this->expect($op, 200, 'PUT', "/api/v1/statement-synonyms/{$synonym}", ['line_key' => 'sofp.inventory', 'label' => 'بضاعة في المخازن']);
        $this->expect($op, 422, 'PUT', "/api/v1/statement-synonyms/{$synonym}", ['line_key' => 'sofp.nope', 'label' => 'بضاعة']);
        $this->expect($op, 404, 'PUT', '/api/v1/statement-synonyms/999999', ['line_key' => 'sofp.inventory', 'label' => 'بضاعة']);
        $op = 'DELETE /api/v1/statement-synonyms/{id}';
        $this->expect($op, 204, 'DELETE', "/api/v1/statement-synonyms/{$synonym}");
        $this->expect($op, 404, 'DELETE', "/api/v1/statement-synonyms/{$synonym}");

        // Movement schedules: a run with movement columns, PPE, accumulated depreciation and end-of-service benefits.
        $movements = ClassificationRun::forceCreate(['id' => (string) Str::uuid(), 'status' => 'done', 'original_name' => 'tb.xlsx', 'result' => $this->runResult([
            $this->account(2, 'سيارات ووسائل نقل', ['opening' => 100, 'period' => [50, 0]]),
            $this->account(3, 'مجمع إهلاك الأصول الثابتة', ['opening' => -20, 'period' => [0, 10]]),
            $this->account(4, 'مخصص نهاية الخدمة', ['opening' => -10, 'period' => [3, 8]]),
            $this->account(5, 'نقدية لدى البنوك', ['opening' => 30, 'period' => [0, 45]]),
            $this->account(6, 'رأس المال', ['opening' => -100]),
            $this->account(7, 'إهلاك السيارات', ['period' => [10, 0]]),
            $this->account(8, 'مصروف مكافأة نهاية الخدمة', ['period' => [8, 0]]),
        ])])->id;
        $base  = '/api/v1/financial-reports/'.$this->expect('POST /api/v1/financial-reports', 201, 'POST', '/api/v1/financial-reports',
            ['classification_run_id' => $movements] + $body)['id'];
        $this->expect('PUT /api/v1/financial-reports/{id}/account-overrides', 200, 'PUT', "{$base}/account-overrides", ['overrides' => [['row' => 3, 'category' => 'vehicles']]]);

        $op = 'POST /api/v1/financial-reports/{id}/suggestions';
        $state = $this->expect($op, 200, 'POST', "{$base}/suggestions", ['decisions' => [['key' => 'ppe.additions.vehicles', 'action' => 'confirm'],
            ['key' => 'employee_benefits.paid', 'action' => 'edit', 'amount' => 3]]]);
        $this->assertSame(['confirmed', 'edited'], [collect($state['suggestions'])->firstWhere('key', 'ppe.additions.vehicles')['status'],
            collect($state['suggestions'])->firstWhere('key', 'employee_benefits.paid')['status']]);
        $this->expect($op, 422, 'POST', "{$base}/suggestions", ['decisions' => [['key' => 'ppe.nope.total', 'action' => 'confirm']]]);
        $this->expect($op, 404, 'POST', '/api/v1/financial-reports/'.Str::uuid().'/suggestions', ['decisions' => [['key' => 'x', 'action' => 'reset']]]);

        $op = 'PATCH /api/v1/financial-reports/{id}/notes';
        $state = $this->expect($op, 200, 'PATCH', "{$base}/notes", ['visibility' => ['investment_property' => 'on'], 'texts' => ['incorporation.4' => 'المركز الرئيسي للشركة في مدينة الاختبار.']]);
        $this->assertSame('forced_on', collect($state['notes'])->firstWhere('key', 'investment_property')['visibility']);
        $this->assertTrue(collect(collect($state['notes'])->firstWhere('key', 'incorporation')['blocks'])->firstWhere('key', 'incorporation.4')['edited']);
        $this->expect($op, 422, 'PATCH', "{$base}/notes", ['visibility' => ['capital' => 'off']]);
        $this->expect($op, 404, 'PATCH', '/api/v1/financial-reports/'.Str::uuid().'/notes', ['visibility' => ['ppe' => 'on']]);

        $op = 'PUT /api/v1/financial-reports/{id}/acknowledgements';
        $warning = collect($state['checks'])->firstWhere('severity', 'warning');
        $acks = $warning ? [['key' => $warning['key'], 'note' => 'تمت المراجعة.']] : [];
        $state = $this->expect($op, 200, 'PUT', "{$base}/acknowledgements", ['acknowledgements' => $acks]);
        $this->assertCount(count($acks), $state['acknowledgements']);
        $this->expect($op, 422, 'PUT', "{$base}/acknowledgements", ['acknowledgements' => [['key' => 'nope:0']]]);
        $this->expect($op, 404, 'PUT', '/api/v1/financial-reports/'.Str::uuid().'/acknowledgements', ['acknowledgements' => []]);

        $op = 'POST /api/v1/financial-reports/{id}/versions';
        $this->assertFalse($state['can_finalize']);
        $blocked = $this->expect($op, 422, 'POST', "{$base}/versions", ['kind' => 'final']);
        $this->assertNotEmpty($blocked['blocking']);
        $this->expect($op, 422, 'POST', "{$base}/versions", ['kind' => 'other']);
        $version = $this->expect($op, 201, 'POST', "{$base}/versions", ['kind' => 'draft']);
        $this->assertSame([1, 'draft'], [$version['version'], $version['payload']['kind']]);
        $this->expect($op, 404, 'POST', '/api/v1/financial-reports/'.Str::uuid().'/versions', ['kind' => 'draft']);

        $op = 'GET /api/v1/financial-reports/{id}/versions';
        $this->assertSame([1], array_column($this->expect($op, 200, 'GET', "{$base}/versions")['data'], 'version'));
        $this->expect($op, 404, 'GET', '/api/v1/financial-reports/'.Str::uuid().'/versions');

        $op = 'GET /api/v1/financial-reports/{id}/versions/{version}';
        $this->assertSame(1, $this->expect($op, 200, 'GET', "{$base}/versions/1")['version']);
        $this->expect($op, 404, 'GET', "{$base}/versions/2");

        $op = 'GET /api/v1/financial-reports/{id}/versions/{version}/export.xlsx';
        $this->expect($op, 200, 'GET', "{$base}/versions/1/export.xlsx");
        $this->expect($op, 404, 'GET', "{$base}/versions/2/export.xlsx");
        $this->expect($op, 404, 'GET', '/api/v1/financial-reports/'.Str::uuid().'/versions/1/export.xlsx');

        $op = 'GET /api/v1/financial-reports/{id}/versions/{version}/export.docx';
        $this->expect($op, 200, 'GET', "{$base}/versions/1/export.docx");
        $this->expect($op, 404, 'GET', "{$base}/versions/2/export.docx");
        $this->expect($op, 404, 'GET', '/api/v1/financial-reports/'.Str::uuid().'/versions/1/export.docx');

        $op = 'GET /api/v1/financial-reports/{id}/versions/{version}/export.pdf';
        $this->expect($op, 200, 'GET', "{$base}/versions/1/export.pdf");
        $this->expect($op, 404, 'GET', "{$base}/versions/2/export.pdf");
        $this->expect($op, 404, 'GET', '/api/v1/financial-reports/'.Str::uuid().'/versions/1/export.pdf');
    }

    /* ---------- coverage ---------- */

    /** Runs last (declaration order): every status the spec documents was sent and checked above. */
    public function test_zz_every_documented_status_was_checked(): void
    {
        if (count(OpenApiContract::$checked) < 10) {
            $this->markTestSkipped('Only meaningful when the whole class runs.');
        }
        $missing = [];
        foreach (OpenApiContract::get()->documented() as $operation => $statuses) {
            foreach ($statuses as $status) {
                $skip = $this->live && in_array($status, self::KERNEL_ONLY[$operation] ?? [], true);
                if (! $skip && ! isset(OpenApiContract::$checked[$operation][$status])) {
                    $missing[] = "{$operation} {$status}";
                }
            }
        }
        $this->assertSame([], $missing, 'Documented statuses no test sends.');
    }
}
