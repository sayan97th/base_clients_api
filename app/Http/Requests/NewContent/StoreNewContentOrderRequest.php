<?php

namespace App\Http\Requests\NewContent;

use App\Models\NewContentTier;
use Illuminate\Foundation\Http\FormRequest;

class StoreNewContentOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_notes'                        => ['nullable', 'string'],
            'total_amount'                       => ['required', 'numeric', 'gt:0'],
            'coupon_ids'                         => ['nullable', 'array'],
            'coupon_ids.*'                       => ['string', 'exists:coupons,id'],
            'items'                              => ['required', 'array', 'min:1'],
            'items.*.tier_id'                    => ['required', 'string', 'exists:new_content_tiers,id'],
            'items.*.quantity'                   => ['required', 'integer', 'min:1'],
            'items.*.unit_price'                 => ['required', 'numeric', 'min:0'],
            'items.*.intake_rows'                => ['nullable', 'array'],
            'items.*.intake_rows.*.keyword_phrase'    => ['required', 'string', 'max:500'],
            'items.*.intake_rows.*.type_of_content'   => ['required', 'string', 'max:255'],
            'items.*.intake_rows.*.notes'             => ['nullable', 'string', 'max:1000'],
            'billing'                            => ['required', 'array'],
            'billing.company'                    => ['nullable', 'string', 'max:255'],
            'billing.address'                    => ['nullable', 'string', 'max:255'],
            'billing.city'                       => ['nullable', 'string', 'max:100'],
            'billing.state'                      => ['nullable', 'string', 'max:100'],
            'billing.country'                    => ['nullable', 'string', 'max:100'],
            'billing.postal_code'                => ['nullable', 'string', 'max:20'],
            'payment'                            => ['required', 'array'],
            'payment.payment_method_id'          => ['required', 'string'],
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

                $tier = NewContentTier::where('id', $tier_id)
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
