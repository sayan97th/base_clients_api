<?php

namespace App\Http\Requests\Admin\Discount;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:100'],
            'description'     => ['nullable', 'string', 'max:500'],
            'discount_type'   => ['required', 'string', 'in:bulk'],
            'discount_rate'   => ['required', 'numeric', 'min:0.01', 'max:100'],
            'min_quantity'    => ['required', 'integer', 'min:1'],
            'applies_to'      => ['required', 'string', 'in:link_building,new_content,content_optimization,content_brief,all'],
            'is_active'       => ['boolean'],
            'dr_tier_ids'     => ['nullable', 'array'],
            'dr_tier_ids.*'   => ['string', 'exists:dr_tiers,id'],
        ];
    }
}
