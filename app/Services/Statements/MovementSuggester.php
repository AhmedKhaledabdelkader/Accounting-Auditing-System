<?php

namespace App\Services\Statements;

use App\Support\Phrase;

/**
 * The cells of the movement schedules the user decides (plan §4.2 step 6, §4.4 `suggestions`). A movement read from the
 * trial balance's movement columns is a suggestion, pending until the user confirms it; the user may also edit it or
 * reject it. Without movement columns, a movement is the user's to enter: missing until entered, never 0.
 * Decisions: {key: {action: confirm|edit|reject, amount?}}. A confirmation keeps the amount confirmed: when the trial
 * balance later suggests another amount, the suggestion is pending again.
 */
final class MovementSuggester
{
    public const ACTIONS = ['confirm', 'edit', 'reject', 'reset'];

    /** @var list<array> */
    private array $suggestions = [];
    /** @var list<array{key: string, reason: string, input_key: null}> */
    private array $missing = [];
    /** @var array<string, array{suggested: ?float}> every cell the user may decide */
    private array $cells = [];

    public function __construct(private array $decisions = [])
    {
    }

    /**
     * A movement from the movement columns. Nothing moved (0) is a fact, not a suggestion; the user may still edit it.
     *
     * @param array{schedule: string, row: string, column: string, label_ar: string} $where
     */
    public function suggest(string $key, float $amount, Phrase|string $basis, array $refs, array $where): Figure
    {
        $amount = round($amount, 2) + 0.0;
        $this->cells[$key] = ['suggested' => $amount];
        $decision = $this->decisions[$key] ?? null;
        if (abs($amount) < 0.005 && ($decision['action'] ?? null) !== 'edit') {
            return new Figure(0.0, 'derived_from_movement', 'ok', $refs);
        }

        $status = match ($decision['action'] ?? null) {
            null      => 'pending',
            'confirm' => round((float) $decision['amount'], 2) === $amount ? 'confirmed' : 'pending',
            'edit'    => 'edited',
            'reject'  => 'rejected',
        };
        $this->suggestions[] = $where + ['key' => $key, 'amount' => $amount, 'basis_ar' => self::basis($basis, 'ar'),
            'rows' => array_values(array_unique(array_column(array_filter($refs, fn ($r) => $r['type'] === 'row'), 'row'))),
            'status' => $status, 'value' => $status === 'edited' ? round((float) $decision['amount'], 2) : null,
            'basis' => self::basis($basis, app()->getLocale())];

        return match ($status) {
            'pending'   => new Figure($amount, 'suggested_from_movement', 'pending_confirmation', $refs),
            'confirmed' => new Figure($amount, 'derived_from_movement', 'ok', $refs),
            'edited'    => $this->edited($key),
            'rejected'  => $this->missingCell($key, 'suggestion_rejected'),
        };
    }

    /** A movement only the user can give: their amount, or missing. */
    public function entered(string $key): Figure
    {
        $this->cells[$key] = ['suggested' => null];

        return ($this->decisions[$key]['action'] ?? null) === 'edit' ? $this->edited($key) : $this->missingCell($key, 'movement_required');
    }

    /** A figure the user may correct (e.g. no prior-year zakat adjustment): theirs when entered, else the given one. */
    public function fact(string $key, Figure $figure): Figure
    {
        $this->cells[$key] = ['suggested' => null];

        return ($this->decisions[$key]['action'] ?? null) === 'edit' ? $this->edited($key) : $figure;
    }

    public function suggestions(): array
    {
        return $this->suggestions;
    }

    public function missing(): array
    {
        return $this->missing;
    }

    /** @return array<string, array{suggested: ?float}> */
    public function cells(): array
    {
        return $this->cells;
    }

    /** Where the amount comes from: `basis_ar` in Arabic, `basis` in the request's language. */
    private static function basis(Phrase|string $basis, string $locale): string
    {
        return $basis instanceof Phrase ? $basis->in($locale) : $basis;
    }

    private function edited(string $key): Figure
    {
        return new Figure(round((float) $this->decisions[$key]['amount'], 2) + 0.0, 'user_input', 'ok', [['type' => 'decision', 'key' => $key]]);
    }

    private function missingCell(string $key, string $reason): Figure
    {
        $this->missing[] = ['key' => $key, 'reason' => $reason, 'input_key' => null];

        return Figure::missing();
    }
}
