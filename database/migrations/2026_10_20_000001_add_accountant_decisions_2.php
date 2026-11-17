<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The accountant's answers of 2026-10-20 (docs/17-classification-rules.md): the general accrued-salaries account with a
// debit balance is an employee advance. The counterparts of the other classes are side pairs (config classification.side_pairs).
return new class extends Migration
{
    public function up(): void
    {
        $rule = fn (string $name, array $values) => DB::table('classification_rules')->where('name', $name)
            ->update(array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $values) + ['updated_at' => now()]);
        $rule('رواتب مستحقة (عام)', ['debit_class' => 'سلف العاملين', 'review_side' => 'none']);
    }

    public function down(): void
    {
        // Data decisions: not undone.
    }
};
