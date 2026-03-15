<?php

namespace App\Http\Requests\Admin\Service;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'description'   => ['required', 'string'],
            'category'      => ['required', Rule::in(Service::CATEGORIES)],
            'pricing_model' => ['required', Rule::in(Service::PRICING_MODELS)],
            'base_price'    => [
                Rule::requiredIf(fn () => !in_array($this->pricing_model, ['tiered', 'custom'])),
                'nullable',
                'numeric',
                'min:0',
            ],
            'is_active'     => ['required', 'boolean'],
            'is_featured'   => ['required', 'boolean'],
        ];
    }
}
