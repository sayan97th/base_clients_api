<?php

namespace App\Http\Requests\SmeContent;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollaborationOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selected_tiers'           => ['required', 'array', 'min:1'],
            'selected_tiers.*'         => ['required', 'integer', 'min:1'],
            'billing_address'          => ['required', 'array'],
            'billing_address.address'  => ['required', 'string', 'max:255'],
            'billing_address.city'     => ['required', 'string', 'max:100'],
            'billing_address.state'    => ['required', 'string', 'max:100'],
            'billing_address.country'  => ['required', 'string', 'max:100'],
            'billing_address.postal_code' => ['required', 'string', 'max:20'],
            'billing_address.company'  => ['nullable', 'string', 'max:255'],
            'email'                    => ['required', 'string', 'email', 'max:255'],
            'payment_intent_id'        => ['required', 'string', 'max:255'],
        ];
    }
}
