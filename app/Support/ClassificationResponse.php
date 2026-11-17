<?php

namespace App\Support;

use App\Http\Resources\ClassifiedAccountResource;
use App\Services\TrialBalanceTotals;

/**
 * The {data, meta} payload returned by the sync endpoint and stored for queued runs.
 *
 * Stored: every message in Arabic where it always was, with its phrase beside it (`*_phrase`, `*_phrases`). The API
 * (present()) drops the phrases and adds each message in the request's language as `*_text`; a result stored before
 * phrases gets its Arabic there.
 */
class ClassificationResponse
{
    public static function make(array $result): array
    {
        $rows = $result['rows'];

        return [
            'data' => array_map(fn ($row) => (new ClassifiedAccountResource($row))->resolve(), $rows),
            'meta' => [
                'total'          => count($rows),
                'needs_review'   => count(array_filter($rows, fn ($r) => $r['needs_review'])),
                'avg_confidence' => round(collect($rows)->avg('classification_confidence'), 1),
                'skipped_rows'   => $result['skipped_rows'],
                // Some account rows came back unclassified (a batch failed, or the AI left them out): not a complete result.
                'partial'        => $result['unclassified']['rows'] > 0,
                'unclassified'   => $result['unclassified'],
                'groups'         => $result['groups'],
                // Debit − credit per group (parents left out): all zero, the trial balance balances.
                'balance'        => array_map(fn ($s) => round($s[0] - $s[1], 2), TrialBalanceTotals::sums($rows, $result['groups'])),
                'warnings'       => $result['warnings'],
                'warnings_phrases' => $result['warnings_phrases'] ?? null,
                'info'           => $result['info'],
                'info_phrases'   => $result['info_phrases'] ?? null,
                // Rows whose cached sub-class was re-decided by the balance side, per pair (config side_pairs).
                'side_flips'     => $result['side_flips'] ?? [],
                // Code groups worth marking as one kind of account (ClassificationRules::proposals): the user decides.
                'code_group_proposals' => $result['code_group_proposals'] ?? [],
                'other_sheets'   => $result['other_sheets'],
                'adjustments'    => $result['adjustments'],
                // Which AI produced this result, and the tokens it used (input excludes cache writes and reads).
                'provider'       => $result['provider'] ?? null,
                'model'          => $result['model'] ?? null,
                'usage'          => $result['usage'] ?? null,
            ],
        ];
    }

    /** A stored result as the API returns it: phrases out, `*_text` in the request's language in. */
    public static function present(?array $stored): ?array
    {
        if ($stored === null) {
            return null;
        }
        $stored['data'] = array_map([self::class, 'row'], $stored['data'] ?? []);
        if (isset($stored['meta'])) {
            $meta = $stored['meta'];
            foreach (['warnings', 'info'] as $list) {
                if (array_key_exists($list, $meta)) {
                    $meta[$list.'_text'] = Phrase::localizeList((array) $meta[$list], $meta[$list.'_phrases'] ?? null);
                }
                unset($meta[$list.'_phrases']);
            }
            $stored['meta'] = $meta;
        }

        return $stored;
    }

    /** One stored row as the API returns it. */
    public static function row(array $row): array
    {
        $row['data_flags'] = array_map(function ($f) {
            $f['message_text'] = Phrase::localize($f['message'] ?? '', $f['message_phrase'] ?? null);
            unset($f['message_phrase']);

            return $f;
        }, $row['data_flags'] ?? []);
        $row['classification_reason_text'] = Phrase::localize($row['classification_reason'] ?? null, $row['classification_reason_phrase'] ?? null);
        $row['note_text'] = Phrase::localize($row['note'] ?? '', $row['note_phrase'] ?? null);
        unset($row['classification_reason_phrase'], $row['note_phrase']);

        return $row;
    }
}
