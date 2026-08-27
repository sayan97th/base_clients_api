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
            'type'          => ['required', 'string', 'in:payment,post,system,order,order_comment,user_registration,invoice,ticket'],
            'message'       => ['required', 'string'],
            'preview_text'  => ['sometimes', 'nullable', 'string'],
            'link'          => ['sometimes', 'nullable', 'string'],
            'resource_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'resource_id'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'metadata'      => ['sometimes', 'nullable', 'array'],
        ];
    }
}
