<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the incoming due date to the Y-m-d format the validator
     * expects, accepting ISO 8601 strings and other parseable date inputs.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('date_due')) {
            try {
                $this->merge([
                    'date_due' => Carbon::parse($this->input('date_due'))->format('Y-m-d'),
                ]);
            } catch (\Throwable $e) {
                // Leave the original value untouched so the validator can
                // report a clear "invalid format" message.
            }
        }
    }

    public function rules(): array
    {
        return [
            'user_id'                          => ['required', 'integer', 'exists:users,id'],
            'date_due'                         => ['required', 'date_format:Y-m-d'],
            'line_items'                       => ['required', 'array', 'min:1'],
            'line_items.*.item_name'           => ['required', 'string', 'max:255'],
            'line_items.*.description'         => ['sometimes', 'nullable', 'string'],
            'line_items.*.price'               => ['required', 'numeric', 'min:0'],
            'line_items.*.quantity'            => ['required', 'integer', 'min:1'],
            'line_items.*.discount_percent'    => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'notes'                            => ['sometimes', 'nullable', 'string'],
            'send_client_notification'         => ['required', 'boolean'],
            'send_admin_notification'          => ['required', 'boolean'],
            'currency_type'                    => ['sometimes', 'nullable', Rule::in(['usd', 'credits'])],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists'                        => 'The selected user does not exist.',
            'date_due.required'                     => 'The date due field is required.',
            'date_due.date_format'                  => 'The date due must be in YYYY-MM-DD format.',
            'line_items.required'                   => 'At least one line item is required.',
            'line_items.min'                        => 'At least one line item is required.',
            'line_items.*.item_name.required'       => 'The item name field is required.',
            'line_items.*.item_name.max'            => 'The item name may not be greater than 255 characters.',
            'line_items.*.price.required'           => 'The price field is required.',
            'line_items.*.price.min'                => 'The price must be at least 0.',
            'line_items.*.quantity.required'        => 'The quantity field is required.',
            'line_items.*.quantity.min'             => 'The quantity must be at least 1.',
            'line_items.*.discount_percent.min'     => 'The discount percent must be at least 0.',
            'line_items.*.discount_percent.max'     => 'The discount percent may not be greater than 100.',
            'currency_type.in'                      => 'The currency type must be usd or credits.',
        ];
    }
}
