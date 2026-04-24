<?php

namespace App\Http\Requests\Admin\NewContent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNewContentTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tierId = $this->route('id');

        return [
            'id'              => ['sometimes', 'string', 'max:100', "unique:new_content_tiers,id,{$tierId}", 'regex:/^[a-z0-9_]+$/'],
            'label'           => ['sometimes', 'string', 'max:255'],
            'turnaround_time' => ['sometimes', 'string', 'max:255'],
            'price'           => ['sometimes', 'numeric', 'gt:0'],
            'is_active'       => ['sometimes', 'boolean'],
            'is_most_popular' => ['sometimes', 'boolean'],
            'max_quantity'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_hidden'       => ['sometimes', 'boolean'],
            'sort_order'      => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
