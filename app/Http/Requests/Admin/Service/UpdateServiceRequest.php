<?php

namespace App\Http\Requests\Admin\Service;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['sometimes', 'string', 'max:255'],
            'description'   => ['sometimes', 'string'],
            'category'      => ['sometimes', Rule::in(Service::CATEGORIES)],
            'pricing_model' => ['sometimes', Rule::in(Service::PRICING_MODELS)],
            'base_price'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active'     => ['sometimes', 'boolean'],
            'is_featured'   => ['sometimes', 'boolean'],
        ];
    }
}
