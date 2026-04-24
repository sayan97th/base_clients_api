<?php

namespace App\Http\Requests\Admin\NewContent;

use Illuminate\Foundation\Http\FormRequest;

class StoreNewContentTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'              => ['required', 'string', 'max:100', 'unique:new_content_tiers,id', 'regex:/^[a-z0-9_]+$/'],
            'label'           => ['required', 'string', 'max:255'],
            'turnaround_time' => ['required', 'string', 'max:255'],
            'price'           => ['required', 'numeric', 'gt:0'],
            'is_active'       => ['required', 'boolean'],
            'is_most_popular' => ['required', 'boolean'],
            'max_quantity'    => ['required', 'nullable', 'integer', 'min:1'],
            'is_hidden'       => ['required', 'boolean'],
            'sort_order'      => ['required', 'integer', 'min:0'],
        ];
    }
}
