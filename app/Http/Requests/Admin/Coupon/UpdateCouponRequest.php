<?php

namespace App\Http\Requests\Admin\Coupon;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCouponRequest extends FormRequest
{
    private ?Coupon $current_coupon = null;

    private bool $current_coupon_loaded = false;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    public function rules(): array
    {
        $coupon_id     = $this->route('id');
        $discount_type = $this->input('discount_type', $this->currentCoupon()?->discount_type);
        $applies_to    = $this->effectiveAppliesTo();

        return [
            'code' => [
                'sometimes',
                'string',
                'max:32',
                'alpha_num',
                Rule::unique('coupons', 'code')->ignore($coupon_id)->whereNull('deleted_at'),
            ],
            'name'                    => 'sometimes|string|max:100',
            'description'             => 'sometimes|nullable|string|max:500',
            'discount_type'           => ['sometimes', Rule::in(Coupon::DISCOUNT_TYPES)],
            'discount_value'          => [
                'sometimes',
                'numeric',
                'gt:0',
                function ($attribute, $value, $fail) use ($discount_type) {
                    if ($discount_type === Coupon::DISCOUNT_TYPE_PERCENTAGE && $value > 100) {
                        $fail('The discount value must not be greater than 100 when discount type is percentage.');
                    }
                    if ($discount_type === Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD && $value > 100) {
                        $fail('The reward percentage must not be greater than 100.');
                    }
                },
            ],
            'applies_to'              => ['sometimes', Rule::in(Coupon::APPLIES_TO_OPTIONS)],
            'product_types'           => 'sometimes|nullable|array',
            'product_types.*'         => 'string|in:link_building,new_content,content_optimization,content_brief',
            'dr_tier_ids'             => $this->drTierRules($applies_to),
            'dr_tier_ids.*'           => [
                'string',
                Rule::exists('dr_tiers', 'id'),
            ],
            'minimum_purchase_amount' => $this->minimumPurchaseRules($applies_to),
            'starts_at'               => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    if ($value && $this->expires_at && strtotime($value) > strtotime($this->expires_at)) {
                        $fail('The starts at date must be on or before the expires at date.');
                    }
                },
            ],
            'expires_at'              => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    if ($value && $this->isNewExpiryDateInThePast($value)) {
                        $fail('The expires at field must be a date after or equal to today.');
                    }
                },
            ],
            'usage_limit'             => 'sometimes|nullable|integer|min:1',
            'usage_per_user'          => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) {
                    if ($value && $this->usage_limit && $value > $this->usage_limit) {
                        $fail('The usage per user must not exceed the usage limit.');
                    }
                },
            ],
            'is_active'               => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'dr_tier_ids.required'             => 'At least one DR tier must be selected when the discount scope is DR Tiers.',
            'dr_tier_ids.min'                  => 'At least one DR tier must be selected when the discount scope is DR Tiers.',
            'dr_tier_ids.*.exists'             => 'One or more selected DR tiers are invalid.',
            'minimum_purchase_amount.required' => 'A minimum purchase amount is required when the discount scope is Minimum Purchase.',
        ];
    }

    /**
     * The scope the coupon will have after this update: the one sent in the
     * request, or the stored one when the request does not change it.
     */
    public function effectiveAppliesTo(): ?string
    {
        return $this->input('applies_to', $this->currentCoupon()?->applies_to);
    }

    /**
     * DR tiers only matter for the DR Tiers scope. Any other scope accepts an
     * empty list (or no list at all), and the controller clears stale tiers.
     */
    private function drTierRules(?string $applies_to): array
    {
        if ($applies_to !== Coupon::APPLIES_TO_DR_TIERS) {
            return ['sometimes', 'nullable', 'array'];
        }

        // Switching to the DR Tiers scope in this request must come with tiers.
        $presence_rule = $this->has('applies_to') ? 'required' : 'sometimes';

        return [$presence_rule, 'array', 'min:1'];
    }

    private function minimumPurchaseRules(?string $applies_to): array
    {
        if ($applies_to !== Coupon::APPLIES_TO_MINIMUM_PURCHASE) {
            return ['sometimes', 'nullable', 'numeric', 'gt:0'];
        }

        $presence_rule = $this->has('applies_to') ? 'required' : 'sometimes';

        return [$presence_rule, 'numeric', 'gt:0'];
    }

    /**
     * A coupon that already expired keeps its stored date when other fields are
     * edited, so only a new date is required to be today or later.
     */
    private function isNewExpiryDateInThePast(string $value): bool
    {
        if ($this->currentCoupon()?->endDate() === $value) {
            return false;
        }

        return $value < Coupon::promotionToday();
    }

    private function currentCoupon(): ?Coupon
    {
        if (! $this->current_coupon_loaded) {
            $this->current_coupon        = Coupon::find($this->route('id'));
            $this->current_coupon_loaded = true;
        }

        return $this->current_coupon;
    }
}
