<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invitation_token'      => ['required', 'string'],
            'first_name'            => ['required', 'string', 'max:255'],
            'last_name'             => ['required', 'string', 'max:255'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
