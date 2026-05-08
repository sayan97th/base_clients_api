<?php

namespace App\Http\Requests\EmailNotification;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailNotificationSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notify_all_admins'   => ['required', 'boolean'],
            'enabled_user_ids'    => ['required', 'array'],
            'enabled_user_ids.*'  => ['integer', 'exists:users,id'],
            'custom_emails'       => ['required', 'array'],
            'custom_emails.*'     => ['string', 'email', 'max:255'],
        ];
    }
}
