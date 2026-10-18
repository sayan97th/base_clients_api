<?php

namespace App\Http\Requests\EmailNotification;

use App\Support\Email\EmailTypeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserEmailPreferenceRequest extends FormRequest
{
    /**
     * Super admins and admins manage everyone's preferences. Any other admin
     * side user (staff) can only manage their own.
     */
    public function authorize(): bool
    {
        $auth_user = $this->user();

        if (! $auth_user) {
            return false;
        }

        if ($auth_user->hasRole(['super_admin', 'admin'])) {
            return true;
        }

        return (int) $this->route('user_id') === $auth_user->id;
    }

    public function rules(): array
    {
        return [
            'mute_all_emails'        => ['required', 'boolean'],
            'disabled_email_types'   => ['present', 'array'],
            'disabled_email_types.*' => ['string', 'distinct', Rule::in(EmailTypeCatalog::configurableKeys())],
        ];
    }

    public function messages(): array
    {
        return [
            'disabled_email_types.*.in' => 'One of the email types does not exist or cannot be turned off.',
        ];
    }
}
