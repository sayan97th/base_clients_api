<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the incoming due date to the Y-m-d format the validator
     * expects. The frontend may send an ISO 8601 string (e.g.
     * "2026-07-24T00:00:00+00:00") when an invoice is loaded for editing;
     * without this step the date_format rule would reject an otherwise
     * valid date and the whole update (line items, discounts, total) would
     * silently fail to persist.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('date_due')) {
            try {
                $this->merge([
                    'date_due' => Carbon::parse($this->input('date_due'))->format('Y-m-d'),
                ]);
            } catch (\Throwable $e) {
                // Leave the original value untouched; the validator will
                // surface a clear "invalid format" message.
            }
        }
    }

    public function rules(): array
    {
        return [
            'user_id'                         => ['sometimes', 'integer', 'exists:users,id'],
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

    public function messages(): array
    {
        return [
            'date_due.date_format'                  => 'The date due must be in YYYY-MM-DD format.',
            'line_items.min'                        => 'At least one line item is required.',
            'line_items.*.item_name.required_with'  => 'The item name field is required.',
            'line_items.*.item_name.max'            => 'The item name may not be greater than 255 characters.',
            'line_items.*.price.required_with'      => 'The price field is required.',
            'line_items.*.price.min'                => 'The price must be at least 0.',
            'line_items.*.quantity.required_with'   => 'The quantity field is required.',
            'line_items.*.quantity.min'             => 'The quantity must be at least 1.',
            'line_items.*.discount_percent.between' => 'The discount percent must be between 0 and 100.',
        ];
    }
}
