<?php

namespace App\Http\Requests\Admin\ContentBrief;

use Illuminate\Foundation\Http\FormRequest;

class StoreContentBriefTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'              => ['required', 'string', 'max:100', 'unique:content_brief_tiers,id', 'regex:/^[a-z0-9_-]+$/'],
            'label'           => ['required', 'string', 'max:255'],
            'turnaround_days' => ['required', 'integer', 'min:1'],
            'price'           => ['required', 'numeric', 'min:0.01'],
            'is_active'       => ['sometimes', 'boolean'],
            'is_most_popular' => ['sometimes', 'boolean'],
            'max_quantity'    => ['nullable', 'integer', 'min:1'],
            'is_hidden'       => ['sometimes', 'boolean'],
            'sort_order'      => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.regex'   => 'The id may only contain lowercase letters, numbers, hyphens, and underscores.',
            'id.unique'  => 'A tier with this id already exists.',
            'price.min'  => 'The price must be at least 0.01.',
        ];
    }
}
