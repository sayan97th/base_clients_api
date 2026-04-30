<?php

namespace App\Http\Requests\ScheduledCallAppointment;

use Illuminate\Foundation\Http\FormRequest;

class StoreScheduledCallAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_uri'   => ['required', 'string', 'url'],
            'invitee_uri' => ['required', 'string', 'url'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ];
    }
}
