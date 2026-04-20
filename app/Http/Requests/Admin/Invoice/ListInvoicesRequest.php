<?php

namespace App\Http\Requests\Admin\Invoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page'           => ['sometimes', 'integer', 'min:1'],
            'per_page'       => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'         => ['sometimes', 'nullable', 'string', Rule::in(['unpaid', 'paid', 'overdue', 'refund', 'void'])],
            'sort_field'     => ['sometimes', 'nullable', 'string', Rule::in(['date_issued', 'total_amount', 'status', 'invoice_number', 'customer'])],
            'sort_direction' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
            'date_from'      => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to'        => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'         => 'The status must be one of: unpaid, paid, overdue, refund, void.',
            'sort_field.in'     => 'The sort_field must be one of: date_issued, total_amount, status, invoice_number, customer.',
            'sort_direction.in' => 'The sort_direction must be one of: asc, desc.',
            'date_from.date_format' => 'The date_from must be in YYYY-MM-DD format.',
            'date_to.date_format'   => 'The date_to must be in YYYY-MM-DD format.',
        ];
    }
}
