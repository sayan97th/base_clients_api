<?php

namespace App\Http\Requests\Admin\SmeContent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSmeServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label'       => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'price'       => ['sometimes', 'required', 'numeric', 'min:0'],
            'sort_order'  => ['sometimes', 'integer', 'min:0'],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
