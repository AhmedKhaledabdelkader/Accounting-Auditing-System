<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Support\Llm\AiBudget;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\MeteredDriver;
use App\Support\Llm\ProviderException;
use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every AI call is recorded for the user it was for, and a user with a monthly dollar limit is stopped once it is used up. */
class AiLimitTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username, ?float $limit, bool $admin = false): User
    {
        $office = Office::firstOrCreate(['code' => 'alpha'], ['name_ar' => 'مكتب']);
        $role = Role::create(['name_ar' => 'دور '.$username, 'is_admin' => $admin]);
        $admin || $role->syncPermissions(['classify.view', 'classify.run']);
        $user = User::create(['office_id' => $office->id, 'name' => $username, 'username' => $username, 'password' => 'secret123', 'role_id' => $role->id, 'status' => User::ACTIVE]);
        $user->forceFill(['ai_limit_usd' => $limit])->save();

        return $user;
    }

    /** A provider that answers every call with 100,000 input and 10,000 output tokens. */
    private function metered(): object
    {
        config(['classification.provider' => 'gemini', 'classification.providers.gemini.model' => 'gemini-3.8-flash']);
        $inner = new class implements ClassifierDriver
        {
            public int $sent = 0;

            public function classify(ToolCall $call): PromiseInterface
            {
                $this->sent++;

                return Create::promiseFor(new ToolAnswer(['ok' => true], ['input' => 100000, 'cache_write' => 0, 'cached' => 0, 'output' => 10000]));
            }
        };

        return (object) ['inner' => $inner, 'driver' => new MeteredDriver($inner)];
    }

    private function toolCall(): ToolCall
    {
        return new ToolCall([['text' => 's']], ['name' => 'submit_rows', 'description' => 'd', 'schema' => []], 'c');
    }

    public function test_each_call_is_recorded_with_its_cost_and_the_limit_stops_the_user_once_used_up(): void
    {
        $sara = $this->user('sara', 0.30);
        $this->actingAs($sara);
        $m = $this->metered();

        // 100,000 × $0.75 + 10,000 × $3.75 per million = $0.1125 a call: two calls are under $0.30, the third goes over it.
        foreach (range(1, 3) as $i) {
            $m->driver->classify($this->toolCall())->wait();
        }
        $this->assertSame(3, DB::table('ai_usage')->where('user_id', $sara->id)->count());
        $this->assertEqualsWithDelta(0.3375, AiBudget::spent($sara->id), 0.0001);
        $row = DB::table('ai_usage')->first();
        $this->assertSame(['submit_rows', 'gemini', 'gemini-3.8-flash', 100000, 10000], [$row->kind, $row->provider, $row->model, (int) $row->input_tokens, (int) $row->output_tokens]);

        // Used up: refused before anything is sent, a fatal error with the reason.
        try {
            $m->driver->classify($this->toolCall())->wait();
            $this->fail('expected the limit to stop it');
        } catch (ProviderException $e) {
            $this->assertTrue($e->fatal);
            $this->assertStringContainsString('0.30', $e->getMessage());
        }
        $this->assertSame(3, $m->inner->sent);

        // Another user without a limit is not stopped, and next month starts afresh.
        $this->actingAs($this->user('omar', null));
        $m->driver->classify($this->toolCall())->wait();
        $this->assertSame(4, $m->inner->sent);
        $this->actingAs($sara);
        $this->travel(1)->months();
        $m->driver->classify($this->toolCall())->wait();
        $this->assertSame(5, $m->inner->sent);
    }

    public function test_a_queued_job_is_charged_to_the_user_who_started_it(): void
    {
        $sara = $this->user('sara', 1.0);
        AiBudget::forUser($sara->id);   // what the `ai.payer` middleware does; the job carries it (hidden context)
        $this->app['auth']->forgetGuards();   // a queue worker has nobody signed in
        $this->metered()->driver->classify($this->toolCall())->wait();

        $this->assertSame($sara->id, (int) DB::table('ai_usage')->value('user_id'));
        $this->assertSame($sara->office_id, (int) DB::table('ai_usage')->value('office_id'));
    }

    public function test_the_admin_sets_a_limit_on_the_users_page_and_sees_the_months_spending(): void
    {
        $admin = $this->user('admin', null, true);
        $sara = $this->user('sara', null);
        DB::table('ai_usage')->insert(['user_id' => $sara->id, 'office_id' => $sara->office_id, 'kind' => 'submit_rows', 'provider' => 'gemini', 'cost_usd' => 0.25, 'created_at' => now()]);
        $this->actingAs($admin);

        $this->put(route('admin.users.ai-limit', $sara), ['ai_limit_usd' => 1])->assertRedirect()->assertSessionHas('status');
        $this->assertEquals(1.0, $sara->fresh()->ai_limit_usd);
        $this->get('/admin/users')->assertOk()->assertSee('$0.25 / $1.00', false)->assertSee(__('auth_pages.users.ai_left', ['left' => '0.75']));
        $this->put(route('admin.users.ai-limit', $sara), ['ai_limit_usd' => -1])->assertSessionHasErrors('ai_limit_usd');
        $this->put(route('admin.users.ai-limit', $sara), ['ai_limit_usd' => ''])->assertRedirect();
        $this->assertNull($sara->fresh()->ai_limit_usd);   // empty: no limit

        // Only the users' manager.
        $this->actingAs($sara);
        $this->put(route('admin.users.ai-limit', $sara), ['ai_limit_usd' => 100])->assertForbidden();
    }

    private function usedUp(string $username = 'sara', bool $admin = false): User
    {
        $user = $this->user($username, 1.0, $admin);
        DB::table('ai_usage')->insert(['user_id' => $user->id, 'office_id' => $user->office_id, 'kind' => 'submit_rows', 'provider' => 'gemini', 'cost_usd' => 1.05, 'created_at' => now()]);

        return $user;
    }

    public function test_a_used_up_user_starting_ai_work_lands_on_the_limit_page_and_nothing_starts(): void
    {
        $this->actingAs($this->usedUp());
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        // An API call (the classifier page's upload): 402, said where the page is, with the header the pages' scripts follow.
        $file = \Illuminate\Http\UploadedFile::fake()->create('tb.xlsx', 10);
        $this->postJson('/api/v1/classify/jobs', ['file' => $file])->assertStatus(402)->assertHeader('X-AI-Limit', '1')
            ->assertJsonPath('code', 'ai_limit_reached')->assertJsonPath('page', '/ai-limit');
        $this->assertSame(0, \App\Models\ClassificationRun::count());

        // A page's form (a new client: its registration is read by the AI): sent to the page.
        $this->actingAs($this->usedUp('manager', true));
        $this->post('/clients', [])->assertRedirect(route('ai-limit'));
    }

    public function test_the_limit_page_says_it_all_and_the_sidebar_shows_the_balance(): void
    {
        $sara = $this->usedUp();
        $this->user('admin', null, true)->update(['name' => 'مدير المكتب']);
        $this->actingAs($sara);

        $page = $this->get('/ai-limit')->assertOk();
        $page->assertSee('data-ai-limit="used-up"', false)->assertSee(__('auth_pages.ai_limit.pill_spent'))->assertSee('$1.00', false)->assertSee('$1.05', false)
            ->assertSee(__('auth_pages.ai_limit.kinds.classify'))->assertSee('مدير المكتب')
            ->assertSee(__('auth_pages.ai_limit.renews', ['date' => \App\Services\Portal\Workspace::dateText(now()->utc()->startOfMonth()->addMonth())]));
        // Every page shows the balance in the sidebar, red when used up.
        $this->get('/account')->assertOk()->assertSee('ai-meter out', false);

        // A user with balance sees their month, not the «used up» page.
        $this->actingAs($this->user('omar', 2.0));
        $this->get('/ai-limit')->assertOk()->assertSee('data-ai-limit="ok"', false)->assertSee(__('auth_pages.ai_limit.pill_ok'));
        // Without a limit: no meter.
        $this->actingAs($this->user('free', null));
        $this->get('/account')->assertDontSee('class="ai-meter', false);
    }
}
