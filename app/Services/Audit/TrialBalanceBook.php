<?php

namespace App\Services\Audit;

use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\FinancialReport;
use App\Services\AmountParser;
use App\Support\ImportNormalizer;

/**
 * The year's trial balance of a client, for matching what its documents and the recalculations show against the books: the run of
 * the year's financial statements, else the client's latest classified trial balance (`latest`: said, since it may be another year's).
 * Balances are net closing ones, debit positive; `natural()` turns one to its side (a provision positive when credit).
 */
final class TrialBalanceBook
{
    /** @param list<array{row: int, number: string, name: string, main: string, sub: string, balance: float}> $accounts */
    private function __construct(public readonly string $runId, public readonly bool $latest, public readonly array $accounts) {}

    public static function for(Company $company, int $year): ?self
    {
        $report = FinancialReport::withoutGlobalScopes()->where('company_id', $company->id)->whereYear('period_end', $year)->latest('updated_at')->first();
        $run = $report ? ClassificationRun::withoutGlobalScopes()->where('status', 'done')->find($report->classification_run_id) : null;
        $latest = false;
        if ($run === null) {
            $run = ClassificationRun::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'done')->latest()->first();
            $latest = true;
        }

        return $run === null ? null : self::fromRows($run->id, $latest, (array) ($run->result['data'] ?? []));
    }

    /** From a run's rows (the accounts only, parents and headings left out). */
    public static function fromRows(string $runId, bool $latest, array $rows): self
    {
        $accounts = [];
        foreach ($rows as $r) {
            if (($r['row_type'] ?? 'account') !== 'account') {
                continue;
            }
            $accounts[] = ['row' => (int) ($r['row'] ?? 0), 'number' => trim((string) ($r['account_number'] ?? '')), 'name' => trim((string) ($r['account_name'] ?? '')),
                'main' => (string) ($r['main_class'] ?? ''), 'sub' => (string) ($r['sub_class'] ?? ''),
                'balance' => round((float) ($r['closing_debit'] ?? 0) - (float) ($r['closing_credit'] ?? 0), 2)];
        }

        return new self($runId, $latest, $accounts);
    }

    /**
     * The accounts classified under any of the classes (a sub class or a main class; a class's words are enough). `subOnly`: the sub
     * class only («مصروفات مدفوعة مقدماً» is also part of a main class «…وأرصدة مدينة أخرى» that holds much more).
     */
    public function in(array $classes, bool $subOnly = false): array
    {
        $wanted = array_values(array_filter(array_map(fn ($c) => ImportNormalizer::text($c), $classes)));
        if ($wanted === []) {
            return [];
        }

        return array_values(array_filter($this->accounts, function ($a) use ($wanted, $subOnly) {
            $sub = ImportNormalizer::text($a['sub']);
            $main = ImportNormalizer::text($a['main']);
            foreach ($wanted as $w) {
                if (($sub !== '' && (str_contains($sub, $w) || str_contains($w, $sub))) || (! $subOnly && $main !== '' && str_contains($main, $w))) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** The total of the accounts, on the given natural side (credit: a credit balance positive). */
    public static function total(array $accounts, ?string $side): float
    {
        return self::natural(array_sum(array_column($accounts, 'balance')), $side);
    }

    public static function natural(float $balance, ?string $side): float
    {
        return round($side === 'credit' ? -$balance : $balance, 2) + 0.0;   // never -0.0
    }

    /**
     * The account a document's balance is about, among the candidates: the one whose number, or a run of 4+ digits of the reference
     * (an IBAN, an account number), its number or name carries; else the one whose name has the counterparty's words; else the only one.
     *
     * @return array{account: ?array, by: string} by: reference | name | only | none
     */
    public static function pick(array $candidates, ?string $reference, ?string $counterparty, bool $onlyIsEnough = true): array
    {
        if ($candidates === []) {
            return ['account' => null, 'by' => 'none'];
        }
        $digits = preg_replace('/\D+/', '', AmountParser::asciiDigits((string) $reference));
        if (strlen($digits) >= 4) {
            foreach ($candidates as $a) {
                $num = preg_replace('/\D+/', '', AmountParser::asciiDigits($a['number']));
                $inName = preg_replace('/\D+/', '', AmountParser::asciiDigits($a['name']));   // «بنك الراجحي 4521»
                if (($num !== '' && $num === $digits) || (strlen($inName) >= 4 && (str_ends_with($digits, $inName) || str_contains($inName, substr($digits, -4))))) {
                    return ['account' => $a, 'by' => 'reference'];
                }
            }
        }
        $words = array_filter(explode(' ', ImportNormalizer::text($counterparty)), fn ($w) => mb_strlen($w) > 2);
        if ($words !== []) {
            $best = null;
            $score = 0.0;
            foreach ($candidates as $a) {
                $s = count(array_intersect($words, explode(' ', ImportNormalizer::text($a['name'])))) / count($words);
                if ($s > $score) {
                    [$best, $score] = [$a, $s];
                }
            }
            if ($best !== null && $score >= 0.5) {
                return ['account' => $best, 'by' => 'name'];
            }
        }

        // One account of the kind: a bank's balance is that account's. A customer's or a supplier's is not the whole of «عملاء».
        return $onlyIsEnough && count($candidates) === 1 ? ['account' => $candidates[0], 'by' => 'only'] : ['account' => null, 'by' => 'none'];
    }
}

