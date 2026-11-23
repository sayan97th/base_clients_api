<?php

namespace App\Http\Requests\Admin\SeoPackages;

use Illuminate\Foundation\Http\FormRequest;

class ChargeAdminSeoPackageSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional one time override; defaults to the subscription's card.
            'payment_profile_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
