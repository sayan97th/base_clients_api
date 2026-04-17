<?php

namespace App\Http\Requests\SmeAppointment;

use Illuminate\Foundation\Http\FormRequest;

class StoreSmeAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type'     => ['required', 'string', 'in:authored,collaboration,enhanced'],
            'event_uri'        => ['required', 'string', 'url'],
            'invitee_uri'      => ['required', 'string', 'url'],
            'selected_tiers'   => ['required', 'array', 'min:1'],
            'selected_tiers.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
