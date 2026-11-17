<?php

namespace App\Services\Statements;

/**
 * The notes' movement schedules of a report (PR 9): asset notes, provision roll-forwards and the loans note, with the
 * movement suggestions and what is still missing. Every amount is the trial balance's, a suggestion the user confirms,
 * a sum of those, or the user's own.
 */
final class MovementSchedules
{
    public function __construct(private AssetNotesBuilder $assets, private ProvisionRollforwards $provisions)
    {
    }

    /**
     * @param MovementSuggester $s holds the report's decisions; collects the suggestions and the cells still missing
     * @return array{schedules: list<array>, missing: list<array>} missing: asset-note sides waiting for categories
     */
    public function build(ScheduleContext $ctx, MovementSuggester $s): array
    {
        $schedules = [];
        $missing   = [];
        foreach ($ctx->catalogue->schedules() as $key => $cfg) {
            $schedule = match ($cfg['kind']) {
                'asset'     => $this->assets->build($key, $cfg, $ctx, $s),
                'provision' => $this->provisions->build($key, $cfg, $ctx, $s),
                'loans'     => $this->provisions->loans($cfg, $ctx),
            };
            if ($schedule === null) {
                continue;
            }
            foreach ($schedule['split'] ?? [] as $side => $split) {
                if (! $split) {
                    $missing[] = ['key' => "{$key}.{$side}", 'reason' => 'category_split_required', 'input_key' => null];
                }
            }
            $schedules[] = $schedule;
        }

        return ['schedules' => $schedules, 'missing' => $missing];
    }

    /** One cell of a schedule, or null. */
    public static function cell(array $schedule, string $row, string $column = 'total'): ?Figure
    {
        foreach ($schedule['rows'] as $r) {
            if ($r['key'] === $row) {
                return $r['cells'][$column] ?? null;
            }
        }
        return null;
    }
}
