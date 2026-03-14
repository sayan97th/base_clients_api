<?php

namespace App\Http\Requests;

use App\Models\Invitation;
use Illuminate\Foundation\Http\FormRequest;

class SendInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'role'  => ['required', 'string', 'in:' . implode(',', Invitation::ALLOWED_ROLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'The role must be one of: ' . implode(', ', Invitation::ALLOWED_ROLES) . '.',
        ];
    }
}
