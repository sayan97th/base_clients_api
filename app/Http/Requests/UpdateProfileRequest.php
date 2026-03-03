<?php

namespace App\Http\Requests;

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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'business_email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'timezone' => ['required', 'string', 'max:50', 'timezone:all'],
            'interested_in' => ['nullable', 'string', Rule::in(['', 'links', 'content', 'both'])],
            'notification_channel' => ['required', 'string', Rule::in(['email_and_portal', 'portal_only'])],
            'team_order_updates' => ['required', 'boolean'],
            'push_notifications_enabled' => ['required', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'state_province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'company' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
        ];
    }
}
