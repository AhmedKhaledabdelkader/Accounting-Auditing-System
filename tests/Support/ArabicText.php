<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Lang;

/**
 * What a reader sees, and the codes in it (PRs 6–13): no check code, severity, status, origin, layout, group, line key,
 * input key or other identifier may be visible. Shared by the server-rendered page tests and the browser tests.
 */
trait ArabicText
{
    /** The text a reader sees: no scripts, styles, tags or attributes. */
    protected function visibleText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Codes found in a page's visible text. */
    protected function codesIn(string $html): array
    {
        return $this->codesInText($this->visibleText($html));
    }

    /** Codes found in a text. */
    protected function codesInText(string $text): array
    {
        $labels = Lang::get('statements', [], 'ar');
        $codes = [];
        $words = [];
        foreach (['checks', 'severities', 'statuses', 'origins', 'layouts', 'groups', 'missing_reasons', 'unmapped_reasons', 'prior_sources', 'scopes',
            'schedules', 'schedule_rows', 'schedule_columns', 'suggestion_statuses', 'tags', 'notes', 'policies', 'visibility', 'going_concern',
            'blocking', 'version_kinds', 'build_up'] as $set) {
            $words = [...$words, ...array_map('strval', array_keys($labels[$set]))];
        }
        foreach ($labels['categories'] as $categories) {
            $words = [...$words, ...array_keys($categories)];
        }
        $words = [...$words, 'liability', 'equity', 'possible_parent', 'partners_current', 'confirm', 'edit', 'reject', 'reset', 'undefined', 'null', 'NaN'];
        foreach (array_unique($words) as $word) {
            if (preg_match('/(?<![\p{L}\d_.])'.preg_quote($word, '/').'(?![\p{L}\d_]|\.[a-z])/u', $text)) {
                $codes[] = $word;
            }
        }
        // Line keys, input keys and any snake_case identifier.
        preg_match_all('/\b(?:sofp|is|zakat|oci|ppe|loans|cf|soce|cash_flow)\.[a-z_.]+\b|\b[a-z]+_[a-z_]+\b/', $text, $m);

        return array_values(array_unique([...$codes, ...$m[0]]));
    }
}
