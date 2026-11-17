<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One set of financial statements built from a classified run (plan §4.1). */
class FinancialReport extends Model
{
    use BelongsToOffice, HasUuids;

    public const DRAFT = 'draft';
    public const FINAL = 'final';

    protected $fillable = [
        'office_id', 'company_id', 'classification_run_id', 'prior_report_id', 'prior_run_id', 'status', 'period_start', 'period_end',
        'comparative_start', 'comparative_end', 'template_version', 'inputs', 'account_overrides', 'adjustments', 'movement_decisions', 'zakat_tax', 'notes', 'acknowledgements', 'prior_figures', 'prior_upload',
        'run_result_hash', 'last_computed_at', 'finalized_at',
    ];

    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'comparative_start' => 'date:Y-m-d', 'comparative_end' => 'date:Y-m-d',
            'inputs' => 'array', 'account_overrides' => 'array', 'adjustments' => 'array', 'movement_decisions' => 'array', 'zakat_tax' => 'array', 'notes' => 'array', 'acknowledgements' => 'array', 'prior_figures' => 'array', 'prior_upload' => 'array',
            'last_computed_at' => 'datetime', 'finalized_at' => 'datetime',
        ];
    }

    /**
     * The report's own part, as CurrentYearStatements reads it: overrides as row => line | [line => share], the rows the
     * user left out (row => reason), categories and
     * tags per row, the inputs, the adjusting entries and the decisions on the movement suggestions.
     */
    public function statementOptions(): array
    {
        $overrides = $categories = $tags = $excluded = [];
        foreach ($this->account_overrides ?? [] as $o) {
            if ($o['exclude'] ?? false) {
                $excluded[$o['row']] = $o['reason'];
                continue;
            }
            if (isset($o['line_key']) || isset($o['shares'])) {
                $overrides[$o['row']] = $o['line_key'] ?? $o['shares'];
            }
            if (isset($o['category'])) {
                $categories[$o['row']] = $o['category'];
            }
            if (! empty($o['tags'])) {
                $tags[$o['row']] = $o['tags'];
            }
        }

        return ['overrides' => $overrides, 'excluded' => $excluded, 'categories' => $categories, 'tags' => $tags, 'inputs' => $this->inputs ?? [],
            'adjustments' => $this->adjustments ?? [], 'decisions' => $this->movement_decisions ?? [], 'zakat_tax' => $this->zakat_tax ?? []];
    }

    /** sha1 of the report's own data: what the user entered and decided, its period, company and prior-year choice. */
    public function inputsHash(): string
    {
        return sha1(json_encode([$this->company_id, $this->prior_report_id, $this->prior_run_id, $this->template_version,
            $this->period_start?->format('Y-m-d'), $this->period_end?->format('Y-m-d'), $this->comparative_start?->format('Y-m-d'), $this->comparative_end?->format('Y-m-d'),
            $this->inputs, $this->account_overrides, $this->adjustments, $this->movement_decisions, $this->zakat_tax, $this->notes, $this->acknowledgements, $this->prior_figures]));
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FinancialReportVersion::class, 'report_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function priorReport(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_report_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ClassificationRun::class, 'classification_run_id');
    }
}
