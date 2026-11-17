<?php

namespace App\Providers;

use App\Repositories\Contracts\AccountClassifierRepositoryInterface;
use App\Repositories\Contracts\TaxonomyMapperRepositoryInterface;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Repositories\Eloquents\AccountClassifierRepository;
use App\Repositories\Eloquents\TaxonomyMapperRepository;
use App\Repositories\Eloquents\TaxonomyRepository;
use App\Jobs\ClassifyTrialBalance;
use App\Support\Heartbeat;
use App\Support\Taxonomy\TaxonomyRules;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Queue;
use App\Support\Llm\AnthropicDriver;
use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\GeminiDriver;
use App\Support\Llm\OpenAiDriver;
use App\Support\Llm\LlmTransport;
use App\Support\Llm\UsageMeter;
use Illuminate\Queue\Events\WorkerStarting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
       
          $this->app->bind(AccountClassifierRepositoryInterface::class, AccountClassifierRepository::class);
          // Read once per request or queued job (scoped: a worker reads the tables again for each job).
          $this->app->scoped(TaxonomyRepository::class);
          $this->app->alias(TaxonomyRepository::class, TaxonomyRepositoryInterface::class);
          $this->app->singleton(TaxonomyRules::class);
          $this->app->bind(TaxonomyMapperRepositoryInterface::class, TaxonomyMapperRepository::class);
          $this->app->singleton(Heartbeat::class);
          $this->app->singleton(UsageMeter::class);
          $this->app->singleton(LlmTransport::class);
          // One per request or job: where it keeps the AI's answers on a PDF is shared by its classifier and extractor.
          $this->app->scoped(\App\Services\Statements\PriorPdf\Llm::class);
          // The only place that knows which AI provider runs (CLASSIFIER_PROVIDER): each driver gets its own config only.
          $this->app->bind(ClassifierDriver::class, function ($app) {
              $provider = config('classification.provider');
              $config   = config("classification.providers.{$provider}", []);

              return match ($provider) {
                  'anthropic' => new AnthropicDriver($app->make(LlmTransport::class), $config),
                  'gemini'    => new GeminiDriver($app->make(LlmTransport::class), $config),
                  'openai'    => new OpenAiDriver($app->make(LlmTransport::class), $config),
                  default     => throw new RuntimeException("Unknown CLASSIFIER_PROVIDER [{$provider}]. Use one of: anthropic, gemini, openai."),
              };
          });
    }



    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Permissions (config/permissions.php) come from the user's role; an inactive user has none.
        \App\Http\Middleware\SetLocaleFromHeader::rememberDefault(config('app.locale'));
        Gate::before(fn (User $user, string $ability) => in_array($ability, Role::catalogue(), true) ? $user->hasPermission($ability) : null);

        // The config rules follow the taxonomy's renames and added sub classes (TaxonomyRules): on every page and API call,
        // every command but the framework's own (migrations, caches: the tables may not exist yet), and every queued job.
        if (! $this->app->runningInConsole()) {
            $this->app->booted(fn () => $this->app->make(TaxonomyRules::class)->apply());
        }
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command !== null && ! preg_match('/^(migrate|db|schema|config|cache|optimize|package|key|vendor|queue|event|route|view|test|about|list|help|env|clear-compiled|storage|make|stub|lang|install|down|up|serve)(:|$)/', $event->command)) {
                $this->app->make(TaxonomyRules::class)->apply();
            }
        });
        Queue::before(fn () => $this->app->make(TaxonomyRules::class)->apply(reload: true));

        // A queue whose retry_after is not above the job timeout hands a long run to a second worker while the first
        // is still on it (the second then fails the run and deletes its file). Refuse to start such a worker.
        Event::listen(WorkerStarting::class, function (WorkerStarting $event) {
            $retryAfter = config("queue.connections.{$event->connectionName}.retry_after");
            if ($retryAfter !== null && (int) $retryAfter <= ClassifyTrialBalance::TIMEOUT) {
                throw new RuntimeException("Queue connection [{$event->connectionName}] has retry_after = {$retryAfter}s, not above the classification job timeout ("
                    .ClassifyTrialBalance::TIMEOUT.'s). Set DB_QUEUE_RETRY_AFTER=1900 (or higher) and restart the worker.');
            }
        });
    }
}
