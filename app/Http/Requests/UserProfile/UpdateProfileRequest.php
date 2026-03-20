<?php

namespace App\Http\Requests\UserProfile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('interested_in')) {
            $this->merge([
                'interested_in' => strtolower($this->input('interested_in', '')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'first_name'                 => ['required', 'string', 'max:100'],
            'last_name'                  => ['required', 'string', 'max:100'],
            'business_email'             => ['required', 'email', 'max:255'],
            'phone'                      => ['nullable', 'string'],
            'timezone'                   => ['nullable', 'string'],
            'interested_in'              => ['nullable', 'string', Rule::in(['', 'links', 'content', 'both'])],
            'notification_channel'       => ['required', 'string', Rule::in(['email_and_portal', 'email', 'portal'])],
            'team_order_updates'         => ['required', 'boolean'],
            'push_notifications_enabled' => ['required', 'boolean'],
            'address'                    => ['nullable', 'string', 'max:255'],
            'city'                       => ['nullable', 'string', 'max:100'],
            'country'                    => ['nullable', 'string', 'max:10'],
            'state_province'             => ['nullable', 'string', 'max:100'],
            'postal_code'                => ['nullable', 'string', 'max:20'],
            'company'                    => ['nullable', 'string', 'max:255'],
        ];
    }
}
