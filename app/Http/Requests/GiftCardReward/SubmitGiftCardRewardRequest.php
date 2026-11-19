<?php

namespace App\Http\Requests\GiftCardReward;

use App\Models\GiftCardRewardClaim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitGiftCardRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('recipient_email')) {
            $this->merge(['recipient_email' => strtolower(trim((string) $this->recipient_email))]);
        }
    }

    public function rules(): array
    {
        return [
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'gift_card_brand' => ['required', 'string', Rule::in(GiftCardRewardClaim::brandKeys())],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_email.required' => 'Please enter the email address where the gift card should be sent.',
            'recipient_email.email'    => 'Please enter a valid email address.',
            'gift_card_brand.required' => 'Please choose a gift card.',
            'gift_card_brand.in'       => 'Please choose Amazon, Visa or Target.',
        ];
    }
}
