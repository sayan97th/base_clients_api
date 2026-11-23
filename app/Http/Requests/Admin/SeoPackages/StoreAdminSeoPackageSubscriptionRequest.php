<?php

namespace App\Http\Requests\Admin\SeoPackages;

use App\Models\PaymentProfile;
use App\Models\SeoPackageSubscription;
use App\Support\BillingCalendar;
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
            // Compared against "today" in the admin's own timezone, see after().
            'first_billing_date'       => ['nullable', 'date'],
            'timezone'                 => ['nullable', 'string', 'max:64'],
            'ends_at'                  => ['nullable', 'date', 'after:starts_at'],
            'auto_renew'               => ['sometimes', 'boolean'],
            'payment_profile_id'       => [
                'nullable',
                'string',
                Rule::exists('payment_profiles', 'id')->where('user_id', (int) $this->input('user_id')),
            ],
            'reminder_days_before'     => ['nullable', 'integer', 'min:0', 'max:' . SeoPackageSubscription::MAX_REMINDER_DAYS],
            'charge_now'               => ['sometimes', 'boolean'],
            // The client already paid the first period another way (bank
            // transfer, check, a manual invoice). It is recorded as paid and
            // never charged again.
            'first_period_paid_outside' => ['sometimes', 'boolean'],
            'outside_payment_reference' => ['nullable', 'string', 'max:120'],
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
                $timezone           = $this->input('timezone');

                // Today is allowed: a subscription can start and bill the same day.
                if ($first_billing_date && BillingCalendar::isPast($first_billing_date, $timezone)) {
                    $validator->errors()->add('first_billing_date', 'The first billing date cannot be in the past.');
                }

                if ($this->boolean('charge_now') && $first_billing_date && ! BillingCalendar::isToday($first_billing_date, $timezone)) {
                    $validator->errors()->add(
                        'charge_now',
                        'The first payment can only be charged now when the first billing date is today.'
                    );
                }

                if ($this->boolean('charge_now') && $this->boolean('first_period_paid_outside')) {
                    $validator->errors()->add(
                        'first_period_paid_outside',
                        'The first payment is already marked as paid, so it cannot be charged now as well.'
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
            'outside_payment_reference.max'  => 'The note can be at most 120 characters.',
            'payment_profile_id.exists'      => 'The selected card does not belong to this client.',
            'reminder_days_before.integer'   => 'The reminder must be a whole number of days.',
            'reminder_days_before.max'       => 'The reminder can be sent at most ' . SeoPackageSubscription::MAX_REMINDER_DAYS . ' days before the charge.',
        ];
    }
}
