<?php

namespace App\Services\Engagement;

use App\Models\AuditLog;
use App\Models\AuditPlan;
use App\Models\AuditRisk;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The risk register of a client's year (step 5, ISA 315 and 330): the risks of material misstatement, each on an area of the
 * audit program (or the statements as a whole), with its assertions, likelihood and magnitude (the inherent risk), whether it
 * is significant or a fraud risk, and the area's procedures that answer it.
 *
 * Suggested from the year's figures (left «suggested» for the accountant to accept or dismiss): the presumed fraud risks of
 * ISA 240 (revenue recognition, management override), a loss or a negative equity (going concern, ISA 570), related parties
 * (ISA 550), and the balances at or above the performance materiality that rest on an estimate (ISA 540).
 * A significant risk with no test of details (a `detail` or `sample` procedure) linked blocks the audit plan (Engagement).
 * The highest rating of an area's risks gives its samples' confidence factor by default (high 3.0, medium 2.3, low 1.6).
 */
final class RiskRegister
{
    /** The assurance (config audit.sampling.factors) a rating asks of a sample. */
    public const ASSURANCE = ['high' => 'high', 'medium' => 'moderate', 'low' => 'low'];

    /**
     * Adds the suggestions the year's figures call for, once each (a suggestion dismissed isn't made again).
     *
     * @param  array  $m  Materiality::build()
     * @return int how many were added
     */
    public static function suggest(Company $company, int $year, array $m, ?User $user): int
    {
        $f = $m['figures'] ?? [];
        $pm = $m['approved']['performance'] ?? $m['performance'];
        $areaAmount = function (string $area) use ($m): float {
            $sum = 0.0;
            foreach ((array) config("audit_procedures.areas.{$area}.lines") as $line) {
                $sum += abs((float) ($m['lines'][$line]['amount'] ?? 0));
            }

            return $sum;
        };
        $money = fn (float $v) => number_format($v, 2);
        $out = [];
        if (($f['revenue'] ?? 0) > 0) {
            $out['revenue_fraud'] = ['revenue', 'assertion', ['occurrence', 'cutoff'], 2, 3, true, true, []];
        }
        $out['management_override'] = ['risk', 'fs', ['occurrence', 'accuracy', 'valuation'], 2, 3, true, true, []];
        $negativeEquity = isset($f['total_equity']) && $f['total_equity'] < 0;
        if (isset($f['net_profit']) && $f['net_profit'] < 0) {
            $out['loss'] = ['completion', 'fs', ['presentation'], 2, $negativeEquity ? 3 : 2, $negativeEquity, false, ['amount' => $money(abs($f['net_profit']))]];
        }
        if ($negativeEquity) {
            $out['negative_equity'] = ['completion', 'fs', ['presentation'], 3, 3, true, false, ['amount' => $money(abs($f['total_equity']))]];
        }
        if (($rp = $areaAmount('related_parties')) > 0.005) {
            $out['related_parties'] = ['related_parties', 'assertion', ['completeness', 'presentation'], 2, 2, false, false, ['amount' => $money($rp)]];
        }
        foreach (['receivables' => ['valuation', 'existence'], 'inventory' => ['existence', 'valuation'], 'ppe' => ['valuation', 'existence'],
            'employee_benefits' => ['valuation', 'completeness'], 'zakat' => ['accuracy', 'valuation']] as $area => $assertions) {
            if ($pm !== null && $pm > 0 && ($amount = $areaAmount($area)) >= $pm) {
                $out["estimate_{$area}"] = [$area, 'assertion', $assertions, 2, 2, false, false, ['amount' => $money($amount)]];
            }
        }

        $known = AuditRisk::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->whereNotNull('source')->pluck('source')->all();
        $added = 0;
        foreach ($out as $source => [$area, $level, $assertions, $likelihood, $magnitude, $significant, $fraud, $params]) {
            if (in_array($source, $known, true)) {
                continue;
            }
            $risk = AuditRisk::create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => $year, 'area' => $area, 'level' => $level,
                'description' => __("engagement.risks.suggestions.{$source}", $params), 'assertions' => $assertions, 'likelihood' => $likelihood, 'magnitude' => $magnitude,
                'inherent_rating' => AuditRisk::rating($likelihood, $magnitude), 'is_significant' => $significant, 'is_fraud' => $fraud,
                'status' => AuditRisk::SUGGESTED, 'source' => $source, 'procedures' => self::defaultProcedures($area, $assertions), 'created_by' => $user?->id]);
            AuditLog::record($company, $year, 'suggest', 'risk', $risk->id, null, $risk->only(['area', 'description', 'inherent_rating']));
            $added++;
        }

        return $added;
    }

    /** The area's procedures that test the risk's assertions in detail: linked to a suggestion at once (the accountant may change them). */
    public static function defaultProcedures(string $area, array $assertions): array
    {
        $codes = [];
        foreach ((array) config("audit_procedures.areas.{$area}.procedures") as $code => [$text, $as, $type]) {
            if (in_array($type, ['detail', 'sample'], true) && array_intersect($as, $assertions) !== []) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /** The procedures of an area (the program's and the accountant's own): code => [text, type]. */
    public static function procedures(string $area, ?AuditPlan $plan): array
    {
        $out = [];
        foreach ((array) config("audit_procedures.areas.{$area}.procedures") as $code => [$text, , $type]) {
            $out[(string) $code] = [$text, $type];
        }
        foreach ((array) ($plan?->procedures['areas'][$area]['custom'] ?? []) as $c) {
            $out[$c['id']] = [$c['text'], $c['type'] ?? 'detail'];
        }

        return $out;
    }

    /**
     * The significant risks accepted with no test of details among their procedures.
     *
     * @param  Collection<int, AuditRisk>  $accepted
     * @return list<AuditRisk>
     */
    public static function unanswered(Collection $accepted, AuditPlan $plan): array
    {
        $out = [];
        foreach ($accepted->where('is_significant', true) as $r) {
            $procs = self::procedures($r->area, $plan);
            $detail = array_filter((array) $r->procedures, fn ($c) => in_array($procs[(string) $c][1] ?? null, ['detail', 'sample'], true));
            if ($detail === []) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** The assurance an area's samples take by default: from its highest accepted risk, else the office's default. */
    public static function assurance(Company $company, int $year, string $area): string
    {
        $ratings = AuditRisk::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->where('area', $area)
            ->where('status', AuditRisk::ACCEPTED)->pluck('inherent_rating')->all();
        foreach (['high', 'medium', 'low'] as $r) {
            if (in_array($r, $ratings, true)) {
                return self::ASSURANCE[$r];
            }
        }

        return (string) config('audit.sampling.default_assurance');
    }

    /** The areas a risk may sit on: the statements as a whole, then the program's areas. */
    public static function areas(): array
    {
        $out = ['fs' => __('engagement.risks.fs_level')];
        foreach ((array) config('audit_procedures.areas') as $k => $a) {
            $out[$k] = $a['code'].' '.$a['title'];
        }

        return $out;
    }
}
