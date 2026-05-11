<?php

namespace App\Http\Requests\Admin\NewContent;

use Illuminate\Foundation\Http\FormRequest;

class AddIntakeRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'intake_rows'                           => ['required', 'array', 'min:1'],
            'intake_rows.*.keyword_phrase'          => ['required', 'string', 'max:500'],
            'intake_rows.*.secondary_keywords'      => ['nullable', 'string', 'max:500'],
            'intake_rows.*.type_of_content'         => ['nullable', 'string', 'in:Blog Article,Product Page,Home Page,About Us Page,Other'],
            'intake_rows.*.notes'                   => ['nullable', 'string', 'max:5000'],
        ];
    }
}
