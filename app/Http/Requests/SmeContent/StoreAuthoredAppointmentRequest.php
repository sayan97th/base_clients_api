<?php

namespace App\Http\Requests\SmeContent;

use Illuminate\Foundation\Http\FormRequest;

class StoreAuthoredAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_uri'        => ['required', 'string', 'url'],
            'invitee_uri'      => ['required', 'string', 'url'],
            'selected_tiers'   => ['required', 'array', 'min:1'],
            'selected_tiers.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
