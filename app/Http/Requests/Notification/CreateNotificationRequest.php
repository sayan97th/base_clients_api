<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class CreateNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'         => ['required', 'string', 'in:payment,post,system,order'],
            'message'      => ['required', 'string'],
            'preview_text' => ['sometimes', 'nullable', 'string'],
            'link'         => ['sometimes', 'nullable', 'string'],
        ];
    }
}
