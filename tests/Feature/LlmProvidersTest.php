<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\TrialBalanceFiles;
use Tests\TestCase;

/** Choosing the provider (CLASSIFIER_PROVIDER only), its key, and a classification end to end through Gemini's wire format. */
class LlmProvidersTest extends TestCase
{
    use RefreshDatabase;
    use TrialBalanceFiles;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->useModel('test-model');
    }

    /** Fakes one provider: $tool(Request) = forced tool name, $prompt(Request) = user text, $answer(name, input) = response body. */
    private function fakeProvider(string $url, \Closure $tool, \Closure $prompt, \Closure $answer): void
    {
        Http::fake([$url => function (Request $r) use ($tool, $prompt, $answer) {
            if ($tool($r) === 'submit_structure') {
                return Http::response($answer('submit_structure', $this->standardStructure()));
            }
            preg_match_all('/^R(\d+) \|/m', explode("\n\n", explode("Rows to classify:\n", $prompt($r))[1] ?? '')[0], $m);
            $rows = array_values(array_filter([$this->account(2, 'نقدية بالصندوق', 95)], fn ($l) => in_array($l['row'], array_map('intval', $m[1]), true)));

            return Http::response($answer('submit_rows', ['rows' => $rows]));
        }]);
    }

    private function classify(): TestResponse
    {
        return $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance([['110101', 'الصندوق', 0, 0, 100, 0, 100, 0]])])
            ->assertOk()
            ->assertJsonPath('data.0.sub_class', 'نقدية بالصندوق')
            ->assertJsonPath('data.0.classification_confidence', 95);
    }

    public function test_gemini(): void
    {
        config(['classification.provider' => 'gemini']);
        $this->useModel('test-model');
        $this->fakeProvider(
            'generativelanguage.googleapis.com/*',
            fn (Request $r) => $r['toolConfig']['functionCallingConfig']['allowedFunctionNames'][0],
            fn (Request $r) => $r['contents'][0]['parts'][0]['text'],
            fn ($name, $input) => ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $name, 'args' => $input]]]]]]],
        );

        $this->classify();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/test-model:generateContent'
            && $r->hasHeader('x-goog-api-key', 'test-key')
            && isset($r['tools'][0]['functionDeclarations'][0]['parametersJsonSchema']['properties'])
            && $r['toolConfig']['functionCallingConfig']['mode'] === 'ANY');
        Http::assertNotSent(fn (Request $r) => ($r['generationConfig']['maxOutputTokens'] ?? null) === 1);   // no cache warm-up call
    }

    /** Loads the provider settings of config/classification.php with these .env values (null = unset). */
    private function useEnv(array $vars): void
    {
        $saved = array_map(fn ($n) => [$_ENV[$n] ?? null, $_SERVER[$n] ?? null, getenv($n)], array_combine(array_keys($vars), array_keys($vars)));
        foreach ($vars as $n => $v) {
            unset($_ENV[$n], $_SERVER[$n]);
            putenv($n);
            if ($v !== null) {
                $_ENV[$n] = $_SERVER[$n] = $v;
                putenv("{$n}={$v}");
            }
        }
        try {
            $config = require config_path('classification.php');
            config(['classification.provider' => $config['provider'], 'classification.providers' => $config['providers']]);
        } finally {
            foreach ($saved as $n => [$env, $server, $put]) {
                unset($_ENV[$n], $_SERVER[$n]);
                putenv($n);
                $env !== null && $_ENV[$n] = $env;
                $server !== null && $_SERVER[$n] = $server;
                $put !== false && putenv("{$n}={$put}");
            }
        }
    }

    private const ENV = ['ANTHROPIC_API_KEY' => 'anthropic-secret', 'CLASSIFIER_ANTHROPIC_MODEL' => 'claude-sonnet-5',
        'GEMINI_API_KEY' => 'gemini-secret', 'CLASSIFIER_GEMINI_MODEL' => null, 'CLASSIFIER_GEMINI_THINKING_LEVEL' => null];

    /** @return iterable<array{0: string, 1: string, 2: string, 3: string, 4: string}> provider, URL, key header, its key, model */
    public static function providers(): iterable
    {
        yield 'gemini'    => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent', 'x-goog-api-key', 'gemini-secret', 'gemini-3.8-flash'];
        yield 'anthropic' => ['anthropic', 'https://api.anthropic.com/v1/messages', 'x-api-key', 'anthropic-secret', 'claude-sonnet-5'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_switching_provider_is_one_env_variable_and_a_key_never_reaches_the_other_provider(string $provider, string $url, string $header, string $key, string $model): void
    {
        $this->useEnv(['CLASSIFIER_PROVIDER' => $provider] + self::ENV);
        $this->fakeLlm($this->standardStructure(), [$this->account(2, 'نقدية بالصندوق', 95)]);

        $this->classify()->assertJsonPath('meta.provider', $provider)->assertJsonPath('meta.model', $model);

        Http::assertSent(fn (Request $r) => $r->url() === $url && $r->hasHeader($header, $key));
        $other = $key === 'gemini-secret' ? 'anthropic-secret' : 'gemini-secret';
        Http::assertNotSent(fn (Request $r) => $r->url() !== $url || str_contains(json_encode($r->headers()).$r->body(), $other));
    }

    /** @return iterable<array{0: string, 1: string, 2: ?string}> provider, its key variable, the key's value */
    public static function missingKeys(): iterable
    {
        foreach (['anthropic' => ['Anthropic', 'ANTHROPIC_API_KEY'], 'gemini' => ['Gemini', 'GEMINI_API_KEY']] as $provider => [$name, $var]) {
            yield "{$provider} placeholder" => [$provider, $name, $var, '<need-to-change>'];
            yield "{$provider} empty"       => [$provider, $name, $var, ''];
            yield "{$provider} unset"       => [$provider, $name, $var, null];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingKeys')]
    public function test_a_missing_or_placeholder_key_fails_clearly_before_any_ai_call(string $provider, string $name, string $var, ?string $value): void
    {
        $this->useEnv(['CLASSIFIER_PROVIDER' => $provider, $var => $value] + self::ENV);
        $this->assertNull(config("classification.providers.{$provider}.api_key"));
        Http::fake();
        $message = "{$name} API key is missing. Set {$var} in .env, then run php artisan queue:restart.";

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance([['110101', 'الصندوق', 0, 0, 100, 0, 100, 0]])])
            ->assertStatus(502)
            ->assertJsonPath('message', $message);

        Storage::fake('local');
        $id = $this->postJson('/api/v1/classify/jobs', ['file' => $this->trialBalance([['110101', 'الصندوق', 0, 0, 100, 0, 100, 0]])])->assertStatus(202)->json('id');
        $this->getJson("/api/v1/classify/jobs/{$id}")->assertJsonPath('status', 'failed')->assertJsonPath('error', $message)
            ->assertJsonPath('provider', $provider);

        Http::assertNothingSent();
    }

    public function test_unknown_provider_is_a_clear_error(): void
    {
        config(['classification.provider' => 'nope']);

        $this->postJson('/api/v1/classify/trial-balance', ['file' => $this->trialBalance([['110101', 'الصندوق', 0, 0, 100, 0, 100, 0]])])
            ->assertStatus(500)
            ->assertJsonPath('message', 'Unknown CLASSIFIER_PROVIDER [nope]. Use one of: anthropic, gemini, openai.');
    }
}
