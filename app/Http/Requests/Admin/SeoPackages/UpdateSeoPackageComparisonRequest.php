<?php

namespace App\Http\Requests\Admin\SeoPackages;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSeoPackageComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows'                  => ['present', 'array'],
            'rows.*.id'             => ['nullable', 'string', 'uuid'],
            'rows.*.label'          => ['required', 'string', 'max:255'],
            'rows.*.sort_order'     => ['required', 'integer', 'min:0'],
            'rows.*.values'         => ['present', 'array'],
            'rows.*.values.*'       => ['nullable', 'string', 'max:255'],
        ];
    }
}
