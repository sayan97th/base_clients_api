<?php

namespace App\Http\Requests\Admin\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAdminNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type'     => ['sometimes', Rule::in(['order', 'payment', 'system', 'user_registration', 'order_comment', 'invoice', 'post', 'ticket'])],
            'is_read'  => ['sometimes', 'boolean'],
        ];
    }
}
