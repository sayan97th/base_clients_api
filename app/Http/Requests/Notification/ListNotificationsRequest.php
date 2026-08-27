<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(['payment', 'post', 'system', 'order', 'order_comment', 'user_registration', 'invoice', 'ticket'])],
            'is_read' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
