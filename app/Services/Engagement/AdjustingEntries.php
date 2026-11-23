<?php

namespace App\Services\Engagement;

use App\Models\AdjustingEntry;
use App\Models\AuditLog;
use App\Models\ClassificationRun;
use App\Models\Company;
use App\Models\Misstatement;
use App\Models\User;
use App\Services\Audit\Materiality;
use App\Services\Audit\RevenueSampling;
use App\Support\ImportNormalizer;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * The adjusting entries of a client's year (step 10), on its approved trial balance (the year's run, never changed): each
 * balanced (debit = credit, or it isn't saved), on accounts of the trial balance; proposed, then accepted by the client and
 * posted (the adjusted trial balance: the statements and zakat change), or rejected (it goes to the misstatements, ISA 450).
 * Unposting takes it back out. Entries read from a file (Excel or CSV) are matched to the accounts by number, then by name,
 * checked for balance and saved as proposed; one unbalanced or on an unknown account is listed with why, never saved.
 */
final class AdjustingEntries
{
    /** The year's trial balance the entries sit on (the run of the year's statements, else the latest classified). */
    public static function run(Company $company, int $year): ?ClassificationRun
    {
        return Materiality::run($company, Materiality::report($company, $year));
    }

    /** @return array<int, array> the trial balance's accounts by row (row, number, name, side, balance, main_class) */
    public static function accounts(?ClassificationRun $run): array
    {
        $out = [];
        foreach ((array) ($run?->result['data'] ?? []) as $r) {
            if (($r['row_type'] ?? 'account') !== 'account' || trim((string) ($r['account_name'] ?? '')) === '') {
                continue;
            }
            $out[(int) $r['row']] = ['row' => (int) $r['row'], 'number' => isset($r['account_number']) ? trim((string) $r['account_number']) : null,
                'name' => trim((string) $r['account_name']), 'main_class' => $r['main_class'] ?? null, 'statement' => $r['financial_statement'] ?? null,
                'balance' => round((float) ($r['closing_debit'] ?? 0) - (float) ($r['closing_credit'] ?? 0), 2)];
        }

        return $out;
    }

    /**
     * Checks an entry's lines: each on an account of the trial balance, debit or credit (not both), and debit = credit.
     *
     * @param  list<array{row: mixed, debit: mixed, credit: mixed}>  $lines
     * @return array{lines: list<array>, error: ?string, params: array}
     */
    public static function check(array $lines, array $accounts): array
    {
        $out = [];
        foreach ($lines as $l) {
            $debit = round((float) ($l['debit'] ?? 0), 2);
            $credit = round((float) ($l['credit'] ?? 0), 2);
            if (($l['row'] ?? null) === null || ($l['row'] === '' && $debit == 0 && $credit == 0)) {
                continue;
            }
            $a = $accounts[(int) $l['row']] ?? null;
            if ($a === null) {
                return ['lines' => [], 'error' => 'unknown_account', 'params' => ['account' => (string) ($l['label'] ?? $l['row'])]];
            }
            if ($debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0) || ($debit == 0 && $credit == 0)) {
                return ['lines' => [], 'error' => 'bad_line', 'params' => ['account' => $a['name']]];
            }
            $out[] = ['row' => $a['row'], 'account_code' => $a['number'], 'account_name' => $a['name'], 'debit' => $debit, 'credit' => $credit];
        }
        if (count($out) < 2) {
            return ['lines' => [], 'error' => 'two_lines', 'params' => []];
        }
        $d = round(array_sum(array_column($out, 'debit')), 2);
        $c = round(array_sum(array_column($out, 'credit')), 2);
        if (abs($d - $c) > 0.005) {
            return ['lines' => [], 'error' => 'unbalanced', 'params' => ['debit' => number_format($d, 2), 'credit' => number_format($c, 2)]];
        }

        return ['lines' => $out, 'error' => null, 'params' => []];
    }

    /** Saves a checked entry as proposed. */
    public static function create(Company $company, int $year, ClassificationRun $run, array $lines, string $description, string $type, ?User $user, array $source = []): AdjustingEntry
    {
        $number = (int) AdjustingEntry::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->max('number') + 1;
        $entry = AdjustingEntry::create(['office_id' => $company->office_id, 'company_id' => $company->id, 'year' => $year, 'classification_run_id' => $run->id,
            'number' => $number, 'type' => $type, 'description' => $description, 'status' => AdjustingEntry::PROPOSED, 'created_by' => $user?->id,
            'source_type' => $source['type'] ?? 'manual', 'source_ref' => $source['ref'] ?? null, 'source_file' => $source['file'] ?? null, 'source_page' => $source['page'] ?? null]);
        $entry->lines()->createMany($lines);
        AuditLog::record($company, $year, 'create', 'adjusting_entry', $entry->id, null, ['number' => $number, 'description' => $description, 'lines' => $lines]);

        return $entry;
    }

    /**
     * Moves an entry: accept (the client agrees), post (into the adjusted trial balance), unpost (back to accepted), reject
     * (a misstatement), reopen (a rejected one back to proposed; its misstatement goes).
     *
     * @return ?string why it can't (a key of engagement.entries.errors)
     */
    public static function move(Company $company, AdjustingEntry $entry, string $action, ?User $user): ?string
    {
        $from = $entry->status;
        $to = match ([$action, $from]) {
            ['accept', AdjustingEntry::PROPOSED] => AdjustingEntry::ACCEPTED,
            ['post', AdjustingEntry::PROPOSED], ['post', AdjustingEntry::ACCEPTED] => AdjustingEntry::POSTED,
            ['unpost', AdjustingEntry::POSTED] => AdjustingEntry::ACCEPTED,
            ['reject', AdjustingEntry::PROPOSED], ['reject', AdjustingEntry::ACCEPTED] => AdjustingEntry::REJECTED,
            ['reopen', AdjustingEntry::REJECTED] => AdjustingEntry::PROPOSED,
            default => null,
        };
        if ($to === null) {
            return 'bad_move';
        }
        $entry->status = $to;
        $entry->posted_at = $to === AdjustingEntry::POSTED ? now() : null;
        $entry->posted_by = $to === AdjustingEntry::POSTED ? $user?->id : null;
        $entry->save();
        if ($to === AdjustingEntry::REJECTED) {
            self::toMisstatement($company, $entry, $user);
        } elseif ($from === AdjustingEntry::REJECTED) {
            Misstatement::withoutGlobalScopes()->where('company_id', $company->id)->where('source', 'entry')->where('source_id', $entry->id)->delete();
        }
        AuditLog::record($company, $entry->year, $action, 'adjusting_entry', $entry->id, ['status' => $from], ['status' => $to]);

        return null;
    }

    /** A rejected entry, as an uncorrected misstatement: its amount, its effect on the statements' parts. */
    private static function toMisstatement(Company $company, AdjustingEntry $entry, ?User $user): void
    {
        $accounts = self::accounts(ClassificationRun::withoutGlobalScopes()->find($entry->classification_run_id));
        $effects = ['effect_assets' => 0.0, 'effect_liabilities' => 0.0, 'effect_equity' => 0.0, 'effect_pl' => 0.0];
        foreach ($entry->lines()->get() as $l) {
            $a = $accounts[$l->row] ?? null;
            $net = $l->debit - $l->credit;
            if (($a['statement'] ?? null) === 'قائمة الدخل') {
                $effects['effect_pl'] -= $net;   // a debit to an expense lowers the profit
            } elseif (($a['balance'] ?? 0) >= 0 && ! preg_match('/رأس المال|احتياطي|المبق|حقوق|جاري الشركاء/u', (string) ($a['main_class'] ?? ''))) {
                $effects['effect_assets'] += $net;
            } elseif (preg_match('/رأس المال|احتياطي|المبق|حقوق|جاري الشركاء/u', (string) ($a['main_class'] ?? ''))) {
                $effects['effect_equity'] -= $net;
            } else {
                $effects['effect_liabilities'] -= $net;
            }
        }
        Misstatement::withoutGlobalScopes()->updateOrCreate(['company_id' => $company->id, 'source' => 'entry', 'source_id' => $entry->id],
            ['office_id' => $company->office_id, 'year' => $entry->year, 'kind' => 'factual', 'created_by' => $user?->id, 'corrected' => false,
                'description' => __('engagement.misstatements.from_entry', ['number' => $entry->number, 'description' => $entry->description]),
                'amount' => $entry->total() ?: (float) $entry->lines()->sum('debit')] + array_map(fn ($v) => round($v, 2), $effects));
    }

    /**
     * Reads entries from a spreadsheet (Excel or CSV): a row per line with the entry's number, the account (number and/or
     * name), debit, credit and a description. Each entry is matched and checked; the good ones are saved as proposed.
     *
     * @return array{saved: list<int>, refused: list<array{entry: string, reason: string}>}
     */
    public static function import(Company $company, int $year, ClassificationRun $run, string $path, string $fileName, ?User $user): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);
        $patterns = ['entry' => '/رقم\s*القيد|القيد|entry|journal|je/iu', 'code' => '/رقم\s*الحساب|كود|code|account\s*(no|number)/iu',
            'name' => '/اسم\s*الحساب|الحساب|account/iu', 'debit' => '/مدين|debit/iu', 'credit' => '/دائن|credit/iu', 'description' => '/البيان|الوصف|شرح|description|memo/iu'];
        $cols = [];
        $headerAt = null;
        foreach (array_slice($rows, 0, 15, true) as $i => $r) {
            $found = [];
            foreach ($r as $c => $v) {
                foreach ($patterns as $k => $p) {
                    if (! isset($found[$k]) && is_string($v) && preg_match($p, trim($v))) {
                        $found[$k] = $c;
                        break;
                    }
                }
            }
            if (isset($found['debit'], $found['credit']) && (isset($found['code']) || isset($found['name']))) {
                [$cols, $headerAt] = [$found, $i];
                break;
            }
        }
        if ($headerAt === null) {
            return ['saved' => [], 'refused' => [['entry' => '—', 'reason' => __('engagement.entries.errors.no_header')]]];
        }
        $accounts = self::accounts($run);
        $byCode = $byName = [];
        foreach ($accounts as $a) {
            if ($a['number'] !== null && $a['number'] !== '') {
                $byCode[$a['number']] = $a['row'];
            }
            $byName[ImportNormalizer::text($a['name'])] = $a['row'];
        }
        $groups = [];
        $current = null;
        foreach (array_slice($rows, $headerAt + 1, null, true) as $i => $r) {
            $code = trim((string) ($r[$cols['code'] ?? -1] ?? ''));
            $name = trim((string) ($r[$cols['name'] ?? -1] ?? ''));
            $debit = (float) str_replace(',', '', (string) ($r[$cols['debit']] ?? 0));
            $credit = (float) str_replace(',', '', (string) ($r[$cols['credit']] ?? 0));
            if ($code === '' && $name === '' && $debit == 0 && $credit == 0) {
                continue;
            }
            $no = trim((string) ($r[$cols['entry'] ?? -1] ?? ''));
            $current = $no !== '' ? $no : ($current ?? '1');
            $row = $byCode[$code] ?? $byName[ImportNormalizer::text($name)] ?? null;
            $groups[$current]['lines'][] = ['row' => $row ?? '?', 'label' => trim($code.' '.$name), 'debit' => $debit, 'credit' => $credit];
            $groups[$current]['description'] ??= trim((string) ($r[$cols['description'] ?? -1] ?? '')) ?: null;
            $groups[$current]['line'] ??= $i + 1;
        }
        $saved = $refused = [];
        foreach ($groups as $no => $g) {
            $unknown = array_filter($g['lines'], fn ($l) => $l['row'] === '?');
            if ($unknown !== []) {
                $refused[] = ['entry' => (string) $no, 'reason' => __('engagement.entries.errors.unknown_account', ['account' => reset($unknown)['label']])];
                continue;
            }
            $check = self::check($g['lines'], $accounts);
            if ($check['error'] !== null) {
                $refused[] = ['entry' => (string) $no, 'reason' => __("engagement.entries.errors.{$check['error']}", $check['params'])];
                continue;
            }
            $saved[] = self::create($company, $year, $run, $check['lines'], $g['description'] ?? __('engagement.entries.imported', ['number' => $no]), 'adjusting', $user,
                ['type' => 'import', 'ref' => (string) $no, 'file' => $fileName, 'page' => $g['line']])->id;
        }

        return ['saved' => $saved, 'refused' => $refused];
    }
}
