<?php

namespace App\Services\Engagement;

use App\Models\AuditPlan;
use App\Models\Company;
use App\Models\Misstatement;
use App\Models\MaterialityVersion;
use App\Services\Audit\RevenueSampling;

/**
 * The summary of misstatements of a client's year (ISA 450): the ones typed, the adjusting entries the client refused (added
 * when refused), and each sample's: the misstatement found in it (factual) and the rest of its projection on the population
 * (projected), added on reading. One below the clearly trivial threshold is listed but left out of the total. The uncorrected
 * total (in absolute amounts: one doesn't hide another) against the overall materiality approved: above it, an unmodified
 * opinion is blocked (Engagement: MISSTATEMENTS_ABOVE_MATERIALITY).
 */
final class Misstatements
{
    /** @return list<array> every misstatement of the year, the samples' among them */
    public static function rows(Company $company, int $year, AuditPlan $plan, ?MaterialityVersion $version): array
    {
        $trivial = $version?->trivial;
        $out = [];
        foreach (Misstatement::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->orderBy('id')->get() as $m) {
            $out[] = ['id' => $m->id, 'source' => $m->source, 'source_id' => $m->source_id, 'kind' => $m->kind, 'description' => $m->description,
                'amount' => $m->amount, 'effect_assets' => $m->effect_assets, 'effect_liabilities' => $m->effect_liabilities, 'effect_equity' => $m->effect_equity,
                'effect_pl' => $m->effect_pl, 'corrected' => $m->corrected, 'prior_period' => $m->prior_period, 'editable' => $m->source === 'manual'];
        }
        $performance = $version?->performance;
        foreach (RevenueSampling::samples($plan->sampling) as $s) {
            $e = RevenueSampling::evaluate($s, (array) ($plan->sales ?? []), $performance, $trivial);
            $revenue = ! str_starts_with((string) ($s['account'] ?? ''), 'tb:') || ($s['tb']['side'] ?? null) === 'credit';
            $name = $s['account_name'] ?? $s['id'];
            foreach ([['factual', $e['found']], ['projected', round($e['projected'] - $e['found'], 2)]] as [$kind, $amount]) {
                if (abs($amount) > 0.005) {
                    $out[] = ['id' => null, 'source' => 'sample', 'source_id' => $s['id'], 'kind' => $kind,
                        'description' => __("engagement.misstatements.from_sample_{$kind}", ['sample' => $s['id'], 'account' => $name]),
                        'amount' => $amount, 'effect_assets' => 0.0, 'effect_liabilities' => 0.0, 'effect_equity' => 0.0,
                        'effect_pl' => $revenue ? -$amount : 0.0, 'corrected' => false, 'prior_period' => false, 'editable' => false];
                }
            }
        }
        foreach ($out as &$r) {
            $r['trivial'] = $trivial !== null && abs($r['amount']) <= $trivial;
        }

        return $out;
    }

    /** @return array{count: int, uncorrected: float, corrected: float, above_materiality: bool, trivial: ?float, overall: ?float, fingerprint: string} */
    public static function totals(Company $company, int $year, AuditPlan $plan, ?MaterialityVersion $version): array
    {
        $rows = self::rows($company, $year, $plan, $version);
        $uncorrected = $corrected = 0.0;
        foreach ($rows as $r) {
            if ($r['trivial']) {
                continue;
            }
            $r['corrected'] ? $corrected += abs($r['amount']) : $uncorrected += abs($r['amount']);
        }

        return ['count' => count($rows), 'uncorrected' => round($uncorrected, 2), 'corrected' => round($corrected, 2),
            'above_materiality' => $version !== null && $uncorrected > $version->overall, 'trivial' => $version?->trivial, 'overall' => $version?->overall,
            'pl_effect' => round(array_sum(array_map(fn ($r) => $r['corrected'] || $r['trivial'] ? 0 : $r['effect_pl'], $rows)), 2),
            'fingerprint' => sha1(json_encode(array_map(fn ($r) => [$r['id'], $r['kind'], $r['amount'], $r['corrected']], $rows)))];
    }
}
