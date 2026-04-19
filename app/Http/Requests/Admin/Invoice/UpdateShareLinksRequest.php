<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShareLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sharing_enabled' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sharing_enabled.required' => 'The sharing enabled field is required.',
            'sharing_enabled.boolean'  => 'The sharing enabled field must be a boolean.',
        ];
    }
}
