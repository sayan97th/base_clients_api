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
            'payment_method_id'                                               => ['required', 'string'],
            'total_amount'                                                    => ['required', 'numeric', 'gt:0'],
            'coupon_ids'                                                      => ['nullable', 'array'],
            'coupon_ids.*'                                                    => ['string', 'exists:coupons,id'],
            'order_title'                                                     => ['nullable', 'string', 'max:255'],
            'order_notes'                                                     => ['nullable', 'string'],
            'billing'                                                         => ['required', 'array'],
            'billing.company'                                                 => ['nullable', 'string', 'max:255'],
            'billing.address'                                                 => ['required', 'string', 'max:255'],
            'billing.city'                                                    => ['required', 'string', 'max:100'],
            'billing.state'                                                   => ['required', 'string', 'max:100'],
            'billing.country'                                                 => ['required', 'string', 'max:100'],
            'billing.postal_code'                                             => ['required', 'string', 'max:20'],
            'content_optimization_items'                                      => ['required', 'array', 'min:1'],
            'content_optimization_items.*.tier_id'                           => ['required', 'string', 'exists:content_optimization_tiers,id'],
            'content_optimization_items.*.quantity'                           => ['required', 'integer', 'min:1'],
            'content_optimization_items.*.unit_price'                        => ['required', 'numeric', 'min:0'],
            'content_optimization_items.*.intake_rows'                       => ['required', 'array', 'min:1'],
            'content_optimization_items.*.intake_rows.*.primary_keyword'     => ['required', 'string', 'max:500'],
            'content_optimization_items.*.intake_rows.*.secondary_keywords'  => ['nullable', 'string', 'max:1000'],
            'content_optimization_items.*.intake_rows.*.content_page_url'    => ['required', 'url', 'max:2083'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('content_optimization_items', []) as $index => $item) {
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
                        "content_optimization_items.{$index}.tier_id",
                        'The selected tier is not available.'
                    );
                    continue;
                }

                if ($tier->max_quantity !== null && $quantity > $tier->max_quantity) {
                    $validator->errors()->add(
                        "content_optimization_items.{$index}.quantity",
                        "Quantity exceeds the maximum allowed ({$tier->max_quantity}) for this tier."
                    );
                }

                $intake_rows = $item['intake_rows'] ?? [];
                if (count($intake_rows) !== $quantity) {
                    $validator->errors()->add(
                        "content_optimization_items.{$index}.intake_rows",
                        "The number of intake rows (" . count($intake_rows) . ") must equal the quantity ({$quantity})."
                    );
                }
            }
        });
    }
}
