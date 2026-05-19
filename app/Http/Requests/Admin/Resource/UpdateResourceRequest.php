<?php

namespace App\Http\Requests\Admin\Resource;

use App\Models\Resource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'           => ['sometimes', 'string', 'max:255'],
            'description'     => ['sometimes', 'nullable', 'string'],
            'category'        => ['sometimes', Rule::in(Resource::CATEGORIES)],
            'status'          => ['sometimes', Rule::in(Resource::STATUSES)],
            'is_hidden'       => ['sometimes', 'boolean'],
            'client_ids'      => ['sometimes', 'nullable', 'array'],
            'client_ids.*'    => ['integer', 'exists:users,id'],
        ];
    }
}
