<?php

namespace App\Services\Statements;

use App\Models\ClassificationRun;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;

/**
 * A report's classified run (plan §4.2 step 1): whether it can feed a report, its fingerprint, and what it gives the
 * statements (layout profile and line mapping), always read from the run as it is now.
 */
final class ReportSource
{
    public function __construct(private TaxonomyRepositoryInterface $taxonomy, private PeriodicInventoryPairs $pairs)
    {
    }

    /** Why the run cannot feed a report (in the current language), or null when it can: only a finished run with a result. */
    public function unusable(ClassificationRun $run): ?string
    {
        return match (true) {
            $run->status !== 'done'                                                   => match ($run->status) {
                'needs_confirmation' => __('report_messages.run.needs_confirmation'),
                'queued', 'running'  => __('report_messages.run.running'),
                default              => __('report_messages.run.not_done', ['status' => $run->status]),
            },
            ! is_array($run->result) || ! is_array($run->result['data'] ?? null)      => __('report_messages.run.no_result'),
            default                                                                  => null,
        };
    }

    /** sha1 of the run's stored result: a later correction of a row changes it (run_changed). */
    public function hash(ClassificationRun $run): ?string
    {
        $raw = $run->getRawOriginal('result');

        return $raw === null ? null : sha1($raw);
    }

    /**
     * The run's result with every row's classes under their current names: a run stored before a rename on the taxonomy
     * page keeps the old names (a record), and its rows resolve through the former names (TaxonomyRepository::resolveSub).
     * The main class follows the sub class (its main class today); a name that is neither current nor former stays as is.
     */
    public function result(ClassificationRun $run): array
    {
        $result = $run->result;
        foreach ($result['data'] ?? [] as $i => $row) {
            $sub  = $this->taxonomy->resolveSub($row['sub_class'] ?? null);
            $main = $sub !== null ? $this->taxonomy->find($sub)[1] : $this->taxonomy->resolveMain($row['main_class'] ?? null);
            if ($sub !== null && $sub !== $row['sub_class']) {
                $result['data'][$i]['sub_class'] = $sub;
            }
            if ($main !== null && $main !== ($row['main_class'] ?? null)) {
                $result['data'][$i]['main_class'] = $main;
            }
        }

        return $result;
    }

    /**
     * The run read with the rules of the report's template version.
     *
     * @param array $options LineMapper options (overrides, partners_current)
     * @return array{catalogue: LineCatalogue, profile: LayoutProfile, mapping: array, rows: list<array>} rows: the result's rows, current names
     */
    public function read(ClassificationRun $run, string $templateVersion, array $options = []): array
    {
        $result    = $this->result($run);
        $catalogue = new LineCatalogue($templateVersion);
        $profile   = LayoutProfile::fromResult($result, $this->taxonomy, $catalogue, array_keys($options['excluded'] ?? []));
        $mapper    = new LineMapper($catalogue, $this->pairs);

        return ['catalogue' => $catalogue, 'profile' => $profile, 'mapping' => $mapper->map($result['data'], $profile, $options), 'rows' => $result['data']];
    }
}
