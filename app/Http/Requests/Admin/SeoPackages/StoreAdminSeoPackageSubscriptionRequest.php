<?php

namespace App\Http\Requests\Admin\SeoPackages;

use App\Models\PaymentProfile;
use App\Models\SeoPackageSubscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdminSeoPackageSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'pricing_type' => $this->input('pricing_type', SeoPackageSubscription::PRICING_PACKAGE),
        ]);
    }

    public function rules(): array
    {
        return [
            'user_id'                  => ['required', 'integer', 'exists:users,id'],
            'pricing_type'             => ['required', Rule::in(SeoPackageSubscription::PRICING_TYPES)],
            'package_id'               => [
                Rule::requiredIf(fn () => $this->input('pricing_type') === SeoPackageSubscription::PRICING_PACKAGE),
                'nullable',
                'string',
                Rule::exists('seo_packages', 'id')->where('is_active', true),
            ],
            'custom_price'             => [
                Rule::requiredIf(fn () => $this->input('pricing_type') === SeoPackageSubscription::PRICING_CUSTOM),
                'nullable',
                'numeric',
                'min:0.5',
                'max:1000000',
            ],
            'custom_plan_name'         => ['nullable', 'string', 'max:120'],
            'invoice_item_label'       => ['nullable', 'string', 'max:120'],
            'invoice_item_description' => ['nullable', 'string', 'max:1000'],
            'starts_at'                => ['required', 'date'],
            'first_billing_date'       => ['nullable', 'date', 'after_or_equal:today'],
            'ends_at'                  => ['nullable', 'date', 'after:starts_at'],
            'auto_renew'               => ['sometimes', 'boolean'],
            'payment_profile_id'       => [
                'nullable',
                'string',
                Rule::exists('payment_profiles', 'id')->where('user_id', (int) $this->input('user_id')),
            ],
            'reminder_days_before'     => ['nullable', 'integer', 'min:0', 'max:14'],
            'charge_now'               => ['sometimes', 'boolean'],
            'notes'                    => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $wants_card = $this->boolean('auto_renew') || $this->boolean('charge_now');

                if ($wants_card && ! PaymentProfile::where('user_id', (int) $this->input('user_id'))->exists()) {
                    $validator->errors()->add(
                        'payment_profile_id',
                        'This client has no card on file. Ask them to add a card in Billing, or turn off auto renew and charge manually later.'
                    );
                }

                $first_billing_date = $this->input('first_billing_date');

                if ($this->boolean('charge_now') && $first_billing_date && ! now()->isSameDay($first_billing_date)) {
                    $validator->errors()->add(
                        'charge_now',
                        'The first payment can only be charged now when the first billing date is today.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'package_id.required'            => 'Select an SEO package, or switch to a custom price.',
            'custom_price.required'          => 'Enter the monthly amount to charge this client.',
            'custom_price.min'               => 'The monthly amount must be at least $0.50.',
            'first_billing_date.after_or_equal' => 'The first billing date cannot be in the past.',
            'payment_profile_id.exists'      => 'The selected card does not belong to this client.',
        ];
    }
}
