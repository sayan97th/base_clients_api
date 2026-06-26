<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class RefundInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Server-side guard rails for a full refund.
     *
     * A refund moves real money, so it must never be triggered by an accidental
     * or programmatic request. The client has to explicitly opt in by sending
     * `confirmation = true` (the admin's acknowledgement). Without it,
     * validation fails before any Stripe call or credit restoration runs.
     */
    public function rules(): array
    {
        return [
            'confirmation'      => ['required', 'accepted'],
            'payment_intent_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => 'Refund confirmation is required before this action can be processed.',
            'confirmation.accepted' => 'You must explicitly confirm the refund before it can be processed.',
        ];
    }
}
