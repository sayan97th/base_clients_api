<?php

namespace App\Http\Requests\Admin\SeoPackages;

use Illuminate\Foundation\Http\FormRequest;

class CancelAdminSeoPackageSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes'              => ['nullable', 'string', 'max:1000'],
            // Void the unpaid invoices of periods that were never collected.
            'void_open_invoices' => ['sometimes', 'boolean'],
        ];
    }
}
