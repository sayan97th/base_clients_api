<?php

namespace App\Http\Requests\Admin\PremiumMentions;

use Illuminate\Foundation\Http\FormRequest;

class StorePremiumMentionsPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'max:255'],
            'price_per_month'      => ['required', 'numeric', 'min:0'],
            'total_placements'     => ['required', 'integer', 'min:1'],
            'exclusive_placements' => ['required', 'integer', 'min:0'],
            'core_placements'      => ['required', 'integer', 'min:0'],
            'support_placements'   => ['required', 'integer', 'min:0'],
            'best_for'             => ['required', 'string', 'max:500'],
            'tagline'              => ['required', 'string', 'max:255'],
            'is_most_popular'      => ['required', 'boolean'],
            'is_active'            => ['required', 'boolean'],
            'sort_order'           => ['required', 'integer', 'min:0'],
        ];
    }
}
