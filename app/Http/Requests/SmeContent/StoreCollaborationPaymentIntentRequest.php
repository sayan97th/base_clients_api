<?php

namespace App\Http\Requests\SmeContent;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollaborationPaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'           => ['required', 'integer', 'min:1'],
            'selected_tiers'   => ['required', 'array', 'min:1'],
            'selected_tiers.*' => ['required', 'integer', 'min:1'],
            'email'            => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
