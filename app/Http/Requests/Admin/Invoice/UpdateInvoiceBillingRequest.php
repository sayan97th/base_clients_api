<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'address_line_1'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'state'               => ['sometimes', 'nullable', 'string', 'max:100'],
            'country'             => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
