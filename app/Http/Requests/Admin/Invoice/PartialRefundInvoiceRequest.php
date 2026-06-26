<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class PartialRefundInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'refund_amount'            => ['required', 'numeric', 'gt:0'],
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
        ];
    }
}
