<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One uploaded trial balance being classified by a queued job. */
class ClassificationRun extends Model
{
    use BelongsToOffice, HasUuids;

    protected $fillable = [
        'office_id', 'status', 'stage', 'progress_done', 'progress_total', 'original_name', 'company_id', 'file_path', 'result', 'error', 'error_phrase',
        'structure', 'structure_issues', 'confirmation', 'expires_at', 'confirmed_at', 'lease', 'heartbeat_at', 'usage', 'rows_from_cache', 'rows_sent_to_llm',
        'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'llm_requests', 'cost_usd', 'cost_per_row_usd',
        'estimated_savings_usd', 'savings_basis', 'provider', 'model', 'started_at',
    ];

    /** Statuses a run can still be cancelled in (nothing is processing yet). */
    public const CANCELLABLE = ['queued', 'needs_confirmation'];

    /** Client data kept only while the run waits: cleared, with the file, when it ends, is cancelled or expires. */
    public const WAITING_DATA = ['confirmation' => null, 'structure_issues' => null, 'expires_at' => null];

    protected function casts(): array
    {
        return [
            'result'           => 'array',
            'error_phrase'     => 'array',
            'structure'        => 'array',
            'structure_issues' => 'array',
            'confirmation'     => 'array',
            'usage'            => 'array',
            'expires_at'       => 'datetime',
            'confirmed_at'     => 'datetime',
            'heartbeat_at'     => 'datetime',
            'started_at'       => 'datetime',
        ];
    }
}
