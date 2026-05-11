<?php

namespace App\Http\Requests\PurchaseGroup;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_group_id'         => ['required', 'uuid'],
            'order_title'               => ['nullable', 'string', 'max:255'],
            'total_amount'              => ['required', 'numeric', 'min:0'],
            'created_at'                => ['required', 'date'],
            'orders'                    => ['required', 'array', 'min:1'],
            'orders.*.order_id'         => ['required', 'uuid'],
            'orders.*.product_type'     => ['required', 'string', 'in:link_building,new_content,content_optimization,content_brief'],
            'orders.*.total_amount'     => ['required', 'numeric', 'min:0'],
            'payment_status'            => ['required', 'string', 'in:paid,pending'],
            'invoice_unique_id'         => ['nullable', 'string', 'max:50'],
        ];
    }
}
