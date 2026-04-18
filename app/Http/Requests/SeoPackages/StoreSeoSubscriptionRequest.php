<?php

namespace App\Http\Requests\SeoPackages;

use App\Models\SeoPackage;
use Illuminate\Foundation\Http\FormRequest;

class StoreSeoSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'package_id'                => ['required', 'string', 'exists:seo_packages,id'],
            'total_amount'              => ['required', 'numeric', 'min:0'],
            'billing'                   => ['required', 'array'],
            'billing.company'           => ['nullable', 'string', 'max:255'],
            'billing.address'           => ['nullable', 'string', 'max:255'],
            'billing.city'              => ['nullable', 'string', 'max:100'],
            'billing.state'             => ['nullable', 'string', 'max:100'],
            'billing.country'           => ['nullable', 'string', 'max:100'],
            'billing.postal_code'       => ['nullable', 'string', 'max:20'],
            'payment'                   => ['required', 'array'],
            'payment.payment_method_id' => ['required', 'string'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $package_id = $this->input('package_id');

            if ($package_id && !$validator->errors()->has('package_id')) {
                $package = SeoPackage::find($package_id);

                if (!$package || !$package->is_active) {
                    $validator->errors()->add('package_id', 'The selected package is not available.');
                }
            }
        });
    }
}
