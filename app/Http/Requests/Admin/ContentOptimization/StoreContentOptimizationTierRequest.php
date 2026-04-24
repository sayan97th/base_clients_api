<?php

namespace App\Http\Requests\Admin\ContentOptimization;

use Illuminate\Foundation\Http\FormRequest;

class StoreContentOptimizationTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'               => ['required', 'string', 'max:100', 'unique:content_optimization_tiers,id', 'regex:/^[a-z0-9_]+$/'],
            'label'            => ['required', 'string', 'max:255'],
            'word_count_range' => ['required', 'string', 'max:255'],
            'turnaround_days'  => ['required', 'integer', 'min:1'],
            'price'            => ['required', 'numeric', 'gt:0'],
            'is_active'        => ['required', 'boolean'],
            'is_most_popular'  => ['required', 'boolean'],
            'max_quantity'     => ['required', 'nullable', 'integer', 'min:1'],
            'is_hidden'        => ['required', 'boolean'],
            'sort_order'       => ['required', 'integer', 'min:0'],
        ];
    }
}
