<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company's code group: the accounts whose numbers start with `prefix` are the rule's kind. */
class CompanyCodeGroup extends Model
{
    protected $fillable = ['company_id', 'prefix', 'rule_id'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ClassificationRule::class, 'rule_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
