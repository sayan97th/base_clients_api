<?php

namespace App\Http\Requests\Admin\PremiumMentions;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePremiumMentionsPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                 => ['sometimes', 'string', 'max:255'],
            'price_per_month'      => ['sometimes', 'numeric', 'min:0.01'],
            'total_placements'     => ['sometimes', 'integer', 'min:1'],
            'exclusive_placements' => ['sometimes', 'integer', 'min:0'],
            'core_placements'      => ['sometimes', 'integer', 'min:0'],
            'support_placements'   => ['sometimes', 'integer', 'min:0'],
            'best_for'             => ['sometimes', 'nullable', 'string'],
            'tagline'              => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_most_popular'      => ['sometimes', 'boolean'],
            'is_active'            => ['sometimes', 'boolean'],
            'sort_order'           => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
