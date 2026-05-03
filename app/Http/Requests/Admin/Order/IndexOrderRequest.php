<?php

namespace App\Http\Requests\Admin\Order;

use Illuminate\Foundation\Http\FormRequest;

class IndexOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page'           => 'nullable|integer|min:1',
            'per_page'       => 'nullable|integer|min:1|max:100',
            'search'         => 'nullable|string|max:255',
            'status'         => 'nullable|string|in:pending,processing,completed,cancelled',
            'sort_field'     => 'nullable|string|in:created_at,total_amount,status,order_title,customer',
            'sort_direction' => 'nullable|string|in:asc,desc',
            'date_from'      => 'nullable|date_format:Y-m-d|before_or_equal:date_to',
            'date_to'        => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'product_type'   => 'nullable|string|in:link_building,new_content,content_optimization,content_brief',
        ];
    }
}
