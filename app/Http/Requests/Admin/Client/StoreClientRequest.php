<?php

namespace App\Http\Requests\Admin\Client;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'         => ['required', 'string', 'max:100'],
            'last_name'          => ['required', 'string', 'max:100'],
            'email'              => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'           => ['nullable', 'string', 'min:8'],
            'send_welcome_email' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required'         => 'The first name field is required.',
            'first_name.max'              => 'The first name may not be greater than 100 characters.',
            'last_name.required'          => 'The last name field is required.',
            'last_name.max'               => 'The last name may not be greater than 100 characters.',
            'email.required'              => 'The email field is required.',
            'email.email'                 => 'The email must be a valid email address.',
            'email.unique'                => 'The email has already been taken.',
            'password.min'                => 'The password must be at least 8 characters.',
            'send_welcome_email.required' => 'The send welcome email field is required.',
            'send_welcome_email.boolean'  => 'The send welcome email field must be true or false.',
        ];
    }
}
