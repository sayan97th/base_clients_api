<?php

namespace App\Http\Requests\UserProfile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatchProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('interested_in')) {
            $this->merge([
                'interested_in' => strtolower(trim($this->input('interested_in', ''))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'first_name'                 => ['sometimes', 'required', 'string', 'max:100'],
            'last_name'                  => ['sometimes', 'required', 'string', 'max:100'],
            'business_email'             => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'                      => ['sometimes', 'nullable', 'string'],
            'timezone'                   => ['sometimes', 'nullable', 'string'],
            'interested_in'              => ['sometimes', 'nullable', 'string', Rule::in(['', 'links', 'content', 'both'])],
            'notification_channel'       => ['sometimes', 'required', 'string', Rule::in(['email_and_portal', 'email', 'portal'])],
            'team_order_updates'         => ['sometimes', 'required', 'boolean'],
            'push_notifications_enabled' => ['sometimes', 'required', 'boolean'],
            'address'                    => ['sometimes', 'nullable', 'string', 'max:255'],
            'city'                       => ['sometimes', 'nullable', 'string', 'max:100'],
            'country'                    => ['sometimes', 'nullable', 'string', 'max:10'],
            'state_province'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code'                => ['sometimes', 'nullable', 'string', 'max:20'],
            'company'                    => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
