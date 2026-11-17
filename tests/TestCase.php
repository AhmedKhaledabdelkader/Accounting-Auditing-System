<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Every test runs signed in as an administrator (every permission), unless it signs out (signOut) or signs in as someone
 * else; the user isn't saved, so tests without a database work too.
 *
 * The AI provider is CLASSIFIER_PROVIDER (default anthropic): the suite runs with either one, the fakes answering in its
 * wire format (Tests\Support\FakeWire). Keys are fake, so nothing real is ever called (live tests set their own).
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['classification.providers.anthropic.api_key' => 'test-key', 'classification.providers.gemini.api_key' => 'test-key']);
        $this->actingAs($this->admin());
        // Each test reads PDFs with an empty cache of the AI's answers.
        config(['financial_statements.prior_pdf.cache' => sys_get_temp_dir().'/prior-pdf-cache-'.bin2hex(random_bytes(6))]);
    }

    /** An active administrator, not saved. */
    protected function admin(): User
    {
        $user = new User(['name' => 'مدير الاختبار', 'username' => 'test.admin', 'status' => User::ACTIVE, 'locale' => 'ar']);
        $user->id = 999999;
        $user->setRelation('role', new Role(['name_ar' => 'مدير النظام', 'is_admin' => true]));

        return $user;
    }

    /** No one signed in. */
    protected function signOut(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function provider(): string
    {
        return config('classification.provider');
    }

    /** For tests of one provider's own behaviour (its wire format, its errors): runs them with it whatever CLASSIFIER_PROVIDER is. */
    protected function useProvider(string $provider): void
    {
        config(['classification.provider' => $provider]);
    }

    /** Sets the model of every provider (so it holds after useProvider()). */
    protected function useModel(string $model): void
    {
        foreach (array_keys(config('classification.providers')) as $provider) {
            config(["classification.providers.{$provider}.model" => $model]);
        }
    }
}
