<?php

namespace App\Http\Requests\LinkBuilding;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['required', 'string', 'in:pending,processing,completed,cancelled'],
            'notify_user' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
