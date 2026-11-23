<?php

namespace App\Casts;

use App\Services\Statements\PriorPdf\Sections;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A report's prior-year part (`prior_figures`, `prior_upload`): JSON like the `array` cast, with the reading of a PDF of
 * last year's statements kept up to date with the reader's later fixes that need no new reading (Sections::nestLetters:
 * the lettered parts «أ- …», «ب- …» of a note inside it), wherever the file sits (`file` in use, or the upload itself).
 */
final class PriorFile implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        $data = is_array($value) ? $value : json_decode((string) $value, true);
        if (! is_array($data)) {
            return null;
        }
        if (isset($data['document']['sections'])) {
            $data['document']['sections'] = Sections::nestLetters($data['document']['sections']);
        }
        if (isset($data['file']['document']['sections'])) {
            $data['file']['document']['sections'] = Sections::nestLetters($data['file']['document']['sections']);
        }

        return $data;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($value);
    }
}
