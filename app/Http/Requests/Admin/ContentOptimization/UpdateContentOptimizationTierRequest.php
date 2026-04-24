<?php

namespace App\Http\Requests\Admin\ContentOptimization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContentOptimizationTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label'            => ['sometimes', 'string', 'max:255'],
            'word_count_range' => ['sometimes', 'string', 'max:255'],
            'turnaround_days'  => ['sometimes', 'integer', 'min:1'],
            'price'            => ['sometimes', 'numeric', 'gt:0'],
            'is_active'        => ['sometimes', 'boolean'],
            'is_most_popular'  => ['sometimes', 'boolean'],
            'max_quantity'     => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_hidden'        => ['sometimes', 'boolean'],
            'sort_order'       => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
