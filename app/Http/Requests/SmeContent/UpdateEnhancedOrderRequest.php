<?php

namespace App\Http\Requests\SmeContent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnhancedOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['pending', 'paid', 'processing', 'completed', 'cancelled'])],
        ];
    }
}
