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
            'items.*.placements.*.landing_page'  => 'nullable|string|max:2048',
            'items.*.placements.*.exact_match'   => 'required|boolean',
            'billing'                            => 'required|array',
            'billing.company'                    => 'nullable|string|max:255',
            'billing.address'                    => 'required|string|max:255',
            'billing.city'                       => 'required|string|max:100',
            'billing.state'                      => 'required|string|max:100',
            'billing.country'                    => 'required|string|max:100',
            'billing.postal_code'                => 'required|string|max:20',
            'payment'                              => 'required|array',
            'payment.type'                         => 'required|string|in:account_balance,credits,saved_card,new_card',
            'payment.payment_profile_id'           => 'required_if:payment.type,saved_card|nullable|integer|exists:payment_methods,id',
            'payment.stripe_payment_method_id'     => 'required_if:payment.type,new_card|nullable|string|starts_with:pm_',
            'payment.save_card'                    => 'sometimes|boolean',
        ];
    }
}
