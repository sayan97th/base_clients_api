<?php

namespace App\Http\Requests\Client\Invoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method'    => ['required', 'string', Rule::in(['account_balance', 'credit_card'])],
            'payment_intent_id' => [
                Rule::requiredIf(fn () => $this->input('payment_method') === 'credit_card'),
                'nullable',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method.required'    => 'The payment method field is required.',
            'payment_method.in'          => 'The payment method must be account_balance or credit_card.',
            'payment_intent_id.required' => 'A Stripe PaymentIntent ID is required for credit card payments.',
        ];
    }
}
