<?php

namespace App\Services\Statements;

use InvalidArgumentException;

/**
 * One figure of the financial statements, with where it came from (plan §4.4). A missing figure has no amount, never 0.
 * refs: [{type: 'row', row: 12, share?: 0.6}] for trial-balance figures; [{type: 'line', key: 'sofp.cash'}] for totals.
 */
final class Figure
{
    public const ORIGINS = [
        'trial_balance', 'trial_balance_opening', 'derived_from_movement', 'suggested_from_movement',
        'derived', 'user_input', 'user_adjustment', 'prior_year_report', 'prior_year_trial_balance', 'prior_year_manual', 'prior_year_statements', 'missing',
    ];
    public const STATUSES = ['ok', 'pending_confirmation', 'missing', 'check_failed'];

    public function __construct(
        public readonly ?float $amount,
        public readonly string $origin,
        public readonly string $status = 'ok',
        public readonly array $refs = [],
    ) {
        if (! in_array($origin, self::ORIGINS, true)) {
            throw new InvalidArgumentException("Unknown origin {$origin}");
        }
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown status {$status}");
        }
        if (($amount === null) !== ($origin === 'missing')) {
            throw new InvalidArgumentException('Only a missing figure has no amount, and it always has none');
        }
    }

    public static function missing(): self
    {
        return new self(null, 'missing', 'missing');
    }

    /** A total of other figures: missing when any part is, pending when any part is. */
    public static function sum(array $parts, array $refs = []): self
    {
        if ($parts === [] || array_filter($parts, fn (self $f) => $f->amount === null)) {
            return $parts === [] ? new self(0.0, 'derived', 'ok', $refs) : self::missing();
        }
        $pending = array_filter($parts, fn (self $f) => $f->status === 'pending_confirmation') !== [];
        return new self(round(array_sum(array_map(fn (self $f) => $f->amount, $parts)), 2), 'derived', $pending ? 'pending_confirmation' : 'ok', $refs);
    }

    public function isMissing(): bool
    {
        return $this->amount === null;
    }

    public function toArray(): array
    {
        return ['amount' => $this->amount, 'origin' => $this->origin, 'status' => $this->status, 'refs' => $this->refs];
    }
}
