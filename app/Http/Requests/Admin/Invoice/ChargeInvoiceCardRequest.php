<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class ChargeInvoiceCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_profile_id' => ['required', 'string', 'max:64'],
            'confirmation'       => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_profile_id.required' => 'Please select a card to charge.',
            'confirmation.accepted'       => 'Please confirm the charge.',
        ];
    }
}
