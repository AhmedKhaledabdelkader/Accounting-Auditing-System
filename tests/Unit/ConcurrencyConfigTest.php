<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** CLASSIFIER_CONCURRENCY is read in config/classification.php; invalid values fall back to 4. */
class ConcurrencyConfigTest extends TestCase
{
    public static function values(): array
    {
        return [
            'valid'      => ['6', 6],
            'one'        => ['1', 1],
            'zero'       => ['0', 4],
            'negative'   => ['-3', 4],
            'not a number' => ['abc', 4],
            'decimal'    => ['2.5', 4],
            'empty'      => ['', 4],
        ];
    }

    #[DataProvider('values')]
    public function test_concurrency_from_env(string $value, int $expected): void
    {
        $this->assertSame($expected, $this->concurrencyWith($value));
    }

    public function test_unset_defaults_to_4(): void
    {
        $this->assertSame(4, $this->concurrencyWith(null));
    }

    /** env() reads $_SERVER and $_ENV before getenv(), and .env fills them: set all three, then restore. */
    private function concurrencyWith(?string $value): int
    {
        $key   = 'CLASSIFIER_CONCURRENCY';
        $saved = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($value === null ? $key : "{$key}={$value}");
        if ($value !== null) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        try {
            return (require config_path('classification.php'))['concurrency'];
        } finally {
            putenv($saved[0] === false ? $key : "{$key}={$saved[0]}");
            unset($_ENV[$key], $_SERVER[$key]);
            if ($saved[1] !== null) { $_ENV[$key] = $saved[1]; }
            if ($saved[2] !== null) { $_SERVER[$key] = $saved[2]; }
        }
    }
}
