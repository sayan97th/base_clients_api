<?php

namespace App\Http\Requests\Admin\SeoPackages;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminSeoPackageSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'    => ['required', 'integer', 'exists:users,id'],
            'package_id' => ['required', 'string', Rule::exists('seo_packages', 'id')->where('is_active', true)],
            'starts_at'  => ['required', 'date'],
            'ends_at'    => ['nullable', 'date', 'after:starts_at'],
            'notes'      => ['nullable', 'string', 'max:1000'],
        ];
    }
}
