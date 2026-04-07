<?php

namespace App\Http\Requests\Admin\ContentRefreshTier;

use Illuminate\Foundation\Http\FormRequest;

class StoreContentRefreshTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label'            => ['required', 'string', 'max:255'],
            'word_count_range' => ['required', 'string', 'max:100'],
            'turnaround_days'  => ['required', 'integer', 'min:1'],
            'price'            => ['required', 'numeric', 'min:0.01'],
            'is_active'        => ['required', 'boolean'],
            'sort_order'       => ['required', 'integer', 'min:1'],
        ];
    }
}
