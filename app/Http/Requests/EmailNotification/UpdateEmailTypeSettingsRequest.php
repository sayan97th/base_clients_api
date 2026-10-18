<?php

namespace App\Http\Requests\EmailNotification;

use App\Support\Email\EmailTypeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmailTypeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email_types'              => ['required', 'array'],
            'email_types.*.key'        => ['required', 'string', 'distinct', Rule::in(EmailTypeCatalog::configurableKeys())],
            'email_types.*.is_enabled' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email_types.*.key.in' => 'One of the email types does not exist or cannot be turned off.',
        ];
    }
}
