<?php

namespace App\Http\Requests\EmailNotification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEmailInterceptSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'intercept_admin_emails'   => ['required', 'boolean'],
            'intercept_client_emails'  => ['required', 'boolean'],
            'recipient_emails'         => ['present', 'array'],
            'recipient_emails.*'       => ['string', 'email', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $any_interception_enabled = $this->boolean('intercept_admin_emails')
                || $this->boolean('intercept_client_emails');

            if ($any_interception_enabled && empty($this->input('recipient_emails'))) {
                $validator->errors()->add(
                    'recipient_emails',
                    'Add at least one recipient email before turning on interception.'
                );
            }
        });
    }
}
