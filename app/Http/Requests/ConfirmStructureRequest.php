<?php

namespace App\Http\Requests;

use App\Services\AccountExtractor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** The column mapping the user confirmed or corrected. The sheet and the other sheets stay as detected. */
class ConfirmStructureRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'header_rows'    => ['present', 'array'],
            'header_rows.*'  => ['integer', 'min:1'],
            'first_data_row' => ['required', 'integer', 'min:1'],
            'columns'        => ['required', 'array'],
            'columns.*'      => ['nullable', 'string', 'regex:/^[A-Z]{1,3}$/'],
            'acknowledge_doubts' => ['sometimes', 'boolean'],   // accept pausing doubts raised by this mapping that weren't shown before
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $unknown = array_diff(array_keys((array) $this->input('columns', [])), AccountExtractor::FIELDS);
            if ($unknown) {
                $validator->errors()->add('columns', 'Unknown fields: '.implode(', ', $unknown).'.');
            }
        }];
    }
}
