<?php

namespace App\Http\Requests\Admin\DrTier;

use Illuminate\Foundation\Http\FormRequest;

class StoreDrTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label'          => ['required', 'string', 'max:100'],
            'traffic_range'  => ['nullable', 'string'],
            'word_count'     => ['required', 'integer', 'min:0'],
            'price_per_link' => ['required', 'numeric', 'min:0.01'],
            'is_most_popular' => ['required', 'boolean'],
            'is_active'      => ['required', 'boolean'],
            'max_quantity'   => ['nullable', 'integer', 'min:1'],
        ];
    }
}
