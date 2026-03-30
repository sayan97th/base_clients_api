<?php

namespace App\Http\Requests\Admin\News;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNewsPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'           => 'sometimes|in:promo,news,blog_post,tip',
            'status'         => 'sometimes|in:draft,active,archived',
            'title'          => 'sometimes|string|max:255',
            'subtitle'       => 'nullable|string|max:255',
            'description'    => 'nullable|string',
            'discount_value' => 'nullable|string|max:50',
            'discount_label' => 'nullable|string|max:255',
            'coupon_id'      => 'nullable|string|exists:coupons,id',
            'starts_at'      => 'nullable|date_format:Y-m-d',
            'ends_at'        => 'nullable|date_format:Y-m-d',
            'image_url'      => 'nullable|string|max:2048',
            'image_path'     => 'nullable|string|max:2048',
            'thumbnail_url'  => 'nullable|string|max:2048',
            'thumbnail_path' => 'nullable|string|max:2048',
            'cta_text'       => 'nullable|string|max:255',
            'cta_url'        => 'nullable|string|max:2048',
            'tags'           => 'nullable|array',
            'tags.*'         => 'string|max:100',
            'is_featured'    => 'nullable|boolean',
            'sort_order'     => 'nullable|integer|min:0',
        ];
    }
}
