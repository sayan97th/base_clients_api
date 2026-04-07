<?php

namespace App\Http\Requests\Admin\ContentRefreshTier;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContentRefreshTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label'            => ['sometimes', 'string', 'max:255'],
            'word_count_range' => ['sometimes', 'string', 'max:100'],
            'turnaround_days'  => ['sometimes', 'integer', 'min:1'],
            'price'            => ['sometimes', 'numeric', 'min:0.01'],
            'is_active'        => ['sometimes', 'boolean'],
            'sort_order'       => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
