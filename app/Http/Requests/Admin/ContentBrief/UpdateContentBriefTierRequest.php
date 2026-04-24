<?php

namespace App\Http\Requests\Admin\ContentBrief;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContentBriefTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label'           => ['sometimes', 'string', 'max:255'],
            'turnaround_days' => ['sometimes', 'integer', 'min:1'],
            'price'           => ['sometimes', 'numeric', 'min:0.01'],
            'is_active'       => ['sometimes', 'boolean'],
            'is_most_popular' => ['sometimes', 'boolean'],
            'max_quantity'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_hidden'       => ['sometimes', 'boolean'],
            'sort_order'      => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'price.min' => 'The price must be at least 0.01.',
        ];
    }
}
