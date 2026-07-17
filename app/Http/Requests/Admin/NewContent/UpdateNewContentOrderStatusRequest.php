<?php

namespace App\Http\Requests\Admin\NewContent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNewContentOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['required', 'string', 'in:pending,processing,completed,cancelled,pending_details'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
