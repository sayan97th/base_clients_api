<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class PayInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_intent_id' => ['required', 'string'],
            'token'             => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_intent_id.required' => 'The payment intent id field is required.',
            'payment_intent_id.string'   => 'The payment intent id field must be a string.',
            'token.required'             => 'The token field is required.',
            'token.string'               => 'The token field must be a string.',
        ];
    }
}
