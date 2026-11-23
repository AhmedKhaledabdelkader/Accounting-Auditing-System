<?php

namespace App\Services\Engagement;

use App\Models\AdjustingEntry;

/**
 * The adjusted trial balance: the approved one (a classified run, never changed) plus the adjusting entries posted on it. The
 * statements and zakat read the run through ReportSource::result(), which adds the posted entries here: each line on its row's
 * adjustment columns and its closing balance. A file without adjustment columns gets them (the classifier's copies are cleared
 * first, so nothing counts twice). Taking an entry back (unposting) gives the statements back as they were.
 */
final class AdjustedTrialBalance
{
    /** @return list<AdjustingEntry> the posted entries on the run, with their lines */
    public static function posted(string $runId): array
    {
        if ($runId === '') {
            return [];
        }
        try {
            return AdjustingEntry::withoutGlobalScopes()->with('lines')->where('classification_run_id', $runId)->where('status', AdjustingEntry::POSTED)
                ->orderBy('number')->get()->all();
        } catch (\Illuminate\Database\QueryException) {
            return [];   // no database (a run read on its own, in a unit test): nothing posted
        }
    }

    /** What the posted entries are, for the run's fingerprint: a post or an unpost changes it (the report is then out of date). */
    public static function fingerprint(string $runId): string
    {
        $parts = [];
        foreach (self::posted($runId) as $e) {
            foreach ($e->lines as $l) {
                $parts[] = $e->id.':'.$l->row.':'.$l->debit.':'.$l->credit;
            }
        }

        return $parts === [] ? '' : sha1(implode('|', $parts));
    }

    /** The run's result with the posted entries added to its rows. */
    public static function apply(string $runId, array $result): array
    {
        $entries = self::posted($runId);
        if ($entries === [] || ! is_array($result['data'] ?? null)) {
            return $result;
        }
        $groups = (array) ($result['meta']['groups'] ?? []);
        $index = [];
        foreach ($result['data'] as $i => $row) {
            $index[(int) ($row['row'] ?? -1)] = $i;
            if (! in_array('adjustment', $groups, true)) {
                $result['data'][$i]['adjustment_debit'] = 0.0;
                $result['data'][$i]['adjustment_credit'] = 0.0;
            }
        }
        if (! in_array('adjustment', $groups, true)) {
            $result['meta']['groups'] = [...$groups, 'adjustment'];
        }
        foreach ($entries as $e) {
            foreach ($e->lines as $l) {
                $i = $index[(int) $l->row] ?? null;
                if ($i === null) {
                    continue;
                }
                $r = &$result['data'][$i];
                $adj = (float) $r['adjustment_debit'] - (float) $r['adjustment_credit'] + $l->debit - $l->credit;
                [$r['adjustment_debit'], $r['adjustment_credit']] = [max($adj, 0.0), max(-$adj, 0.0)];
                $closing = (float) ($r['closing_debit'] ?? 0) - (float) ($r['closing_credit'] ?? 0) + $l->debit - $l->credit;
                [$r['closing_debit'], $r['closing_credit']] = [round(max($closing, 0.0), 2), round(max(-$closing, 0.0), 2)];
                unset($r);
            }
        }

        return $result;
    }
}
