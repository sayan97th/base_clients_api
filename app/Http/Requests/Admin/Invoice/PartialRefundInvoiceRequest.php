<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class PartialRefundInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Server-side guard rails for a partial refund.
     *
     * Like a full refund, a partial refund moves real money and must be
     * explicitly confirmed. The client has to send `confirmation = true`
     * (the admin's acknowledgement) on top of a positive `refund_amount`.
     * Without both, validation fails before any Stripe call or credit
     * restoration runs.
     */
    public function rules(): array
    {
        return [
            'refund_amount'            => ['required', 'numeric', 'gt:0'],
            'confirmation'             => ['required', 'accepted'],
            'payment_intent_id'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'send_client_notification' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'refund_amount.required' => 'A refund amount is required.',
            'refund_amount.numeric'  => 'The refund amount must be a valid number.',
            'refund_amount.gt'       => 'The refund amount must be greater than zero.',
            'confirmation.required'  => 'Refund confirmation is required before this action can be processed.',
            'confirmation.accepted'  => 'You must explicitly confirm the refund before it can be processed.',
        ];
    }
}
