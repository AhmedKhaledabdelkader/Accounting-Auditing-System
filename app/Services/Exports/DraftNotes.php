<?php

namespace App\Services\Exports;

use Carbon\CarbonImmutable;

/**
 * What a draft's own page says, in the Word and PDF files (PRs 15, 16): that it is a draft, what still blocks a final, the
 * missing figures and the checks it fails (the same list as the xlsx's draft sheet).
 */
final class DraftNotes
{
    /**
     * @param string $title the page's title: `title_notes` (the notes alone) or `title` (statements and notes)
     * @return list<array{0: string, 1: string}> [text, style]: title, heading or text
     */
    public static function lines(array $p, string $savedAt, string $title = 'title_notes'): array
    {
        $t = fn (string $k, array $r = []) => __("exports.draft.{$k}", $r, 'ar');
        $labels = new StateLabels($p);
        $lines = [[$t($title), 'title'], [$t('version', ['n' => $p['version'] ?? '', 'date' => CarbonImmutable::parse($savedAt)->format('Y-m-d H:i')]), 'text']];
        $blocking = $p['blocking'] ?? [];
        $lines[] = [$blocking ? $t('blocking') : $t('nothing_blocks'), 'heading'];
        foreach ($blocking as $b) {
            $lines[] = ["• {$b['label_ar']} ({$b['count']})", 'text'];
        }
        $lines[] = [$t('missing_title_notes'), 'heading'];
        foreach ($p['missing'] ?? [] as $m) {
            [$what, $why] = $labels->missing($m);
            $lines[] = ["• {$what}: {$why}", 'text'];
        }
        if (($p['missing'] ?? []) === []) {
            $lines[] = [$t('missing_none'), 'text'];
        }
        $lines[] = [$t('checks_title'), 'heading'];
        $failing = array_values(array_filter($p['checks'] ?? [], fn ($c) => $c['severity'] === 'error' || ($c['severity'] === 'warning' && ! ($c['acknowledged'] ?? false))));
        usort($failing, fn ($a, $b) => ($a['severity'] === 'error' ? 0 : 1) <=> ($b['severity'] === 'error' ? 0 : 1));
        foreach ($failing as $c) {
            $title = $c['title_ar'].(($c['scope'] ?? 'current') === 'comparative' ? ' ('.StateLabels::name('scopes.comparative').')' : '');
            $lines[] = ['• '.$title.' — '.(StateLabels::name("severities.{$c['severity']}") ?? '').': '.$c['message_ar'], 'text'];
        }
        if ($failing === []) {
            $lines[] = [$t('checks_none'), 'text'];
        }

        return $lines;
    }
}
