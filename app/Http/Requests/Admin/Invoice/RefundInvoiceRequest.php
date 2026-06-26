<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class RefundInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_intent_id'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'send_client_notification' => ['sometimes', 'boolean'],
        ];
    }
}
