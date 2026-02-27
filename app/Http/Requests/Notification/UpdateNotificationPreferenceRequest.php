<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notification_channel' => ['sometimes', 'required', Rule::in(['email_and_portal', 'portal_only'])],
            'team_order_updates' => ['sometimes', 'boolean'],
            'push_notifications_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
