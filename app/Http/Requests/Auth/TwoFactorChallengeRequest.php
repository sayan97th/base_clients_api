<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'two_factor_token' => ['required', 'string'],
            'code'             => ['required', 'string', 'digits:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'two_factor_token.required' => 'The verification session token is required.',
            'code.required'             => 'The verification code is required.',
            'code.digits'               => 'The verification code must be exactly 6 digits.',
        ];
    }
}
