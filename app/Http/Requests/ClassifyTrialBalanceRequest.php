<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClassifyTrialBalanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** A file the accountant picked from the client's uploads (`client_file_id`) stands for an uploaded one. */
    protected function prepareForValidation(): void
    {
        \App\Support\ClientFileSource::attach($this);
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', \App\Support\SpreadsheetFile::rule(), 'max:20480'], 'company_id' => ['nullable', 'uuid', 'exists:companies,id']];
    }
}