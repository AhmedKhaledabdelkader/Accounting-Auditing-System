<?php

namespace Tests\Support;

use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Services\Statements\LayoutProfile;

/**
 * Builds classified rows as a run stores them (ClassifiedAccountResource), for the statements generator tests.
 * Balances are signed: positive = debit, negative = credit. Groups the file lacks are copy-filled like the classifier does.
 */
trait ClassifiedRows
{
    /**
     * @param array{opening?: float, period?: array{0: float, 1: float}, adjustment?: array{0: float, 1: float}, closing?: float} $amounts
     * @param array $extra any row field (data_flags, existing_classification, …)
     */
    protected function account(int $row, ?string $subClass, array $amounts = [], array $extra = []): array
    {
        $entry = $subClass !== null ? app(TaxonomyRepositoryInterface::class)->find($subClass) : null;
        $this->assertTrue($subClass === null || $entry !== null, "unknown sub-class {$subClass}");

        $opening = (float) ($amounts['opening'] ?? 0);
        [$pd, $pc] = $amounts['period'] ?? [0, 0];
        [$ad, $ac] = $amounts['adjustment'] ?? [0, 0];
        $closing = (float) ($amounts['closing'] ?? $opening + $pd - $pc + $ad - $ac);
        $split = fn (float $net) => [max($net, 0), max(-$net, 0)];

        return array_merge([
            'row' => $row, 'account_number' => (string) (1000 + $row), 'account_name' => "حساب {$row}",
            'financial_statement' => $entry[0] ?? null, 'main_class' => $entry[1] ?? null, 'sub_class' => $subClass,
            'opening_debit' => $split($opening)[0], 'opening_credit' => $split($opening)[1],
            'period_debit' => (float) $pd, 'period_credit' => (float) $pc,
            'adjustment_debit' => (float) $ad, 'adjustment_credit' => (float) $ac,
            'closing_debit' => $split($closing)[0], 'closing_credit' => $split($closing)[1],
            'classification_confidence' => $subClass === null ? 0 : 95, 'classification_reason' => '', 'data_flags' => [],
            'source_note' => null, 'existing_classification' => null, 'existing_note_classification' => null,
            'existing_main_classes' => null, 'audit_request' => null, 'note_reference' => null, 'cache_key' => null,
            'needs_review' => $subClass === null, 'note' => '',
        ], $extra);
    }

    /** A stored run result: rows plus meta.groups (the groups the file really has). */
    protected function runResult(array $rows, array $groups = ['opening', 'period', 'closing']): array
    {
        return ['data' => $rows, 'meta' => ['groups' => $groups, 'total' => count($rows)]];
    }

    protected function profile(array $rows, array $groups = ['opening', 'period', 'closing']): LayoutProfile
    {
        return LayoutProfile::fromResult($this->runResult($rows, $groups), app(TaxonomyRepositoryInterface::class));
    }
}
