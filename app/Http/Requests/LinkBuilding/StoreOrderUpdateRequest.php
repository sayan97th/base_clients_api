<?php

namespace App\Http\Requests\LinkBuilding;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:255'],
            'message'       => ['required', 'string'],
            'status_change' => ['required', 'nullable', 'string', 'in:pending,processing,completed,cancelled'],
            'send_email'    => ['required', 'boolean'],
        ];
    }
}
