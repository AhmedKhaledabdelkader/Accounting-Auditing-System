<?php

namespace App\Services\Statements\PriorPdf;

use App\Support\Llm\ClassifierDriver;
use App\Support\Llm\Provider;
use App\Support\Llm\ProviderException;
use App\Support\Llm\ToolAnswer;
use App\Support\Llm\ToolCall;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The AI calls of reading a PDF (brief §8), each answer kept on disk by the file's content, the call (classify, or a
 * page's extraction and its attempt), the page's rotation, the prompts' version and the model: reading the same file
 * again, or after a failure, pays only for what wasn't asked yet. A cached answer costs nothing (usage null).
 *
 * The cache lives under config financial_statements.prior_pdf.cache (storage/app/private/prior-statements/cache by
 * default); a client fixture's lives in docs/reference/ (gitignored), never in git.
 */
class Llm
{
    /** Bump when a prompt or a tool changes: older answers are then asked again. */
    public const VERSION = 1;

    private ?string $dir = null;

    public function __construct(private ClassifierDriver $driver) {}

    /** Answers are kept in $dir instead of the configured cache (the fixture command). */
    public function cacheIn(?string $dir): static
    {
        $this->dir = $dir;

        return $this;
    }

    /** One call, from the cache when it was asked before. */
    public function ask(string $path, string $kind, int $page, int $variant, callable $call): ToolAnswer
    {
        return $this->askMany($path, [[$kind, $page, $variant, $call]])[0];
    }

    /**
     * Several calls at once (sent together); each from the cache when it was asked before.
     *
     * @param list<array{0: string, 1: int, 2: int, 3: callable(): ToolCall}> $calls [kind, page, variant (rotation and attempt), the call]
     * @return list<ToolAnswer> in the same order
     */
    public function askMany(string $path, array $calls): array
    {
        $hash = hash_file('sha256', $path);
        $out = [];
        $pending = [];
        foreach ($calls as $i => [$kind, $page, $variant, $call]) {
            $file = $this->file($hash, $kind, $page, $variant);
            if (is_file($file) && is_array($kept = json_decode((string) file_get_contents($file), true)) && ($kept['input'] ?? []) !== []) {
                $out[$i] = new ToolAnswer($kept['input'], null);
                continue;
            }
            $pending[$i] = [$file, $this->driver->classify($call())];
        }
        foreach (Utils::settle(array_map(fn ($p) => $p[1], $pending))->wait() as $i => $settled) {
            if ($settled['state'] !== 'fulfilled') {
                throw $settled['reason'] instanceof ProviderException ? $settled['reason'] : new RuntimeException((string) $settled['reason']);
            }
            $answer = $settled['value'];
            if ($answer->input === []) {
                throw new RuntimeException('The AI gave no answer'.($answer->noToolCall ? " ({$answer->noToolCall})" : ''));
            }
            File::ensureDirectoryExists(dirname($pending[$i][0]));
            file_put_contents($pending[$i][0], json_encode(['input' => $answer->input, 'usage' => $answer->usage],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $out[$i] = $answer;
        }
        ksort($out);

        return array_values($out);
    }

    /** The usages of answers, added up (cached answers count nothing). */
    public static function sum(array $usages): array
    {
        $total = ['requests' => 0, 'input' => 0, 'cache_write' => 0, 'cached' => 0, 'output' => 0];
        foreach (array_filter($usages) as $u) {
            $total['requests']++;
            foreach (['input', 'cache_write', 'cached', 'output'] as $k) {
                $total[$k] += (int) ($u[$k] ?? 0);
            }
        }

        return $total;
    }

    private function file(string $hash, string $kind, int $page, int $variant): string
    {
        $model = preg_replace('/[^A-Za-z0-9._-]/', '_', Provider::name().'-'.(Provider::model() ?? 'model'));
        $dir = $this->dir ?? (string) config('financial_statements.prior_pdf.cache', storage_path('app/private/prior-statements/cache'));

        return "{$dir}/{$hash}/v".self::VERSION."-{$model}/{$kind}-p{$page}-v{$variant}.json";
    }
}
