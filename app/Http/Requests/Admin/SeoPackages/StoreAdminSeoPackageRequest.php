<?php

namespace App\Http\Requests\Admin\SeoPackages;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdminSeoPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => ['required', 'string', 'max:255'],
            'slug'                   => ['required', 'string', 'unique:seo_packages,slug', 'regex:/^[a-z0-9-]+$/'],
            'price_per_month'        => ['required', 'numeric', 'min:0.01'],
            'best_for'               => ['nullable', 'string', 'max:500'],
            'tagline'                => ['nullable', 'string', 'max:500'],
            'is_most_popular'        => ['required', 'boolean'],
            'is_active'              => ['required', 'boolean'],
            'sort_order'             => ['required', 'integer', 'min:0'],
            'features'               => ['nullable', 'array'],
            'features.*.category'    => ['required_with:features', 'string', 'max:255'],
            'features.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
