<?php

namespace App\Http\Requests\Admin\NewContent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNewContentTierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'                 => ['sometimes', 'string', 'max:255'],
            'price_per_month'      => ['sometimes', 'numeric', 'min:0.01'],
            'total_placements'     => ['sometimes', 'integer', 'min:1'],
            'exclusive_placements' => ['sometimes', 'integer', 'min:0'],
            'core_placements'      => ['sometimes', 'integer', 'min:0'],
            'support_placements'   => ['sometimes', 'integer', 'min:0'],
            'best_for'             => ['sometimes', 'nullable', 'string'],
            'tagline'              => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_most_popular'      => ['sometimes', 'boolean'],
            'is_active'            => ['sometimes', 'boolean'],
            'sort_order'           => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
