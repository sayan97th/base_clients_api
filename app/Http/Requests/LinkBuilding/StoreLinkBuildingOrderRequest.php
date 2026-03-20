<?php

namespace App\Http\Requests\LinkBuilding;

use Illuminate\Foundation\Http\FormRequest;

class StoreLinkBuildingOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_title'                       => 'nullable|string|max:255',
            'order_notes'                        => 'nullable|string',
            'total_amount'                       => 'required|numeric|min:0',
            'items'                              => 'required|array|min:1',
            'items.*.dr_tier_id'                 => 'required|string|exists:dr_tiers,id',
            'items.*.quantity'                   => 'required|integer|min:1',
            'items.*.unit_price'                 => 'required|numeric|min:0',
            'items.*.placements'                 => 'required|array|min:1',
            'items.*.placements.*.row_index'     => 'required|integer|min:0',
            'items.*.placements.*.keyword'       => 'nullable|string|max:255',
            'items.*.placements.*.landing_page'  => 'nullable|url|max:2048',
            'items.*.placements.*.exact_match'   => 'required|boolean',
            'billing'                            => 'required|array',
            'billing.company'                    => 'nullable|string|max:255',
            'billing.address'                    => 'nullable|string|max:255',
            'billing.city'                       => 'nullable|string|max:100',
            'billing.state'                      => 'nullable|string|max:100',
            'billing.country'                    => 'nullable|string|max:100',
            'billing.postal_code'                => 'nullable|string|max:20',
            'payment'                            => 'required|array',
            'payment.payment_method_id'          => 'required|string|starts_with:pi_',
            'coupon_id'                          => 'nullable|string|exists:coupons,id',
        ];
    }
}
