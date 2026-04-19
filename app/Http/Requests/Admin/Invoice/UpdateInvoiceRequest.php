<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_due'                        => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'line_items'                      => ['sometimes', 'array', 'min:1'],
            'line_items.*.item_name'          => ['required_with:line_items', 'string', 'max:255'],
            'line_items.*.description'        => ['sometimes', 'nullable', 'string'],
            'line_items.*.price'              => ['required_with:line_items', 'numeric', 'min:0'],
            'line_items.*.quantity'           => ['required_with:line_items', 'integer', 'min:1'],
            'line_items.*.discount_percent'   => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'notes'                           => ['sometimes', 'nullable', 'string'],
            'send_update_notification'        => ['sometimes', 'boolean'],
        ];
    }
}
