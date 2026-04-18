<?php

namespace App\Http\Requests\Admin\SeoPackages;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminSeoPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name'                   => ['sometimes', 'string', 'max:255'],
            'slug'                   => ['sometimes', 'string', "unique:seo_packages,slug,{$id}", 'regex:/^[a-z0-9-]+$/'],
            'price_per_month'        => ['sometimes', 'numeric', 'min:0.01'],
            'best_for'               => ['sometimes', 'nullable', 'string', 'max:500'],
            'tagline'                => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_most_popular'        => ['sometimes', 'boolean'],
            'is_active'              => ['sometimes', 'boolean'],
            'sort_order'             => ['sometimes', 'integer', 'min:0'],
            'features'               => ['sometimes', 'array'],
            'features.*.category'    => ['required_with:features', 'string', 'max:255'],
            'features.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
