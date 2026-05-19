<?php

namespace App\Http\Requests\Admin\Resource;

use App\Models\Resource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'           => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'category'        => ['required', Rule::in(Resource::CATEGORIES)],
            'status'          => ['required', Rule::in(Resource::STATUSES)],
            'is_hidden'       => ['nullable', 'boolean'],
            'client_ids'      => ['nullable', 'array'],
            'client_ids.*'    => ['integer', 'exists:users,id'],
        ];
    }
}
