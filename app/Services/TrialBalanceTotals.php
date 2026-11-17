<?php

namespace App\Services;

/** Debit and credit totals of a trial balance's account rows, per column group: the file check and the statements' check 1. */
final class TrialBalanceTotals
{
    public const GROUP_LABELS = [
        'opening'    => 'الرصيد الافتتاحي',
        'period'     => 'حركة الفترة',
        'adjustment' => 'التسويات',
        'closing'    => 'الرصيد الختامي',
    ];

    /**
     * Parents are left out (ParentRows::EXCLUDED): their balance is the sum of accounts already counted.
     *
     * @param list<array>  $rows   account rows (total, subtotal and parent rows never reach them)
     * @param list<string> $groups
     * @return array<string, array{0: float, 1: float}> group → [debit, credit]
     */
    public static function sums(array $rows, array $groups): array
    {
        $counted = array_filter($rows, fn ($r) => ! ParentRows::excluded($r));
        $sums    = [];
        foreach ($groups as $g) {
            $sums[$g] = [round(array_sum(array_column($counted, "{$g}_debit")), 2), round(array_sum(array_column($counted, "{$g}_credit")), 2)];
        }

        return $sums;
    }
}
