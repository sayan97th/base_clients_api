<?php

namespace App\Http\Requests\ContentOptimization;

use App\Models\ContentOptimizationTier;
use Illuminate\Foundation\Http\FormRequest;

class StoreContentOptimizationOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment'                                                   => ['required', 'array'],
            'payment.payment_method_id'                                 => ['required', 'string'],
            'total_amount'                                              => ['required', 'numeric', 'gt:0'],
            'coupon_ids'                                                => ['nullable', 'array'],
            'coupon_ids.*'                                              => ['string', 'exists:coupons,id'],
            'order_title'                                               => ['nullable', 'string', 'max:255'],
            'order_notes'                                               => ['nullable', 'string'],
            'billing'                                                   => ['required', 'array'],
            'billing.company'                                           => ['nullable', 'string', 'max:255'],
            'billing.address'                                           => ['nullable', 'string', 'max:255'],
            'billing.city'                                              => ['nullable', 'string', 'max:100'],
            'billing.state'                                             => ['nullable', 'string', 'max:100'],
            'billing.country'                                           => ['nullable', 'string', 'max:100'],
            'billing.postal_code'                                       => ['nullable', 'string', 'max:20'],
            'items'                                                     => ['required', 'array', 'min:1'],
            'items.*.tier_id'                                           => ['required', 'string', 'exists:content_optimization_tiers,id'],
            'items.*.quantity'                                          => ['required', 'integer', 'min:1'],
            'items.*.unit_price'                                        => ['required', 'numeric', 'min:0'],
            'items.*.intake_rows'                                       => ['nullable', 'array'],
            'items.*.intake_rows.*.primary_keyword'                     => ['required', 'string', 'max:500'],
            'items.*.intake_rows.*.secondary_keywords'                  => ['nullable', 'string', 'max:500'],
            'items.*.intake_rows.*.content_page_url'                    => ['required', 'string', 'max:2000'],
            'items.*.intake_rows.*.notes'                               => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('items', []) as $index => $item) {
                $tier_id  = $item['tier_id'] ?? null;
                $quantity = (int) ($item['quantity'] ?? 0);

                if (!$tier_id) {
                    continue;
                }

                $tier = ContentOptimizationTier::where('id', $tier_id)
                    ->where('is_active', true)
                    ->first();

                if (!$tier) {
                    $validator->errors()->add(
                        "items.{$index}.tier_id",
                        'The selected tier is not available.'
                    );
                    continue;
                }

                if ($tier->max_quantity !== null && $quantity > $tier->max_quantity) {
                    $validator->errors()->add(
                        "items.{$index}.quantity",
                        "Quantity exceeds the maximum allowed ({$tier->max_quantity}) for this tier."
                    );
                }
            }
        });
    }
}
