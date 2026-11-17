<?php

namespace App\Services\Portal;

use App\Models\ClassificationRun;
use App\Models\ClientArchive;
use App\Models\ClientFile;
use App\Models\Company;
use App\Models\FinancialReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a client with everything of theirs, for good: the company and its profile, the client's login, every request, file, message
 * and compressed file (and the files on the private disk), the commercial registration and the articles of association, the financial
 * statements (with their versions and the files they were made from) and the trial balances uploaded for the company. What the AI
 * copied from those files, kept by their content, goes too. What is shared by all the offices (the classification memory, the
 * taxonomy, the synonyms) stays: it holds no client.
 */
final class ClientEraser
{
    /**
     * @return array{files: int, statements: int, trial_balances: int, requests: int}
     */
    public function erase(Company $company): array
    {
        $disk = Storage::disk('local');
        $paths = [];
        $dirs = ["client-files/{$company->office_id}/{$company->id}", "client-archives/{$company->office_id}/{$company->id}",
            "registrations/{$company->office_id}/{$company->id}", "articles/{$company->id}", "papers/{$company->office_id}/{$company->id}"];
        foreach (ClientFile::withoutGlobalScopes()->where('company_id', $company->id)->pluck('path') as $p) {
            $paths[] = $p;
        }
        foreach (ClientArchive::withoutGlobalScopes()->where('company_id', $company->id)->pluck('path') as $p) {
            $paths[] = $p;
        }
        foreach (['registration', 'articles'] as $kind) {
            ($file = $company->profile[$kind]['file'] ?? null) && $paths[] = $file;
        }
        $reports = FinancialReport::withoutGlobalScopes()->where('company_id', $company->id)->get(['id', 'classification_run_id', 'prior_upload']);
        foreach ($reports as $r) {
            $dirs[] = "prior-statements/{$r->id}";
            ($path = $r->prior_upload['path'] ?? null) && $paths[] = $path;
        }
        // The trial balances uploaded for this company, unless a statement of another company still stands on one.
        $stillUsed = FinancialReport::withoutGlobalScopes()->where(fn ($q) => $q->where('company_id', '<>', $company->id)->orWhereNull('company_id'))->pluck('classification_run_id')->all();
        $runs = ClassificationRun::withoutGlobalScopes()->where('company_id', $company->id)->whereNotIn('id', $stillUsed)->get(['id', 'file_path']);
        foreach ($runs as $run) {
            $run->file_path && $paths[] = $run->file_path;
        }
        $requests = DB::table('document_requests')->where('company_id', $company->id)->count();

        // What the AI kept of these files is keyed by their content.
        $hashes = [];
        foreach (array_unique($paths) as $p) {
            $disk->exists($p) && $hashes[] = hash_file('sha256', $disk->path($p));
        }

        DB::transaction(function () use ($company, $reports, $runs) {
            // Statements first (a statement stands on its trial balance), then the trial balances, the login, the company (the rest follows).
            FinancialReport::withoutGlobalScopes()->where('company_id', $company->id)->update(['prior_report_id' => null]);
            foreach ($reports as $r) {
                FinancialReport::withoutGlobalScopes()->whereKey($r->id)->delete();
            }
            ClassificationRun::withoutGlobalScopes()->whereIn('id', $runs->pluck('id'))->delete();
            foreach (User::withoutGlobalScopes()->where('company_id', $company->id)->get() as $login) {
                $login->tokens()->delete();
                $login->delete();
            }
            ClassificationRun::withoutGlobalScopes()->where('company_id', $company->id)->update(['company_id' => null]);
            Company::withoutGlobalScopes()->whereKey($company->id)->delete();
        });

        foreach (array_unique($paths) as $p) {
            $disk->delete($p);
        }
        foreach ($dirs as $d) {
            $disk->deleteDirectory($d);
        }
        $cache = (string) config('financial_statements.prior_pdf.cache', storage_path('app/private/prior-statements/cache'));
        foreach (array_unique($hashes) as $h) {
            File::deleteDirectory("{$cache}/{$h}");
        }

        return ['files' => count(array_unique($paths)), 'statements' => $reports->count(), 'trial_balances' => $runs->count(), 'requests' => $requests];
    }
}
