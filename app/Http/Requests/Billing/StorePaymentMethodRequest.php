<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stripe_payment_method_id' => 'required|string|starts_with:pm_',
            'set_as_default'           => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'stripe_payment_method_id.required'    => 'A Stripe PaymentMethod ID is required.',
            'stripe_payment_method_id.starts_with' => 'Invalid PaymentMethod ID format.',
        ];
    }
}
