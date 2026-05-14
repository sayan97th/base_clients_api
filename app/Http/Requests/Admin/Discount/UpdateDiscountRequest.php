<?php

namespace App\Http\Requests\Admin\Discount;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['sometimes', 'string', 'max:100'],
            'description'     => ['sometimes', 'nullable', 'string', 'max:500'],
            'discount_type'   => ['sometimes', 'string', 'in:bulk'],
            'discount_rate'   => ['sometimes', 'numeric', 'min:0.01', 'max:100'],
            'min_quantity'    => ['sometimes', 'integer', 'min:1'],
            'applies_to'      => ['sometimes', 'string', 'in:link_building,new_content,content_optimization,content_brief,all'],
            'is_active'       => ['sometimes', 'boolean'],
            'dr_tier_ids'     => ['sometimes', 'nullable', 'array'],
            'dr_tier_ids.*'   => ['string', 'exists:dr_tiers,id'],
        ];
    }
}
