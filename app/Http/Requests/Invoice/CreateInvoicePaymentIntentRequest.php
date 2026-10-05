<?php

namespace App\Http\Requests\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class CreateInvoicePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Public share-link flow only (no Authorization header).
            'token'              => ['sometimes', 'nullable', 'string', 'max:255'],
            // Authenticated flow only: pay with a saved card from the client's account.
            'payment_profile_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Authenticated flow only: save the new card for future payments.
            'save_card'          => ['sometimes', 'boolean'],
        ];
    }
}
