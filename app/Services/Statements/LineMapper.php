<?php

namespace App\Services\Statements;

use App\Services\ParentRows;
use InvalidArgumentException;

/**
 * Maps each classified account row to statement lines (plan §4.2 step 2). Priority: per-report override → side rule →
 * sub-class rule → main-class rule; an expense by nature takes its line from its function: the file's own label first,
 * then the config default (Q34). Each column group gets its own line, so a bank overdrawn last year and in funds now is
 * an overdraft in the comparative and cash this year. Lines are {line => share}: an override may split an account.
 *
 * Nothing is re-mapped silently: when a check doubts a row (pending_flags, a periodic-inventory pair), the row keeps its
 * line and becomes pending, with the reason and any proposal. The classifier's labels and the client's own column are
 * defaults, never trusted over a check.
 */
final class LineMapper
{
    private const MATERIALITY_KEY = 'classification.materiality_threshold';

    public function __construct(private LineCatalogue $catalogue, private PeriodicInventoryPairs $pairs)
    {
    }

    /**
     * @param list<array> $rows      the run's rows (result.data)
     * @param array       $options   overrides: [row => line | [line => share]], excluded: [row => the user's reason],
     *                               tags: [row => [tag]], partners_current: liability | equity
     * @return array{rows: array<int, array>, unmapped: array<int, array>, excluded: array<int, array>, proposals: list<array>, parent_proposals: list<array>}
     */
    public function map(array $rows, LayoutProfile $profile, array $options = []): array
    {
        $overrides = $options['overrides'] ?? [];
        $excluded  = $options['excluded'] ?? [];
        $tags      = $options['tags'] ?? [];
        $result    = ['rows' => [], 'unmapped' => [], 'excluded' => [], 'proposals' => [], 'parent_proposals' => []];

        foreach ($rows as $row) {
            $n = $row['row'];
            $ruled = $this->byRule($row, $profile, $options);
            // Q36: deferred finance cost is netted against the loans; it sits against the line's side by nature.
            $contra = in_array('deferred_finance_cost', $tags[$n] ?? [], true) ? ['contra' => true] : [];

            // The user's line or tag wins; what the rule alone gives is kept as `original` (the review shows both, PR 13c).
            $original = ['original' => isset($ruled['rows'])
                ? ['lines' => $ruled['rows']['lines']['closing'], 'reason' => null]
                : ['lines' => null, 'reason' => ($ruled['unmapped'] ?? $ruled['excluded'])['reason']]];
            if (isset($excluded[$n])) {
                // Left out of the statements by the user, with a reason (an override): its balance is counted elsewhere.
                $result['excluded'][$n] = ['row' => $n, 'reason' => 'user', 'note' => $excluded[$n]] + $original;
                continue;
            }
            if (isset($overrides[$n])) {
                $lines = $this->shares($overrides[$n]);
                $result['rows'][$n] = $this->mapping($n, ['closing' => $lines, 'opening' => $lines], 'override', 'ok', [], $contra + $original);
                continue;
            }
            if ($contra !== []) {
                $lines = [$this->catalogue->tags()['deferred_finance_cost'] => 1.0];
                $result['rows'][$n] = $this->mapping($n, ['closing' => $lines, 'opening' => $lines], 'tag', 'ok', [], $contra + $original);
                continue;
            }
            $result[array_key_first($ruled)][$n] = reset($ruled);

            // A probable parent (its balances are the rows' below it): counted where the rule puts it, pending, and
            // proposed for exclusion until the user excludes it or keeps it (an override).
            $flag = collect($row['data_flags'] ?? [])->firstWhere('type', ParentRows::AMOUNTS);
            if ($flag !== null && isset($result['rows'][$n])) {
                $result['rows'][$n]['status'] = 'pending_confirmation';
                $result['rows'][$n]['reasons'][] = ['code' => ParentRows::AMOUNTS, 'message' => $flag['message']];
                $result['parent_proposals'][] = ['row' => $n, 'rows' => $flag['rows'] ?? [], 'current_line' => (string) array_key_first($result['rows'][$n]['lines']['closing']),
                    'message' => $flag['message'], 'message_phrase' => $flag['message_phrase'] ?? null];
            }
        }

        // Periodic-inventory safety net (Q32b): proposals only; the rows stay where they are, pending.
        foreach ($this->pairs->find($rows, $result['rows'], $profile) as $proposal) {
            $result['proposals'][] = $proposal;
            $n = $proposal['row'];
            if (($result['rows'][$n]['source'] ?? null) !== 'override') {
                $result['rows'][$n]['status']    = 'pending_confirmation';
                $result['rows'][$n]['reasons'][] = ['code' => 'periodic_inventory_pair', 'message' => $proposal['message']];
            }
        }

        return $result;
    }

    /**
     * Where the classification alone puts a row, before the user's override or tag.
     *
     * @return array{rows: array}|array{unmapped: array}|array{excluded: array}
     */
    private function byRule(array $row, LayoutProfile $profile, array $options): array
    {
        $n = $row['row'];
        foreach (ParentRows::EXCLUDED as $flag) {
            if ($this->hasFlag($row, $flag)) {
                // Its balance is the sum of accounts already in the file: counting it would double them.
                return ['excluded' => ['row' => $n, 'reason' => $flag]];
            }
        }
        if ($row['sub_class'] === null) {
            return ['unmapped' => ['row' => $n, 'reason' => 'unclassified', 'candidates' => []]];
        }

        $rule = $this->catalogue->rule($row['sub_class'], $row['main_class']);
        if ($rule === null) {
            return ['unmapped' => ['row' => $n, 'reason' => 'no_rule', 'candidates' => []]];
        }
        if (isset($rule['choose'])) {
            // message: Arabic, as always; message_phrase: the same for any language (the state gives it as message_text).
            return ['unmapped' => ['row' => $n, 'reason' => 'choose', 'candidates' => $rule['choose'], 'message' => $rule['reason']]
                + (isset($rule['reason_key']) ? ['message_phrase' => ['key' => 'report_messages.choose_reasons.'.$rule['reason_key'], 'params' => []]] : [])];
        }

        $reasons = [];
        $source  = $rule['source'];
        $extra   = [];
        if (isset($rule['function'])) {
            $client = $this->catalogue->functionOf($row['existing_classification'] ?? null)
                ?? $this->catalogue->functionOf($row['existing_note_classification'] ?? null);
            $function = $client ?? $rule['function'];
            $source   = $client !== null ? 'function_client' : 'function_default';
            $extra    = ['function' => $function, 'default_function' => $rule['function'], 'client_function' => $client];
            $line     = $this->catalogue->functionLine($function);
        } elseif (isset($rule['partners_current'])) {
            $line = $this->catalogue->partnersCurrentLine($options['partners_current'] ?? null);
        } else {
            $line = $rule['line'];
        }
        if (isset($rule['confirm'])) {
            $reasons[] = ['code' => 'confirm_rule', 'message' => $rule['confirm']];
        }
        foreach ($this->pendingFlags($row) as $flag) {
            $reasons[] = ['code' => $flag['type'], 'message' => $flag['message'] ?? null];
        }

        // Side rules, per column group: the balance decides (a credit bank balance is an overdraft).
        $lines = [];
        foreach (['closing' => $profile->closing($row), 'opening' => $profile->opening($row)] as $group => $net) {
            $side = $net === null || abs($net) <= $this->materiality() ? null : ($net > 0 ? 'debit' : 'credit');
            $moved = $side !== null ? $this->catalogue->sideRule($row['main_class'], $side) : null;
            $lines[$group] = [($moved ?? $line) => 1.0];
            if ($moved !== null && $group === 'closing') {
                $source = 'side_rule';
            }
        }

        return ['rows' => $this->mapping($n, $lines, $source, $reasons === [] ? 'ok' : 'pending_confirmation', $reasons, $extra)];
    }

    private function mapping(int $n, array $lines, string $source, string $status, array $reasons = [], array $extra = []): array
    {
        return ['row' => $n, 'lines' => $lines, 'source' => $source, 'status' => $status, 'reasons' => $reasons] + $extra;
    }

    /** An override: one line, or shares of lines that add up to 1. */
    private function shares(string|array $override): array
    {
        $shares = is_string($override) ? [$override => 1.0] : array_map('floatval', $override);
        foreach (array_keys($shares) as $line) {
            if (! $this->catalogue->has($line)) {
                throw new InvalidArgumentException("Unknown line {$line}");
            }
        }
        if (abs(array_sum($shares) - 1.0) > 0.0001 || min($shares) <= 0) {
            throw new InvalidArgumentException('Shares must be positive and add up to 1');
        }
        return $shares;
    }

    private function pendingFlags(array $row): array
    {
        return array_values(array_filter($row['data_flags'] ?? [], function ($flag) {
            foreach ($this->catalogue->pendingFlags() as $spec) {
                [$type, $severity] = array_pad(explode(':', $spec, 2), 2, null);
                if ($flag['type'] === $type && ($severity === null || ($flag['severity'] ?? null) === $severity)) {
                    return true;
                }
            }
            return false;
        }));
    }

    private function hasFlag(array $row, string $type): bool
    {
        return in_array($type, array_column($row['data_flags'] ?? [], 'type'), true);
    }

    private function materiality(): float
    {
        return (float) config(self::MATERIALITY_KEY, 1.0);
    }
}
