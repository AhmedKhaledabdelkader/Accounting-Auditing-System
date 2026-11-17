<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the accountant's classification rules (ClassificationRules): a kind of balance-sheet account, the words it is
 * recognised by, and its class for a debit or a credit balance (null: not decided yet).
 */
class ClassificationRule extends Model
{
    public const REVIEW_SIDES = ['none', 'debit', 'credit', 'both'];

    protected $fillable = ['name', 'sort', 'name_words', 'also_words', 'exclude_words', 'heading_words', 'debit_class', 'credit_class',
        'review_side', 'ai_classes', 'enabled'];

    protected function casts(): array
    {
        return ['name_words' => 'array', 'also_words' => 'array', 'exclude_words' => 'array', 'heading_words' => 'array',
            'ai_classes' => 'array', 'enabled' => 'boolean'];
    }

    public function codeGroups(): HasMany
    {
        return $this->hasMany(CompanyCodeGroup::class, 'rule_id');
    }
}
