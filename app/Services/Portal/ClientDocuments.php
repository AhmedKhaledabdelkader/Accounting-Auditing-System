<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Services\Statements\ArticlesOfAssociation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The two documents that identify a client's company: the commercial registration (read by the AI: the number is the company's
 * identity, one company one client per office) and the articles of association (read by the AI: the partners, their nationality
 * and shares). Used by the client's file pages and by the new-client form.
 */
final class ClientDocuments
{
    /**
     * The AI reads the commercial registration: it must be one, and give its number (ten digits); a number the office already has
     * (another company than $exceptId) is that client already, never a second one.
     *
     * @return array the reader's copy ({number, company_name, activity, ...})
     *
     * @throws ValidationException on `registration_file`
     */
    public function readRegistration(UploadedFile $file, ?string $exceptId = null): array
    {
        set_time_limit(300);
        try {
            $read = app(CommercialRegistrationReader::class)->read($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['registration_file' => __('portal.form.cr_unavailable')]);
        }
        $problem = match (true) {
            ! $read['readable'] => 'cr_unreadable',
            ! $read['is_registration'] => 'cr_not_registration',
            $read['number'] === null => 'cr_no_number',
            default => null,
        };
        if ($problem) {
            throw ValidationException::withMessages(['registration_file' => __("portal.form.{$problem}")]);
        }
        if ($taken = Company::withRegistration($read['number'], $exceptId)) {
            throw ValidationException::withMessages(['registration_file' => __('portal.form.cr_taken', ['number' => $read['number'], 'client' => $taken->clientName(), 'company' => $taken->name_ar])]);
        }

        return $read;
    }

    /** What the registration gave: kept on the company (the file and what was read), and its profile facts filled where still empty. */
    public function keepRegistration(Company $company, UploadedFile $file, array $read): void
    {
        $old = $company->profile['registration']['file'] ?? null;
        $stored = $file->storeAs("registrations/{$company->office_id}/{$company->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
        $old && $old !== $stored && Storage::disk('local')->delete($old);
        $profile = $company->profile ?? [];
        $profile['registration'] = ['file' => $stored, 'name' => $file->getClientOriginalName(), 'read_at' => now()->toIso8601String()]
            + array_intersect_key($read, array_flip(['number', 'company_name', 'unified_number', 'activity', 'city', 'issue_date', 'issue_date_hijri', 'expiry_date',
                'legal_form_text', 'legal_form', 'owner_name']));
        // The legal form the registration prints is the company's (a company or a sole proprietorship); another (joint stock…) leaves it unknown.
        if (isset(Company::LEGAL_FORMS[$read['legal_form'] ?? ''])) {
            $company->legal_form = $read['legal_form'];
            $profile['legal_form_source'] = 'registration';
        }
        $facts = array_filter(['activity' => $read['activity'], 'city' => $read['city'], 'unified_number' => $read['unified_number'], 'cr_date' => $read['issue_date'], 'cr_date_hijri' => $read['issue_date_hijri']]);
        foreach ($facts as $field => $value) {
            if (empty($company->{$field})) {
                $company->{$field} = $value;
            }
        }
        $company->cr_number = $read['number'];
        $company->profile = $profile;
        $company->save();
        Workspace::articlesNotNeeded($company);
    }

    /** The articles of association, read by the AI; a failed reading is told, not thrown (the rest of the work stays). */
    public function keepArticles(Company $company, UploadedFile $file): ?string
    {
        set_time_limit(300);
        try {
            app(ArticlesOfAssociation::class)->store($company, $file);
        } catch (\Throwable $e) {
            report($e);

            return __('portal.form.articles_failed');
        }

        return null;
    }

    /** The registration's company name against the names given: said when it differs, never refused (a name is written many ways). */
    public function nameWarning(Company $company, array $read): ?string
    {
        if ($read['company_name'] && DocumentReviewer::otherCompany(['company_name' => $read['company_name'], 'registration_number' => $read['number']], $company)) {
            return __('portal.form.cr_name_differs', ['name' => $read['company_name']]);
        }

        return null;
    }
}
