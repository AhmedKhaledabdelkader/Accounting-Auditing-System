<?php

namespace Tests\Support;

use App\Repositories\Contracts\AccountClassifierRepositoryInterface;
use Closure;
use App\Support\Llm\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/** In-memory classifier: records how batches were sent and can fail chosen batches (per attempt). */
class RecordingClassifier implements AccountClassifierRepositoryInterface
{
    /** @var list<array{type: string, rows: list<int>}> in call order */
    public array $calls = [];

    /** @var array<int, int> first row of a batch => how many more attempts should fail */
    public array $failures = [];

    /** @var list<int> rows the AI always leaves out of its answers */
    public array $missing = [];

    public function __construct(private array $structure, private string $subClass = 'نقدية بالصندوق') {}

    public function detectStructure(array $preview, array $previousErrors = [], array $sheetNames = [], array $letters = []): array
    {
        return $this->structure;
    }

    public function warmCache(array $structure, array $headerRows, array $fileContext): void
    {
        $this->calls[] = ['type' => 'warm', 'rows' => []];
    }

    /** Runs the batches one after another, emulating the repository's 3 attempts per request. */
    public function classifyRowsRolling(array $batches, int $concurrency, Closure $onAnswer): void
    {
        $this->calls[] = ['type' => 'rolling', 'rows' => array_map(fn ($b) => array_keys($b[2]), array_values($batches)), 'concurrency' => $concurrency];

        foreach ($batches as $key => $args) {
            $retry = $onAnswer($key, $this->attempts($args[2]), false);
            if ($retry !== null) {
                $onAnswer($key, $this->attempts($retry[2]), true);
            }
        }
    }

    private function attempts(array $rows): array|Throwable
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->calls[] = ['type' => 'request', 'rows' => array_keys($rows)];
            $answer = $this->answer($rows);
            if (! $answer instanceof Throwable) {
                return $answer;
            }
        }
        return $answer;
    }

    private function answer(array $rows): array|Throwable
    {
        $first = array_key_first($rows);
        if (($this->failures[$first] ?? 0) > 0) {
            $this->failures[$first]--;
            return ProviderException::from(new ConnectionException("batch starting at R{$first} failed"), false);
        }

        return ['rows' => array_map(
            fn ($n) => ['row' => $n, 'row_type' => 'account', 'sub_class' => $this->subClass, 'confidence' => 95, 'reason' => 'واضح'],
            array_values(array_diff(array_keys($rows), $this->missing))
        ), 'adjustment_matches' => []];
    }
}
