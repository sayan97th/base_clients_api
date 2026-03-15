<?php

namespace App\Http\Requests\Admin\DrTier;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDrTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dr_label'       => ['sometimes', 'string', 'max:100'],
            'traffic_range'  => ['sometimes', 'nullable', 'string'],
            'word_count'     => ['sometimes', 'integer', 'min:0'],
            'price_per_link' => ['sometimes', 'numeric', 'min:0.01'],
            'is_most_popular' => ['sometimes', 'boolean'],
            'is_active'      => ['sometimes', 'boolean'],
            'is_hidden'      => ['sometimes', 'boolean'],
        ];
    }
}
